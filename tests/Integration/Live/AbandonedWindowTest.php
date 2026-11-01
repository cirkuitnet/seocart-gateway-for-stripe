<?php
/**
 * Tests, against Stripe's test mode, a shopper who never completes the bank's page
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration\Live;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\GatewayForStripe\Tests\Support\Live\MessageGuard;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Promotion\Infrastructure\PromotionTables;

require_once __DIR__ . '/LiveStripeCase.php';

/**
 * A payment whose shopper was sent to the bank's page and never came back is voided by the store's reconciliation run once the shopper's time is over: Stripe cancels the PaymentIntent with the reason `abandoned`, and the store gives back everything the order held.
 *
 * Placed with Stripe's card that always asks for 3-D Secure and a promotion code, and never
 * completed. Stripe's answer to the cancellation is the adapter's to read: a refusal of the
 * cancellation or of its reason fails the test, as it is a defect of the adapter, never a skip.
 *
 * @since 0.2.0
 *
 * @group stripe-live
 */
final class AbandonedWindowTest extends LiveStripeCase {

	/**
	 * Tests that the window's end voids the payment at Stripe as abandoned, and releases the order as a decline releases it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_shopper_who_never_acts_is_voided_and_cancelled_at_stripe_as_abandoned(): void {
		$this->begin( 'live-abandoned-window' );

		$placed = $this->placeThreeDSecure( 'LIVEWINDOW' );

		$this->endWait( $placed['intent_uuid'], ReconcileStalePlacements::STALE_SECONDS + 1 );
		$this->assertNull( $this->kernel->get( ReconcileStalePlacements::class )->handle( array() ) );
		$this->end();

		$intent = $this->stripeIntent( (string) $placed['intent_id'] );

		$this->assertSame( array( 'canceled', 'abandoned' ), array( $intent['status'] ?? null, $intent['cancellation_reason'] ?? null ), MessageGuard::say( 'Stripe holds the PaymentIntent %1$s %2$s, not canceled as abandoned.', (string) $placed['intent_id'], (string) ( $intent['status'] ?? 'none' ) ) );

		$order = (array) $this->db->fetchRow( 'SELECT status, payment_status, has_unreconciled_money FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $placed['order_id'] );

		$this->assertSame( array( 'cancelled', 'voided', 0 ), array( (string) $order['status'], (string) $order['payment_status'], (int) $order['has_unreconciled_money'] ) );
		$this->assertSame( array( 'voided', 'action_window_ended' ), array_values( (array) $this->db->fetchRow( 'SELECT status, voided_reason FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $placed['intent_uuid'] ) ) );
		$this->assertSame( array( 'void', 'approved', '1', 'system', null ), array_values( (array) $this->db->fetchRow( 'SELECT operation, result, applied, actor_type, actor_id FROM %i WHERE order_id = %d', $this->table( PaymentTables::TRANSACTIONS ), $placed['order_id'] ) ), 'One ledger row, the void, recorded as the store\'s.' );
		$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', $this->table( PaymentTables::TRANSACTIONS ), $placed['order_id'] ), 'One ledger row.' );
		$this->assertSame( array( '10', '0', '0' ), array_map( 'strval', array_values( (array) $this->db->fetchRow( 'SELECT on_hand, allocated, held FROM %i WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $placed['variant'] ) ) ), 'The hold is given back.' );
		$this->assertSame( 'released', $this->db->fetchValue( 'SELECT state FROM %i WHERE order_id = %d', $this->table( PromotionTables::USAGE ), $placed['order_id'] ), 'The promotion\'s use is given back.' );
		$this->assertSame( 'open', $this->db->fetchValue( 'SELECT status FROM %i WHERE id = %d', $this->table( CartTables::CARTS ), $placed['cart_id'] ), 'The cart is open again.' );
		$this->assertSame( 'voided', ( (array) json_decode( (string) $this->db->fetchValue( 'SELECT response_json FROM %i WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $placed['order_id'] ), true ) )['outcome'] ?? null, 'A retry of the placement is told it was voided.' );
	}
}
