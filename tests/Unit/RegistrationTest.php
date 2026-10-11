<?php
/**
 * Tests that the main file registers the gateway on the action SEOCart fires
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\GatewayForStripe\Gateway;
use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\PaymentGateway;

/**
 * The main file loads before SEOCart, so it names the registration action as a string, not as
 * the constant SEOCart declares it in; these tests hold the string equal to the constant, and
 * check what the registration builds. A wrong string registers the gateway on an action that is
 * never fired, so SEOCart never sees it, and no error says so.
 *
 * @since 0.1.0
 */
final class RegistrationTest extends TestCase {

	/**
	 * Returns the main file's source.
	 *
	 * @since 0.1.0
	 *
	 * @return string The source.
	 */
	private static function mainFile(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/seocart-gateway-for-stripe.php' );
	}

	/**
	 * Tests that the main file makes one registration, naming its action by a string.
	 *
	 * @since 0.1.0
	 */
	public function test_the_main_file_hooks_one_action_by_its_string(): void {
		preg_match_all( '/^[ \t]*add_action\(\s*(.)/m', self::mainFile(), $calls );

		$this->assertSame( array( "'" ), $calls[1], 'The main file registers once, with the name as a quoted string: SEOCart is not loaded when it runs.' );
	}

	/**
	 * Tests that the string is the action SEOCart fires.
	 *
	 * @since 0.1.0
	 */
	public function test_the_string_is_the_action_seocart_declares(): void {
		preg_match_all( "/^[ \t]*add_action\(\s*'([^']*)'/m", self::mainFile(), $actions );

		$this->assertSame( array( GatewayRegistry::ACTION ), $actions[1], 'The main file hooks an action SEOCart does not fire.' );
	}

	/**
	 * Tests that the gateway describes itself by the id the registration names, written against the contract SEOCart implements.
	 *
	 * What it declares beyond that is DescriptorTest's.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The gateway declares what it can do; DescriptorTest checks it.
	 */
	public function test_the_gateway_declares_its_id_and_the_contract_it_is_written_against(): void {
		$descriptor = ( new Gateway( $this->createStub( ExtensionContext::class ) ) )->describe();

		$this->assertSame( Gateway::ID, $descriptor->id );
		$this->assertSame( GatewayDescriptor::TYPE_PAYMENTS, $descriptor->type );
		$this->assertSame( PaymentGateway::CONTRACT_VERSION, $descriptor->contract );
	}
}
