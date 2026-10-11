<?php
/**
 * Tests the gateway's one declaration
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use Brain\Monkey\Functions;
use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\GatewayForStripe\WebhookReader;
use SEOCart\Support\Currency;
use SEOCart\Support\Schema\Privacy;

/**
 * The gateway's describe() declares two modes, four settings per mode, one matrix row per certified account country and currency
 * with the ten operations Stripe supports, the idempotency profile and one host; it is built from constants, so it
 * reads nothing, sends nothing and calls no WordPress function.
 *
 * Planted violations, each shown red and removed:
 * - a `get_option()` call in describe(): the no-I/O test fails (Brain Monkey has no such function);
 * - a GB row in the matrix: the row test fails.
 *
 * @since 0.2.0
 */
final class DescriptorTest extends GatewayTestCase {

	/**
	 * Tests the id, label, type, contract and modes.
	 *
	 * @since 0.2.0
	 */
	public function test_the_gateway_declares_its_id_label_type_contract_and_modes(): void {
		$descriptor = $this->gateway->describe();

		$this->assertSame( 'stripe', $descriptor->id );
		$this->assertSame( 'Stripe', $descriptor->label() );
		$this->assertSame( GatewayDescriptor::TYPE_PAYMENTS, $descriptor->type );
		$this->assertSame( PaymentGateway::CONTRACT_VERSION, $descriptor->contract, 'The gateway is written against the contract SEOCart implements.' );
		$this->assertSame( array( Mode::Test, Mode::Live ), $descriptor->modes );
	}

	/**
	 * Tests the four settings: names, privacy, the countries allowed, and no default.
	 *
	 * @since 0.2.0
	 */
	public function test_each_mode_keeps_two_credentials_a_publishable_key_and_the_account_country(): void {
		$settings = array();

		foreach ( $this->gateway->describe()->settings as $field ) {
			$settings[ $field->name() ] = $field;
		}

		$this->assertSame( array( 'secret_key', 'publishable_key', 'webhook_secret', 'account_country' ), array_keys( $settings ) );
		$this->assertSame( Privacy::Secret, $settings['secret_key']->privacy() );
		$this->assertSame( Privacy::Secret, $settings['webhook_secret']->privacy() );
		$this->assertSame( Privacy::Public, $settings['publishable_key']->privacy() );
		$this->assertSame( Privacy::Public, $settings['account_country']->privacy() );
		$this->assertSame( Gateway::ACCOUNT_COUNTRIES, $settings['account_country']->allowedValues(), 'The countries a store may set are the matrix\'s.' );

		foreach ( $settings as $name => $field ) {
			$this->assertNull( $field->defaultValue(), "{$name} has no default: a mode without it is not configured." );
		}
	}

	/**
	 * Tests the matrix: one row per certified country and currency, each with exactly the ten operations; nothing elsewhere.
	 *
	 * @since 0.2.0
	 */
	public function test_the_matrix_declares_the_ten_operations_for_a_us_account_in_usd_gbp_and_eur_and_nothing_else(): void {
		$matrix = $this->gateway->describe()->matrix;
		$cells  = array();

		foreach ( $matrix->rows as $row ) {
			$cells[]  = $row->accountCountry . '/' . $row->currency->code();
			$declared = $row->operations;
			$expected = Gateway::OPERATIONS;
			sort( $declared );
			sort( $expected );

			$this->assertSame( $expected, $declared );
		}

		$this->assertSame( array( 'US/USD', 'US/GBP', 'US/EUR' ), $cells );

		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			foreach ( Operations::ALL as $operation ) {
				$expected = in_array( $operation, Gateway::OPERATIONS, true );

				$this->assertSame( $expected, $matrix->allows( $operation, Currency::of( $code ), 'US' ), "{$operation} in {$code} for a US account." );
				$this->assertFalse( $matrix->allows( $operation, Currency::of( $code ), 'GB' ), "{$operation} in {$code} for a GB account is not certified." );
				$this->assertFalse( $matrix->allows( $operation, Currency::of( $code ), null ), "{$operation} in {$code} with no account country." );
			}
		}

		$this->assertFalse( $matrix->allows( Operations::AUTHORIZE, Currency::of( 'JPY' ), 'US' ) );
	}

	/**
	 * Tests the idempotency profile and the host.
	 *
	 * @since 0.2.0
	 */
	public function test_the_profile_and_the_one_host(): void {
		$descriptor = $this->gateway->describe();

		$this->assertSame( array( 86400, true, 3600 ), array( $descriptor->idempotency->keyRetentionSeconds, $descriptor->idempotency->searchable, $descriptor->idempotency->searchDelaySeconds ) );
		$this->assertCount( 1, $descriptor->hosts );
		$this->assertSame( array( 'stripe-api', 'api.stripe.com', 30 ), array( $descriptor->hosts[0]->id, $descriptor->hosts[0]->host, $descriptor->hosts[0]->timeoutSeconds ) );
	}

	/**
	 * Tests that describe() reads nothing, sends nothing and calls no WordPress function: not even the translation of its label.
	 *
	 * @since 0.2.0
	 */
	public function test_describe_reads_nothing_sends_nothing_and_calls_no_wordpress_function(): void {
		$context = $this->createMock( ExtensionContext::class );
		$context->expects( $this->never() )->method( $this->anything() );
		Functions\when( '__' )->alias(
			static function (): never {
				throw new \LogicException( 'describe() translated its label.' );
			}
		);

		$descriptor = ( new Gateway( $context ) )->describe();

		$this->assertSame( Gateway::ID, $descriptor->id );
	}

	/**
	 * Tests that the events the endpoint subscribes to are exactly those the reader maps, refunds by the Refund's own events.
	 *
	 * @since 0.2.0
	 */
	public function test_the_events_subscribed_are_the_events_read(): void {
		$events = $this->gateway->webhookEvents();

		$this->assertSame( array_keys( WebhookReader::EVENTS ), $events );
		$this->assertContains( 'refund.created', $events );
		$this->assertContains( 'refund.failed', $events );
		$this->assertNotContains( 'charge.refunded', $events, 'A Charge does not say which refund was made.' );
	}

	/**
	 * Tests that every cell of the matrix has a smallest amount, so availability never falls back to "any amount".
	 *
	 * @since 0.2.0
	 */
	public function test_every_cell_has_a_smallest_amount(): void {
		foreach ( Gateway::ACCOUNT_COUNTRIES as $country ) {
			foreach ( Gateway::CURRENCIES as $code ) {
				$this->assertArrayHasKey( $code, Gateway::MINIMUM_MINOR[ $country ], "{$country}/{$code}" );
			}
		}
	}

	/**
	 * Tests that no setting's example looks like a real key.
	 *
	 * @since 0.2.0
	 */
	public function test_no_example_looks_like_a_real_key(): void {
		foreach ( $this->gateway->describe()->settings as $field ) {
			$this->assertDoesNotMatchRegularExpression( '/^(sk|rk|pk)_(test|live)_[0-9A-Za-z]{20,}/', (string) $field->example() );
		}
	}
}
