// Tests the checkout's card form script, assets/island.js, as it ships, in a context of its own
// with a stand-in window, document and Stripe.js: when it says Stripe takes a payment; what it
// gives Stripe's Element and its confirmation; what each of Stripe's answers comes to; and that it
// loads Stripe.js on its first mount and never before.
//
// Planted violations, each shown red and removed:
// - in canMakePayment(), the maximum's check dropped: the table's row above the maximum fails;
// - in action(), a failed confirmation reported as completed: the decline's mapping fails;
// - in unmount(), the Element not destroyed: the unmount test fails;
// - in billingDetails(), an absent detail sent as empty text, which Stripe refuses on a retry
//   (parameter_invalid_empty): the no-address test fails.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../assets/island.js', import.meta.url), 'utf8');

// A stand-in for a DOM element: what the script does with one, recorded.
function element(tag) {
	const listeners = {};

	return {
		tag,
		className: '',
		textContent: '',
		attributes: {},
		children: [],
		removed: false,
		setAttribute(name, value) {
			this.attributes[name] = value;
		},
		addEventListener(type, listener) {
			listeners[type] = listener;
		},
		dispatch(type) {
			listeners[type]?.();
		},
		append(...nodes) {
			nodes.forEach((node) => (node.parent = this));
			this.children.push(...nodes);
		},
		prepend(...nodes) {
			nodes.forEach((node) => (node.parent = this));
			this.children.unshift(...nodes);
		},
		replaceChildren() {
			this.children = [];
		},
		remove() {
			this.removed = true;

			if (this.parent) {
				this.parent.children = this.parent.children.filter((child) => child !== this);
			}
		},
	};
}

// An object of the script's context, made plain, to compare with one of this one's.
function plain(value) {
	return JSON.parse(JSON.stringify(value));
}

// A stand-in for Stripe.js: one Stripe object per call, its elements and its answers, recorded.
function fakeStripe({ submit = {}, confirm = { paymentIntent: { status: 'requires_capture' } }, loads = true } = {}) {
	const seen = { keys: [], elements: [], created: [], confirmations: [], destroyed: 0 };

	const Stripe = (key) => {
		seen.keys.push(key);

		return {
			elements(options) {
				seen.elements.push(options);

				return {
					create(type, options) {
						const listeners = {};

						seen.created.push({ type, options });

						return {
							on(event, listener) {
								listeners[event] = listener;
							},
							mount() {
								queueMicrotask(() => (loads ? listeners.ready?.() : listeners.loaderror?.({})));
							},
							destroy() {
								seen.destroyed++;
							},
						};
					},
					submit: async () => submit,
				};
			},
			async confirmPayment(params) {
				seen.confirmations.push(params);

				return confirm;
			},
		};
	};

	return { Stripe, seen };
}

// Runs the script with a window that has the stand-in Stripe.js already, or none, and returns
// what it registered and the page it ran in.
function load(stripe = fakeStripe(), { withStripe = true } = {}) {
	const registered = [];
	const head = element('head');
	const sandbox = {
		console,
		document: { head, createElement: element },
		seocart: { payments: { register: (island) => registered.push(island) } },
		...(withStripe ? { Stripe: stripe.Stripe } : {}),
	};

	sandbox.window = sandbox;
	vm.runInContext(SOURCE, vm.createContext(sandbox), { filename: 'island.js' });
	assert.equal(registered.length, 1, 'The script registers one island.');

	return { island: registered[0], head, sandbox, seen: stripe.seen };
}

// A payment context, as the host gives one.
function context(changes = {}) {
	return {
		gatewayId: 'stripe',
		mode: 'test',
		channel: 'storefront',
		amountMinor: 1999,
		currency: 'USD',
		billingCountry: 'US',
		cartVersion: 3,
		settings: { publishable_key: 'pk_test_example', account_country: 'US' },
		currencies: ['USD', 'GBP', 'EUR'],
		locale: 'en_US',
		...changes,
	};
}

// The step the store answers a placement with: Stripe's own, with the PaymentIntent's handle.
const STEP = { type: 'sdk', url: null, client_token: 'pi_example_secret_example' };

