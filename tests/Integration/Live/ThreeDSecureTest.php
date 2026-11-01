<?php
/**
 * Tests, against Stripe's test mode, 3-D Secure completed on the bank's page in a browser
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration\Live;

use SEOCart\Checkout\Application\ResumePayment;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\GatewayForStripe\Tests\Support\Live\EventSelection;
use SEOCart\GatewayForStripe\Tests\Support\Live\LiveRun;
use SEOCart\GatewayForStripe\Tests\Support\Live\MessageGuard;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\PaymentTables;

require_once __DIR__ . '/LiveStripeCase.php';

/**
 * A payment Stripe sends to the bank's page is completed there in a browser, by Playwright on Stripe's own test page, and then settles as the store's resume, its window's end or Stripe's own event settles it.
 *
 * Pressed authorize, the resume authorizes the order once, its ledger naming Stripe's charge, and
 * Stripe holds the amount for capture; pressed fail, the resume declines it and releases the
 * order. Completed and never resumed, the window's end voids it before it asks Stripe anything,
 * and the store and Stripe must then agree: a cancelled authorization, or a kept one, never one
 * captured. Completed and announced by Stripe's event before the resume, the event settles it and
 * the resume answers that it was settled already. Stripe writes its events at the account's API
 * version, which may not be the version the adapter pins: such an event is held to the safety
 * conditions alone, and what the store answered it is reported.
 *
 * @since 0.2.0
 *
 * @group stripe-live
 */
final class ThreeDSecureTest extends LiveStripeCase {

	/**
	 * Tests that a challenge completed in the browser is resumed as an authorization, and one failed there as a decline that releases the order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_completed_challenge_is_resumed_as_an_authorization_and_a_failed_one_as_a_decline(): void {
		$this->begin( 'live-three-d-secure-completed' );

		$placed = $this->placeThreeDSecure();
		$word   = $this->completeInBrowser( $placed['next_action'], 'authorize' );

		$this->assertSame( 'succeeded', $word, MessageGuard::say( 'The bank\'s page answered %s when authorize was pressed.', $word ) );

		$resumed = $this->resume( $placed['intent_uuid'] );
		$this->end();

		$this->assertSame( array( 'approved', 'processing', 'authorized' ), array( $resumed['outcome'] ?? null, $resumed['status'] ?? null, $resumed['payment_status'] ?? null ) );
		$this->assertSame( 'authorized', $this->intentRow( $placed['intent_uuid'] )['status'] );

		list( $charge, $result ) = $this->ledgerRowOf( $placed['order_id'], 'authorize' );

		$this->assertSame( 'approved', $result );
		$this->assertMatchesRegularExpression( '/^(?:ch|py)_[A-Za-z0-9]+$/D', (string) $charge, 'The authorization\'s ledger row names no charge of Stripe\'s.' );
		$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', $this->table( PaymentTables::TRANSACTIONS ), $placed['order_id'] ), 'One ledger row.' );

		$intent = $this->stripeIntent( (string) $placed['intent_id'] );

		$this->assertSame( 'requires_capture', $intent['status'] ?? null, MessageGuard::say( 'Stripe holds the PaymentIntent %1$s %2$s, not requires_capture.', (string) $placed['intent_id'], (string) ( $intent['status'] ?? 'none' ) ) );
		$this->assertSame( $this->intentRow( $placed['intent_uuid'] )['amount_minor'], (int) ( $intent['amount_capturable'] ?? 0 ), 'Stripe holds another amount for capture than the order\'s.' );

		$this->begin( 'live-three-d-secure-failed' );

		$failed = $this->placeThreeDSecure();
		$word   = $this->completeInBrowser( $failed['next_action'], 'fail' );

		$this->assertSame( 'failed', $word, MessageGuard::say( 'The bank\'s page answered %s when fail was pressed.', $word ) );

		$resumed = $this->resume( $failed['intent_uuid'] );
		$this->end();

		$this->assertSame( 'declined', $resumed['outcome'] ?? null );
		$this->assertSame( 'failed', $this->db->fetchValue( 'SELECT status FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $failed['order_id'] ) );
		$this->assertSame( array( '10', '0', '0' ), $this->stockOf( $failed['variant'] ), 'The declined order\'s hold is given back.' );
	}

	/**
	 * Tests that a challenge completed and then left alone ends, at the window's end, with the store and Stripe agreeing and nothing captured.
	 *
	 * The window's end voids before it asks Stripe anything, and Stripe accepts the cancellation
	 * of a PaymentIntent that waits for capture, so the expected end is a cancelled authorization;
	 * an approval Stripe kept is the other agreement. The report says which it was.
	 *
	 * @since 0.2.0
	 */
	public function test_a_completed_challenge_never_resumed_ends_with_the_store_and_stripe_agreeing(): void {
		$this->begin( 'live-three-d-secure-window-ends' );

		$placed = $this->placeThreeDSecure();
		$word   = $this->completeInBrowser( $placed['next_action'], 'authorize' );

		$this->assertSame( 'succeeded', $word, MessageGuard::say( 'The bank\'s page answered %s when authorize was pressed.', $word ) );

		$this->endWait( $placed['intent_uuid'], ReconcileStalePlacements::STALE_SECONDS + 1 );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );
		$this->end();

