/**
 * SEOCart Gateway for Stripe: the card form in SEOCart's checkout.
 *
 * A payment island, as SEOCart's docs/payment-islands.md describes one: a classic script that
 * registers with the checkout's payment registry, `window.seocart.payments`, whether it runs
 * before or after the checkout's own module. When the shopper selects Stripe, it loads Stripe.js
 * from Stripe's own address and shows Stripe's Payment Element, card fields only, for the cart's
 * amount and currency. No PaymentIntent exists yet: the Element is created for the amount alone
 * (Stripe's deferred-intent mode), and the store creates the PaymentIntent when the order is
 * placed, for the amount it froze.
 *
 * - confirm() checks the card fields with Stripe (elements.submit()) and sends the store no
 *   payment data: the card details stay with Stripe.
 * - The store places the order and answers that the shopper must act, with the new
 *   PaymentIntent's client secret. action() confirms that PaymentIntent with the card typed
 *   (stripe.confirmPayment()); a bank's check (3-D Secure) opens in Stripe's own window on the
 *   page. It reports `completed` or `failed`, never more: the store's resume asks Stripe where
 *   the payment stands and decides.
 * - showError() shows a decline inside the form.
 * - unmount() destroys the Element; whatever it was doing is abandoned.
 *
 * The billing address is the checkout's, given to confirm() and action() as it is at each call,
 * and sent to Stripe with the card; the Element collects none.
 *
 * Hand-written and shipped as written (ES2020, no build, no dependency). It makes no request of
 * its own, to the store or anywhere else, and keeps nothing in the browser's storage: Stripe.js,
 * loaded from Stripe, talks to Stripe.
 */