// The resume's details, as the host gives them.
const RESUME = { intentUuid: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e', returnUrl: 'https://shop.example/checkout/?seocart_payment=0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e', billing: null };

test('the island registers as stripe, against contract 0.1.0, taking the steps itself', () => {
	const { island } = load();

	assert.equal(island.id, 'stripe');
	assert.equal(island.contractVersion, '0.1.0');
	assert.equal(island.supports.action, true);
});

test('canMakePayment answers as the gateway would, row by row', () => {
	const { island } = load();
	const rows = [
		[{}, true, 'a payment of 19.99 USD in test mode'],
		[{ currency: 'GBP' }, true, 'in GBP'],
		[{ currency: 'EUR' }, true, 'in EUR'],
		[{ amountMinor: 50 }, true, 'the smallest amount'],
		[{ amountMinor: 49 }, false, 'below the smallest amount'],
		[{ amountMinor: 99999999 }, true, 'the largest amount'],
		[{ amountMinor: 100000000 }, false, 'above the largest amount'],
		[{ amountMinor: 1000.5 }, false, 'not a whole number of minor units'],
		[{ currency: 'JPY', currencies: ['USD', 'GBP', 'EUR', 'JPY'] }, false, 'a currency with no minimum'],
		[{ currency: 'GBP', currencies: ['USD'] }, false, 'a currency the method does not take'],
		[{ settings: { publishable_key: 'pk_live_example', account_country: 'US' } }, false, 'a live key in test mode'],
		[{ mode: 'live', settings: { publishable_key: 'pk_live_example', account_country: 'US' } }, true, 'a live key in live mode'],
		[{ mode: 'live' }, false, 'a test key in live mode'],
		[{ settings: { publishable_key: 'sk_test_example', account_country: 'US' } }, false, 'a secret key'],
		[{ settings: { account_country: 'US' } }, false, 'no key'],
		[{ settings: { publishable_key: 'pk_test_example', account_country: 'GB' } }, false, 'an account country with no minimums'],
		[{ settings: { publishable_key: 'pk_test_example', account_country: 'constructor' } }, false, 'a country named as an object member'],
		[{ mode: 'sandbox' }, false, 'a mode the gateway does not have'],
		[{ mode: 'constructor' }, false, 'a mode named as an object member'],
	];

	for (const [changes, expected, row] of rows) {
		assert.equal(island.canMakePayment(context(changes)), expected, row);
	}
});

test('Stripe.js is loaded on the first mount, once, from Stripe, and never before', async () => {
	const stripe = fakeStripe();
	const { island, head, sandbox } = load(stripe, { withStripe: false });

	assert.equal(head.children.length, 0, 'Nothing is loaded before a mount.');

	const first = island.mount(element('div'), context());
	const second = island.mount(element('div'), context());

	assert.equal(head.children.length, 1, 'One script, for both mounts.');
	assert.equal(head.children[0].src, 'https://js.stripe.com/v3/');

	sandbox.Stripe = stripe.Stripe;
	head.children[0].dispatch('load');
	await Promise.all([first, second]);

	assert.deepEqual(stripe.seen.keys, ['pk_test_example'], 'One Stripe object per publishable key.');
});

test('a Stripe.js that fails to load fails the mount, and the next mount loads it again', async () => {
	const { island, head } = load(fakeStripe(), { withStripe: false });
	const first = island.mount(element('div'), context());

	head.children[0].dispatch('error');
	await assert.rejects(first, { name: 'StripeIslandError', code: 'stripe_js_failed' });
	assert.equal(head.children.length, 0, 'The failed script is removed.');

	island.mount(element('div'), context()).catch(() => {});
	assert.equal(head.children.length, 1, 'The next mount tries again.');
});

test('the Element is made for the amount, in deferred-intent mode, for later capture, with card fields only', async () => {
	const { island, seen } = load();
	const el = element('div');

	await island.mount(el, context({ amountMinor: 3998, currency: 'GBP' }));

	assert.deepEqual(plain(seen.elements), [{ mode: 'payment', amount: 3998, currency: 'gbp', captureMethod: 'manual', paymentMethodTypes: ['card'] }]);
	assert.deepEqual(plain(seen.created), [{ type: 'payment', options: { fields: { billingDetails: 'never' }, wallets: { applePay: 'never', googlePay: 'never' } } }]);
	assert.equal(el.children[0].className, 'seocart-island__frame');
});

test('an Element that fails to load fails the mount, and is destroyed', async () => {
	const { island, seen } = load(fakeStripe({ loads: false }));

	await assert.rejects(island.mount(element('div'), context()), { name: 'StripeIslandError', code: 'element_failed' });
	assert.equal(seen.destroyed, 1);
});

test('confirm() sends no payment data once Stripe accepts the fields, and refuses with the field error otherwise', async () => {
	const accepted = await (await load().island.mount(element('div'), context())).confirm({ billing: null });

	assert.deepEqual(plain(accepted), { payment_data: {} });

	const refusal = { error: { type: 'validation_error', code: 'incomplete_number', message: 'Your card number is incomplete.' } };
	const handle = await load(fakeStripe({ submit: refusal })).island.mount(element('div'), context());

	await assert.rejects(handle.confirm({ billing: null }), { name: 'StripeIslandError', code: 'incomplete_number', message: 'Your card number is incomplete.' });
});

test('action() confirms the store\'s PaymentIntent with the card typed and the billing address, and answers completed', async () => {
	const { island, seen } = load();
	const handle = await island.mount(element('div'), context());
	const billing = { country: 'US', first_name: 'Ada', last_name: 'Lovelace', line1: '1 Main Street', line2: ' ', city: 'Springfield', region: 'IL', postcode: '62701', email: 'ada@example.com' };

	assert.equal(await handle.action(STEP, { ...RESUME, billing }), 'completed');
	assert.equal(seen.confirmations.length, 1);

	const [{ elements, ...params }] = seen.confirmations;

	assert.ok(elements, 'The Element\'s group is confirmed.');
	assert.deepEqual(plain(params), {
		clientSecret: STEP.client_token,
		confirmParams: {
			return_url: RESUME.returnUrl,
			payment_method_data: {
				billing_details: {
					name: 'Ada Lovelace',
					email: 'ada@example.com',
					phone: null,
					address: { line1: '1 Main Street', line2: null, city: 'Springfield', state: 'IL', postal_code: '62701', country: 'US' },
				},
			},
		},
		redirect: 'if_required',
	});
});

test('action() sends every billing detail, null, when the checkout gives no address: never an empty text, which Stripe refuses', async () => {
	const { island, seen } = load();

	await (await island.mount(element('div'), context())).action(STEP, RESUME);

	assert.deepEqual(plain(seen.confirmations[0].confirmParams.payment_method_data.billing_details), {
		name: null,
		email: null,
		phone: null,
		address: { line1: null, line2: null, city: null, state: null, postal_code: null, country: null },
	});
});

test('a confirmation Stripe refuses answers failed, and the decline then shows Stripe\'s message', async () => {
	const declined = { error: { type: 'card_error', code: 'card_declined', message: 'Your card was declined.' } };
	const el = element('div');
	const handle = await load(fakeStripe({ confirm: declined })).island.mount(el, context());

	assert.equal(await handle.action(STEP, RESUME), 'failed');

	handle.showError({ code: 'declined', message: '' });

	const [notice] = el.children;

	assert.equal(notice.className, 'seocart-notice is-error');
	assert.equal(notice.attributes.role, 'alert');
	assert.equal(notice.textContent, 'Your card was declined.');

	handle.showError({ code: 'declined', message: 'The payment was declined.' });
	assert.equal(el.children[0].textContent, 'The payment was declined.', 'The store\'s message comes first.');
	assert.equal(el.children.filter((child) => child.className === 'seocart-notice is-error').length, 1, 'One notice at a time.');
});

test('a failed bank challenge answers failed; an error not written for the shopper shows nothing', async () => {
	const failed = { error: { type: 'card_error', code: 'payment_intent_authentication_failure', message: 'We are unable to authenticate your payment method.' } };

	assert.equal(await (await load(fakeStripe({ confirm: failed })).island.mount(element('div'), context())).action(STEP, RESUME), 'failed');

	const el = element('div');
	const handle = await load(fakeStripe({ confirm: { error: { type: 'invalid_request_error', message: 'No such payment_intent: pi_example' } } })).island.mount(el, context());

	assert.equal(await handle.action(STEP, RESUME), 'failed');
	handle.showError({ code: 'declined', message: '' });
	assert.equal(el.children.length, 1, 'Only the Element\'s frame: no notice without a message for the shopper.');
});

test('a step that is not Stripe\'s is not taken: Stripe is not called, and the host resumes', async () => {
	const { island, seen } = load();
	const handle = await island.mount(element('div'), context());

	await assert.rejects(handle.action({ type: 'redirect', url: 'https://hooks.stripe.com/example', client_token: null }, RESUME), { name: 'StripeIslandError', code: 'step_not_taken' });
	await assert.rejects(handle.action({ type: 'sdk', url: null, client_token: null }, RESUME), { code: 'step_not_taken' });
	assert.equal(seen.confirmations.length, 0);
});

test('a refusal of the fields is shown by the Element alone, and the next confirmation clears a notice', async () => {
	const el = element('div');
	const handle = await load().island.mount(el, context());

	handle.showError({ code: 'island_refused', detail: 'incomplete_number', message: 'Your card number is incomplete.' });
	assert.equal(el.children.length, 1, 'No notice for a refusal of the fields.');

	handle.showError({ code: 'declined', message: 'The payment was declined.' });
	assert.equal(el.children.length, 2);

	const [notice] = el.children;

	await handle.confirm({ billing: null });
	assert.equal(notice.removed, true, 'The notice is removed.');
	assert.equal(el.children.length, 1);
});

test('unmount() destroys the Element, empties the form, and a confirmation in flight is abandoned', async () => {
	let release;
	const { island, seen } = load();
	const el = element('div');
	const handle = await island.mount(el, context());
	const pending = new Promise((resolve) => {
		release = resolve;
	});
	const confirmStripe = fakeStripe({ submit: pending });
	const held = await load(confirmStripe).island.mount(element('div'), context());
	const confirming = held.confirm({ billing: null });

	held.unmount();
	release({});
	await assert.rejects(confirming, { code: 'unmounted' });
	assert.equal(confirmStripe.seen.destroyed, 1);

	handle.unmount();
	assert.equal(seen.destroyed, 1, 'The Element is destroyed.');
	assert.equal(el.children.length, 0, 'The form is emptied.');
	handle.showError({ code: 'declined', message: 'The payment was declined.' });
	assert.equal(el.children.length, 0, 'An unmounted form shows nothing more.');
});
