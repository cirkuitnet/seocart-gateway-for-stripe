<?php
/**
 * Tests the gateway against SEOCart's payment gateway conformance suite
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\Tests\Conformance\Payment\GatewayConformanceCase;

// SEOCart's integration bootstrap autoloads this plugin's src/ alone: the unit tests' fixtures and signer, and the suite's declarations of Stripe, are loaded by their path.
require_once dirname( __DIR__ ) . '/Support/Fixtures.php';
require_once __DIR__ . '/StripeSuiteDeclarations.php';

/**
 * Holds the gateway this plugin registers to every rule of SEOCart's payment gateway conformance suite, replaying Stripe's answers from tests/tapes.
 *
 * `sh ../seocart/bin/ci/extension.sh conformance . payments` runs it; a plain run of the
 * integration suite leaves it out. The tapes are Stripe's test mode, recorded with
 * SEOCART_TAPE=record, SEOCART_TAPE_SETTINGS naming a file of the test mode's settings readable by
 * its owner alone, and a fresh SEOCART_TAPE_NONCE each time, as Stripe answers an idempotency key it
 * has seen in the last day with its first answer. The webhook deliveries are the adapter's event
 * fixtures (freshDeliveries() says why).
 *
 * What the suite is told of Stripe, the test payment methods and what of Stripe's answers is
 * public, is StripeSuiteDeclarations, which the live tests use too.
 *
 * @since 0.2.0
 */
final class ConformanceTest extends GatewayConformanceCase {

	use StripeSuiteDeclarations;

	/**
	 * Returns the id the plugin registers the gateway under.
	 *
	 * @since 0.2.0
	 *
	 * @return string The gateway's id.
	 */
	protected function gatewayId(): string {
		return Gateway::ID;
	}

	/**
	 * Returns the directory of the provider's recorded answers.
	 *
	 * @since 0.2.0
	 *
	 * @return string The directory.
	 */
	protected function tapes(): string {
		return dirname( __DIR__ ) . '/tapes';
	}

	/**
	 * Returns the two deliveries a recording writes: the adapter's own event fixtures, which the unit tests read too.
	 *
	 * Stripe writes an event's `data.object` at the API version of the webhook endpoint it sends the
	 * event to, and keeps it at the account's default version for `GET /v1/events`, so an event read
	 * back that way is not the shape SEOCart's endpoint, set up at Gateway::API_VERSION, is sent; a
	 * recording creates no endpoint. The fixtures are written at Gateway::API_VERSION:
	 * `payment_intent.amount_capturable_updated`, which the gateway reads as a result, and
	 * `customer.created`, which it ignores as a type it does not read, as it would a
	 * `payment_intent.created` (no fixture is of that type). Each is signed at the time the event
	 * says it was created; the suite signs it again with its published secret.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>> Each delivery (mode, signed_at, headers, body) by name.
	 */
	protected function freshDeliveries(): array {
		$deliveries = array();

		foreach ( self::DELIVERIES as $name => $fixture ) {
			$deliveries[ $name ] = array(
				'mode'      => Mode::Test->value,
				'signed_at' => gmdate( DATE_ATOM, (int) Fixtures::object( $fixture )['created'] ),
				'headers'   => array( 'content-type' => 'application/json; charset=utf-8' ),
				'body'      => Fixtures::text( $fixture ),
			);
		}

		return $deliveries;
	}
}