( function () {
	'use strict';

	// Stripe.js, which Stripe serves and updates continuously, and asks to be loaded from its own
	// address rather than bundled or pinned. Loaded on the first mount, never before.
	const STRIPE_JS = 'https://js.stripe.com/v3/';

	// What canMakePayment() mirrors of the gateway's isAvailable(): Stripe's smallest amount by
	// account country and currency, its largest, and the publishable key's prefix by mode.
	// tests/Unit/IslandTest.php holds each to Gateway::MINIMUM_MINOR, Gateway::MAXIMUM_MINOR and
	// StripeApi::isKeyOf().
	const MINIMUM_MINOR = { US: { USD: 50, GBP: 50, EUR: 50 } };
	const MAXIMUM_MINOR = 99999999;
	const KEY_PREFIXES = { test: 'pk_test_', live: 'pk_live_' };

	// Stripe's errors whose message is written for the shopper: by their type, and the failed bank
	// check, which Stripe types as a request's error.
	const SHOPPER_TYPES = [ 'card_error', 'validation_error' ];
	const SHOPPER_CODES = [ 'payment_intent_authentication_failure' ];

	// The statuses of a PaymentIntent whose confirmation went through: authorized, to be captured
	// later, or paid.
	const CONFIRMED = [ 'requires_capture', 'succeeded' ];

	// The one load of Stripe.js on the page, and one Stripe object per publishable key.
	let loading = null;
	const clients = new Map();

	/**
	 * Returns an error of the island's, named for the console, which logs an island's error by its
	 * name alone. Its message is for the shopper, or empty.
	 *
	 * @param {string} code    What went wrong.
	 * @param {string} message Optional. What the shopper reads. Default empty.
	 * @return {Error} The error.
	 */
	function islandError( code, message = '' ) {
		const error = new Error( message );

		error.name = 'StripeIslandError';
		error.code = code;

		return error;
	}

	/**
	 * Returns the message of a Stripe error when Stripe wrote it for the shopper, or empty.
	 *
	 * @param {Object} error Stripe's error.
	 * @return {string} The message.
	 */
	function shopperMessage( error ) {
		return SHOPPER_TYPES.includes( error.type ) ||
			SHOPPER_CODES.includes( error.code )
			? String( error.message || '' )
			: '';
	}

	/**
	 * Tells whether an object has a member of its own.
	 *
	 * @param {Object} object The object.
	 * @param {string} name   The member's name.
	 * @return {boolean} True when it has.
	 */
	function owns( object, name ) {
		return Object.prototype.hasOwnProperty.call( object, name );
	}

	/**
	 * Tells whether Stripe takes this payment, as the gateway's isAvailable() does: the
	 * publishable key is the mode's, the method takes the currency, and the amount is within
	 * Stripe's smallest and largest for the account's country.
	 *
	 * @param {Object} ctx The payment context.
	 * @return {boolean} True when it does.
	 */
	function canMakePayment( ctx ) {
		const key = ctx.settings.publishable_key;
		const country = ctx.settings.account_country;
		const minimums = owns( MINIMUM_MINOR, country ) ? MINIMUM_MINOR[ country ] : {};
		const minimum = owns( minimums, ctx.currency ) ? minimums[ ctx.currency ] : null;

		return (
			owns( KEY_PREFIXES, ctx.mode ) &&
			'string' === typeof key &&
			key.startsWith( KEY_PREFIXES[ ctx.mode ] ) &&
			ctx.currencies.includes( ctx.currency ) &&
			null !== minimum &&
			Number.isInteger( ctx.amountMinor ) &&
			ctx.amountMinor >= minimum &&
			ctx.amountMinor <= MAXIMUM_MINOR
		);
	}

	/**
	 * Loads Stripe.js once, and resolves with its `Stripe` function.
	 *
	 * @return {Promise<Function>} Stripe.
	 */
	function loadStripe() {
		if ( 'function' === typeof window.Stripe ) {
			return Promise.resolve( window.Stripe );
		}

		if ( ! loading ) {
			loading = new Promise( ( resolve, reject ) => {
				const script = document.createElement( 'script' );

				script.src = STRIPE_JS;
				script.async = true;
				script.addEventListener( 'load', () =>
					'function' === typeof window.Stripe
						? resolve( window.Stripe )
						: reject( islandError( 'stripe_js_missing' ) )
				);
				script.addEventListener( 'error', () => {
					// The next mount tries again.
					loading = null;
					script.remove();
					reject( islandError( 'stripe_js_failed' ) );
				} );
				document.head.append( script );
			} );
		}

		return loading;
	}

	/**
	 * Returns the billing details Stripe is sent with the card, from the checkout's billing
	 * address. Every member is sent, null when the checkout has none: the Element collects none
	 * of them, Stripe.js refuses a confirmation that leaves one out, and Stripe refuses an empty
	 * text as an attempt to unset it.
	 *
	 * @param {Object|null} billing The billing address, by the checkout's field names.
	 * @return {Object} The billing details, by Stripe's.
	 */
	function billingDetails( billing ) {
		const given = billing || {};
		const text = ( name ) =>
			( 'string' === typeof given[ name ] && given[ name ].trim() ) ||
			null;

		return {
			name:
				[ text( 'first_name' ), text( 'last_name' ) ]
					.filter( Boolean )
					.join( ' ' ) || null,
			email: text( 'email' ),
			phone: text( 'phone' ),
			address: {
				line1: text( 'line1' ),
				line2: text( 'line2' ),
				city: text( 'city' ),
				state: text( 'region' ),
				postal_code: text( 'postcode' ),
				country: text( 'country' ),
			},
		};
	}

	/**
	 * Shows the Payment Element for a payment, and returns the mount's handle once the Element is
	 * ready. Rejects when Stripe.js or the Element cannot be loaded: the checkout then shows the
	 * method as unavailable.
	 *
	 * @param {HTMLElement} el  The element to show it in, fresh for this mount.
	 * @param {Object}      ctx The payment context.
	 * @return {Promise<Object>} The handle: confirm(), action(), showError(), unmount().
	 */
	async function mount( el, ctx ) {
		const Stripe = await loadStripe();
		const key = ctx.settings.publishable_key;

		if ( ! clients.has( key ) ) {
			clients.set( key, Stripe( key ) );
		}

		const stripe = clients.get( key );
		const elements = stripe.elements( {
			mode: 'payment',
			amount: ctx.amountMinor,
			currency: ctx.currency.toLowerCase(),
			captureMethod: 'manual',
			paymentMethodTypes: [ 'card' ],
		} );
		const payment = elements.create( 'payment', {
			fields: { billingDetails: 'never' },
			wallets: { applePay: 'never', googlePay: 'never' },
		} );
		const frame = document.createElement( 'div' );
		let live = true;
		let notice = null;
		// The message Stripe gave the shopper's last failed confirmation, for a decline the store
		// then answers without one.
		let declined = '';

		const clearNotice = () => {
			notice?.remove();
			notice = null;
		};

		frame.className = 'seocart-island__frame';
		el.append( frame );

		await new Promise( ( resolve, reject ) => {
			payment.on( 'ready', resolve );
			payment.on( 'loaderror', () => {
				payment.destroy();
				reject( islandError( 'element_failed' ) );
			} );
			payment.mount( frame );
		} );

		return {
			/**
			 * Checks the card fields. The placement carries no payment data: Stripe holds the card.
			 *
			 * @return {Promise<Object>} `{ payment_data: {} }`.
			 */
			async confirm() {
				clearNotice();
				declined = '';

				const { error } = await elements.submit();

				if ( ! live ) {
					throw islandError( 'unmounted' );
				}

				if ( error ) {
					// The Element shows what is wrong beside the field.
					throw islandError(
						String( error.code || error.type || 'invalid' ),
						shopperMessage( error )
					);
				}

				return { payment_data: {} };
			},

			/**
			 * Confirms the store's PaymentIntent with the card typed, a bank's check included.
			 * Only Stripe's own step (`sdk`) is taken.
			 *
			 * A form unmounted before or while Stripe confirms no longer owns the step: the host
			 * that unmounted it has moved on, so Stripe is not asked, or its answer is dropped, and
			 * the action reports `left`, the step the host is to do nothing more for.
			 *
			 * @param {Object} next   The step: type, url, client_token.
			 * @param {Object} resume The payment's uuid, the address to come back to, the billing address.
			 * @return {Promise<string>} `completed`, `failed` when Stripe refused the confirmation, or `left`.
			 */
			async action( next, resume ) {
				if ( ! live ) {
					return 'left';
				}

				if ( 'sdk' !== next.type || ! next.client_token ) {
					// Not Stripe's step for this form: the store's resume decides.
					throw islandError( 'step_not_taken' );
				}

				const { error, paymentIntent } = await stripe.confirmPayment( {
					elements,
					clientSecret: next.client_token,
					confirmParams: {
						return_url: resume.returnUrl,
						payment_method_data: {
							billing_details: billingDetails( resume.billing ),
						},
					},
					redirect: 'if_required',
				} );

				if ( ! live ) {
					return 'left';
				}

				if ( ! error ) {
					// Completed only when the payment is authorized for capture, or paid. A PaymentIntent
					// that still waits (for the shopper, a confirmation, its card or Stripe) is reported
					// failed, never completed: the host resumes on either, and the store's answer from
					// Stripe decides.
					return CONFIRMED.includes( paymentIntent?.status )
						? 'completed'
						: 'failed';
				}

				declined = shopperMessage( error );

				return 'failed';
			},

			/**
			 * Shows a decline inside the form: the store's message, or else Stripe's for the
			 * confirmation it refused. A refusal of the fields is shown by the Element itself.
			 *
			 * @param {Object} shown The error: code, message.
			 */
			showError( shown ) {
				const message = String( shown.message || '' ) || declined;

				if ( ! live || 'island_refused' === shown.code || ! message ) {
					return;
				}

				clearNotice();
				notice = document.createElement( 'div' );
				notice.className = 'seocart-notice is-error';
				notice.setAttribute( 'role', 'alert' );
				notice.textContent = message;
				el.prepend( notice );
			},

			/**
			 * Destroys the Element and forgets the mount.
			 */
			unmount() {
				live = false;
				payment.destroy();
				el.replaceChildren();
			},
		};
	}

	( ( window.seocart = window.seocart || {} ).payments = window.seocart
		.payments || {
		queue: [],
		register( island ) {
			this.queue.push( island );
		},
	} ).register( {
		id: 'stripe',
		contractVersion: '0.1.0',
		supports: { action: true },
		canMakePayment,
		mount,
	} );
} )();