		$store  = $this->intentRow( $placed['intent_uuid'] );
		$intent = $this->stripeIntent( (string) $placed['intent_id'] );
		$pair   = $store['status'] . '/' . (string) ( $intent['status'] ?? 'none' );

		LiveRun::report( 'window-end-after-approval', $pair );

		$this->assertContains( $pair, array( 'voided/canceled', 'authorized/requires_capture', 'authorized/succeeded' ), MessageGuard::say( 'The store and Stripe disagree at the window\'s end: %s.', $pair ) );
		$this->assertSame( 0, (int) ( $intent['amount_received'] ?? -1 ), 'Stripe captured the payment.' );
		$this->assertSame( 0, $store['captured_minor'], 'The store captured the payment.' );
	}

	/**
	 * Tests that Stripe's event of an approval, delivered before the resume, settles the payment, and the resume then answers it was settled already.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_delivered_before_the_resume_leaves_the_resume_a_duplicate(): void {
		$this->begin( 'live-three-d-secure-delivered-first' );

		$placed = $this->placeThreeDSecure();
		$word   = $this->completeInBrowser( $placed['next_action'], 'authorize' );

		$this->assertSame( 'succeeded', $word, MessageGuard::say( 'The bank\'s page answered %s when authorize was pressed.', $word ) );

		$id     = (string) $placed['intent_id'];
		$events = $this->eventsOf( array( $id => $placed['intent_uuid'] ), array( $id => array( 'payment_intent.amount_capturable_updated' ) ) );
		$event  = array_values( array_filter( $events, static fn( array $event ): bool => 'payment_intent.amount_capturable_updated' === ( $event['type'] ?? null ) ) )[0];
		$answer = $this->deliver( $event, new \DateTimeImmutable(), $this->runSecret() );

		$resumed = $this->resume( $placed['intent_uuid'] );
		$this->end();

		self::reportTally( 'api-versions-before', EventSelection::apiVersions( array( $event ) ) );
		self::reportTally( 'before-delivery', self::tallied( array( 'resume ' . (string) ( $resumed['outcome'] ?? 'none' ) => 1 ), $answer, $event ) );

		$this->assertSame( 200, $answer['status'], MessageGuard::say( 'The store answered Stripe\'s event of the approval %1$d %2$s.', $answer['status'], (string) $answer['code'] ) );
		$this->assertSafe( array( $placed['order_id'] ), array( (string) $event['id'] ) );

		if ( self::atThePin( $event ) ) {
			$this->assertSame( 'duplicate', $resumed['outcome'] ?? null, 'The resume after the event settled the payment did not answer duplicate.' );
		}
	}

	/**
	 * Resumes a payment as the shopper's browser does on its return from the bank's page.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return array<string, mixed> The store's answer.
	 */
	private function resume( string $intentUuid ): array {
		return $this->kernel->get( ResumePayment::class )->resume( array( 'intent_uuid' => $intentUuid ), self::guest() );
	}

	/**
	 * Reads a variant's stock: on hand, allocated, held.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variant The variant.
	 * @return list<string> The three counts.
	 */
	private function stockOf( int $variant ): array {
		return array_map( 'strval', array_values( (array) $this->db->fetchRow( 'SELECT on_hand, allocated, held FROM %i WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $variant ) ) );
	}
}
