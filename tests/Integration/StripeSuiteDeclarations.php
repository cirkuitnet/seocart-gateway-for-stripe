<?php
/**
 * What the payment conformance suite is told of Stripe, shared by the suite's test and the live tests
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\StripeCodes;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\Tests\Conformance\Payment\NotProducible;
use SEOCart\Tests\Conformance\Payment\Scenario;

// SEOCart's integration bootstrap autoloads this plugin's src/ alone: the unit tests' fixtures and signer are loaded by their path.
require_once dirname( __DIR__ ) . '/Support/Fixtures.php';

/**
 * Tells SEOCart's conformance harness what it needs to know of Stripe: the test payment methods of each scenario, how Stripe signs a delivery, and what of Stripe's answers is public.
 *
 * The suite's test (ConformanceTest), which replays tests/tapes, and the live tests, which send
 * every request to Stripe's test mode, both use it, so the two read Stripe's answers by the same
 * declarations.
 *
 * The declarations tell the suite's scrubber what of Stripe's answers is public, so a tape
 * holds no secret: a PaymentIntent's client secret becomes a placeholder; so does the account's id
 * where Stripe writes it, though the id is not hidden, as the publishable key the tape keeps,
 * public by design, carries it; and every other run of twenty letters, digits, `_` or `-` must be
 * one of Stripe's ids or words named here.
 *
 * @since 0.2.0
 */
trait StripeSuiteDeclarations {

	/**
	 * Stripe's published test payment method of a card that is approved.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const APPROVED = 'pm_card_visa';

	/**
	 * Stripe's published test payment method of a card that is declined (`card_declined`, `generic_decline`).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DECLINED = 'pm_card_chargeDeclined';

	/**
	 * Stripe's published test payment method of a card whose bank must confirm the payment (3D Secure 2).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REQUIRES_ACTION = 'pm_card_threeDSecure2Required';

	/**
	 * The event fixtures a recording writes as its webhook deliveries, by delivery name: one the gateway reads as a result, one it ignores.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const DELIVERIES = array(
		'authorized' => 'event-payment_intent.amount_capturable_updated',
		'ignored'    => 'event-customer.created',
	);

	/**
	 * Returns Stripe's test payment method of each scenario, or why Stripe's test mode cannot produce it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string|NotProducible> By scenario.
	 */
	protected function tokens(): array {
		return array(
			Scenario::Approve->value                 => self::APPROVED,
			Scenario::Decline->value                 => self::DECLINED,
			Scenario::RequiresAction->value          => self::REQUIRES_ACTION,
			Scenario::RequiresActionCompleted->value => new NotProducible( 'Completing the bank\'s page needs a browser.' ),
			Scenario::Expires->value                 => new NotProducible( 'Stripe cannot be made, in test mode, to cancel a PaymentIntent that waits for the shopper.' ),
			Scenario::Pending->value                 => new NotProducible( 'A card payment is never decided later: Stripe answers it at once.' ),
		);
	}

	/**
	 * Returns a stand-in for a secret setting no recording holds: a secret key of the shape the gateway accepts for the mode, and a signing secret.
	 *
	 * @since 0.2.0
	 *
	 * @param string $field The setting's name.
	 * @param Mode   $mode  The mode.
	 * @return string The stand-in.
	 */
	protected function placeholder( string $field, Mode $mode ): string {
		return Gateway::SECRET_KEY === $field ? 'sk_' . $mode->value . '_conformance' : 'whsec_conformance_' . $mode->value;
	}

	/**
	 * Signs a delivery as Stripe does: the `stripe-signature` header, an HMAC of the signed time and the body.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The delivery's headers.
	 * @param string                $body    The raw body.
	 * @param string                $secret  The signing secret.
	 * @param \DateTimeImmutable    $at      When it is signed.
	 * @return array<string, string> The headers, with the signature in place of any earlier one.
	 */
	protected function signDelivery( array $headers, string $body, #[\SensitiveParameter] string $secret, \DateTimeImmutable $at ): array {
		return array( 'stripe-signature' => Fixtures::signature( $body, $secret, $at->getTimestamp() ) ) + $headers;
	}

	/**
	 * Returns the secrets Stripe issues in its answers, each replaced in a tape by its placeholder.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each secret's regular expression, by its placeholder's name.
	 */
	protected function providerSecrets(): array {
		return array(
			// A PaymentIntent's client secret: `client_secret` of every PaymentIntent answer and delivery, and the 3D Secure page's `payment_intent_client_secret`.
			'client_secret'   => '/\bpi_[A-Za-z0-9]+_secret_[A-Za-z0-9]+\b/',
			// The Stripe account's id, which names the store's account: the 3D Secure page's `merchant`.
			// The placeholder hides nothing: the test publishable key the tape keeps, public by design, carries the id's sixteen characters.
			'account_id'      => '/\bacct_[A-Za-z0-9]{16}\b/',
			// A webhook endpoint's signing secret: `secret` of the answer that creates the endpoint, which Stripe gives that once.
			'endpoint_secret' => '/\bwhsec_[A-Za-z0-9]+\b/',
		);
	}

	/**
	 * Returns the shapes of Stripe's ids a tape holds, each the letters and digits after its prefix, as the recording shows them.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|array{int, int}> The shapes, by prefix.
	 */
	protected function publicIdShapes(): array {
		return array(
			// A PaymentIntent: an answer's `id`, a Refund's `payment_intent`, a decline's `error.payment_intent.id`, the 3D Secure page's `payment_intent`, and the request path and body that name it.
			'pi_'      => array( 24, 24 ),
			// A Charge: `latest_charge`, a Refund's `charge`, a decline's `error.charge` and its PaymentIntent's `last_payment_error.charge`.
			'ch_'      => array( 24, 24 ),
			// A Refund: its `id`, alone or in a list's `data`.
			're_'      => array( 24, 24 ),
			// The PaymentMethod Stripe makes of a test payment method: `payment_method`, and a decline's `error.payment_method.id`.
			'pm_'      => array( 24, 24 ),
			// A Refund's `balance_transaction`.
			'txn_'     => array( 24, 24 ),
			// The 3D Secure page's `source`.
			'payatt_'  => array( 24, 24 ),
			// A webhook endpoint: its `id`, alone or in a list's `data`, and the request path that names it.
			'we_'      => array( 24, 24 ),
			// The publishable key, public by design: the tape's `publishable_key` setting and the 3D Secure page's `publishable_key`.
			// Stripe names a key's prefix and no length, and may change the length of its opaque strings up to 255 characters: any length to that passes.
			'pk_test_' => array( 1, 247 ),
		);
	}

	/**
	 * Returns the field whose 64-hex digest is public: a delivery's signature, made with the suite's published secret.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The fields' names.
	 */
	protected function publicDigestFields(): array {
		return array( 'stripe-signature' );
	}

	/**
	 * Returns Stripe's words a tape may hold and a log line may carry: every documented code the adapter knows, and each further word the recording shows.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The words.
	 */
	protected function publicCodes(): array {
		return array_values(
			array_unique(
				array_merge(
					StripeCodes::INTENT_STATUSES,
					StripeCodes::ERROR_TYPES,
					StripeCodes::DECLINE_CODES,
					StripeCodes::ERROR_CODES,
					StripeCodes::REFUND_FAILURE_REASONS,
					StripeCodes::CANCELLATION_REASONS,
					// What each delivery's event `type` holds after its first `.`, such as `amount_capturable_updated` (each fixture is named for its type).
					array_map( static fn( string $fixture ): string => substr( $fixture, (int) strpos( $fixture, '.' ) + 1 ), array_values( self::DELIVERIES ) ),
					array(
						// The `payment_method` of the decline's and of the 3D Secure scenario's authorization, in the request's body.
						self::DECLINED,
						self::REQUIRES_ACTION,
						// A Refund's `destination_details.card.reference_type`.
						'acquirer_reference_number',
						// A query key of the 3D Secure page's URL, `next_action.redirect_to_url.url`; its value is the client secret's placeholder.
						'payment_intent_client_secret',
					)
				)
			)
		);
	}

	/**
	 * Returns Stripe's response headers a tape keeps beside `content-type`: none, as the gateway reads none of them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The headers' names.
	 */
	protected function publicHeaders(): array {
		return array();
	}
}
