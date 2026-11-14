<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/**
 * Checkout robustness (#1400): stored seller payability, one open Session per
 * order, expired / failed / disputed Sessions, and the trimmed webhook router.
 */
require_once __DIR__ . '/MerchCheckoutTest.php';
require_once __DIR__ . '/SellerOrderNotificationsTest.php';
// Shares SellerListingTest's wc_get_orders() double through its callback hook.
require_once __DIR__ . '/SellerListingTest.php';

final class CheckoutRobustnessTest extends \PHPUnit\Framework\TestCase {
	private const VENUE = array( 'type' => 'venue', 'id' => 9911 );

	protected function setUp(): void {
		$GLOBALS['test_options']                 = array( 'admin_email' => 'admin@extrachill.com' );
		$GLOBALS['test_post_meta']               = array();
		$GLOBALS['test_orders']                  = array();
		$GLOBALS['test_transients']              = array();
		$GLOBALS['test_stripe_status']           = null;
		$GLOBALS['test_stripe_status_calls']     = array();
		$GLOBALS['test_owner_calls']             = array();
		$GLOBALS['notification_mail']            = array();
		$GLOBALS['stripe_session_ids']           = array();
		$GLOBALS['stripe_session_status']        = array();
		$GLOBALS['stripe_session_expired']       = array();
		$GLOBALS['stripe_session_retrieved']     = array();
		$GLOBALS['stripe_sessions_created']      = array();
		$GLOBALS['stripe_session_retrieve_fail'] = false;
		$GLOBALS['test_owner_callback']          = static fn() => array( 'type' => 'venue', 'id' => 9911, 'name' => 'Venue' );
		$GLOBALS['test_wc_get_orders_callback']  = static function ( $args ) {
			$GLOBALS['test_wc_get_orders'][] = $args;
			return array_values( array_filter( $GLOBALS['test_orders'], static fn( $order ) => $order->get_meta( $args['meta_key'] ) === $args['meta_value'] ) );
		};
		extrachill_shop_set_product_seller( 10, self::VENUE );
		extrachill_shop_set_seller_stripe_account_record( self::VENUE, array( 'account_id' => 'acct_merch', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['test_wc_get_orders_callback'] );
	}

	private function order(): MerchCheckoutOrder {
		$order                       = new MerchCheckoutOrder();
		$GLOBALS['test_orders'][123] = $order;
		return $order;
	}

	private function session_event( string $type, string $id, string $account = 'acct_merch', string $paid = 'paid' ) {
		return (object) array(
			'type'    => $type,
			'account' => $account,
			'data'    => (object) array(
				'object' => (object) array(
					'id'             => $id,
					'payment_status' => $paid,
					'payment_intent' => 'pi_' . $id,
					'metadata'       => (object) array( 'kind' => 'extrachill_merch', 'order_id' => '123' ),
				),
			),
		);
	}

	// ── Seller payability ────────────────────────────────────────────────────

	public function test_known_status_is_read_from_storage_without_calling_stripe(): void {
		$this->assertTrue( extrachill_shop_seller_can_receive_payments( self::VENUE ) );
		extrachill_shop_update_stripe_account_by_id( 'acct_merch', 'restricted', true );
		$this->assertFalse( extrachill_shop_seller_can_receive_payments( self::VENUE ) );
		$this->assertSame( array(), $GLOBALS['test_stripe_status_calls'] );
	}

	public function test_starting_checkout_makes_no_live_account_call(): void {
		$this->assertFalse( is_wp_error( extrachill_shop_start_merch_checkout( $this->order() ) ) );
		$this->assertSame( array(), $GLOBALS['test_stripe_status_calls'] );
	}

	public function test_unknown_status_falls_back_to_one_cached_live_check(): void {
		$seller = array( 'type' => 'venue', 'id' => 4242 );
		extrachill_shop_set_seller_stripe_account_record( $seller, array( 'account_id' => 'acct_new', 'status' => 'pending' ) );
		$GLOBALS['test_stripe_status'] = array( 'success' => true, 'can_receive_payments' => true );
		$this->assertTrue( extrachill_shop_seller_can_receive_payments( $seller ) );
		$this->assertTrue( extrachill_shop_seller_can_receive_payments( $seller ) );
		$this->assertSame( array( 'acct_new' ), $GLOBALS['test_stripe_status_calls'] );
		$this->assertSame( 120, EXTRACHILL_SHOP_STRIPE_LIVE_STATUS_TTL );
	}

	public function test_failed_live_check_is_not_payable(): void {
		$seller = array( 'type' => 'venue', 'id' => 4343 );
		extrachill_shop_set_seller_stripe_account_record( $seller, array( 'account_id' => 'acct_down', 'status' => 'pending' ) );
		$GLOBALS['test_stripe_status'] = array( 'success' => false, 'error' => 'timeout' );
		$this->assertFalse( extrachill_shop_seller_can_receive_payments( $seller ) );
		$this->assertFalse( extrachill_shop_seller_can_receive_payments( array( 'type' => 'venue', 'id' => 5555 ) ) );
	}

	public function test_account_updated_makes_the_status_known(): void {
		$seller = array( 'type' => 'venue', 'id' => 4444 );
		extrachill_shop_set_seller_stripe_account_record( $seller, array( 'account_id' => 'acct_hook', 'status' => 'pending' ) );
		$event = (object) array(
			'type'    => 'account.updated',
			'account' => 'acct_hook',
			'data'    => (object) array( 'object' => (object) array( 'id' => 'acct_hook', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true ) ),
		);
		$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
		$this->assertTrue( extrachill_shop_seller_can_receive_payments( $seller ) );
		$this->assertSame( array(), $GLOBALS['test_stripe_status_calls'] );
		$this->assertNotSame( '', extrachill_shop_get_seller_stripe_account_record( $seller )['status_synced_at'] );
	}

	public function test_account_updated_for_unknown_account_is_acknowledged_without_binding(): void {
		$event = (object) array(
			'type'    => 'account.updated',
			'account' => 'acct_stranger',
			'data'    => (object) array( 'object' => (object) array( 'id' => 'acct_stranger', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true ) ),
		);
		$before = $GLOBALS['test_options'];
		$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
		$this->assertSame( $before, $GLOBALS['test_options'] );
		$this->assertStringContainsString( 'no seller is bound', file_get_contents( dirname( __DIR__ ) . '/inc/stripe/webhooks.php' ) );
	}

	// ── One open Session per order ────────────────────────────────────────────

	public function test_new_checkout_expires_the_previous_session_on_its_account(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a', 'cs_b' );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertSame( array(), $GLOBALS['stripe_session_expired'] );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertSame( array( array( 'cs_a', array( 'stripe_account' => 'acct_merch' ) ) ), $GLOBALS['stripe_session_expired'] );
		$this->assertSame( array( 'cs_a' => 'acct_merch', 'cs_b' => 'acct_merch' ), $order->meta['_extrachill_checkout_sessions'] );
		$keys = array_map( static fn( $r ) => $r[1]['idempotency_key'], $GLOBALS['stripe_sessions_created'] );
		$this->assertCount( 2, array_unique( $keys ) );
	}

	public function test_platform_session_is_expired_on_the_platform_account(): void {
		$GLOBALS['test_post_meta']     = array();
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a', 'cs_b' );
		extrachill_shop_start_merch_checkout( $order );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertSame( array( array( 'cs_a', array() ) ), $GLOBALS['stripe_session_expired'] );
	}

	public function test_already_paid_previous_session_blocks_a_second_charge(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a', 'cs_b' );
		extrachill_shop_start_merch_checkout( $order );
		$GLOBALS['stripe_session_status']['cs_a'] = 'complete';
		$this->assertSame( 'checkout_already_paid', extrachill_shop_start_merch_checkout( $order )->get_error_code() );
		$this->assertCount( 1, $GLOBALS['stripe_sessions_created'] );
		$GLOBALS['stripe_session_retrieve_fail'] = true;
		$this->assertSame( 'merch_checkout_failed', extrachill_shop_start_merch_checkout( $order )->get_error_code() );
		$this->assertCount( 1, $GLOBALS['stripe_sessions_created'] );
	}

	public function test_already_expired_previous_session_is_not_expired_again(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a', 'cs_b' );
		extrachill_shop_start_merch_checkout( $order );
		$GLOBALS['stripe_session_status']['cs_a'] = 'expired';
		$this->assertFalse( is_wp_error( extrachill_shop_start_merch_checkout( $order ) ) );
		$this->assertSame( array(), $GLOBALS['stripe_session_expired'] );
	}

	/** @dataProvider hold_windows */
	public function test_session_expiry_follows_the_woocommerce_hold( $hold, $minutes ): void {
		$GLOBALS['test_options']['woocommerce_hold_stock_minutes'] = $hold;
		extrachill_shop_start_merch_checkout( $this->order() );
		$data = $GLOBALS['ticket_session_request'][0];
		if ( null === $minutes ) {
			$this->assertArrayNotHasKey( 'expires_at', $data );
		} else {
			$this->assertEqualsWithDelta( time() + $minutes * 60, $data['expires_at'], 2 );
		}
	}

	public static function hold_windows(): array {
		return array(
			'default 60'          => array( '60', 60 ),
			'below Stripe minimum' => array( '10', 30 ),
			'above Stripe maximum' => array( '3000', 1440 ),
			'hold disabled'       => array( '', null ),
		);
	}

	public function test_paying_an_older_session_still_fulfils_the_order(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a', 'cs_b' );
		extrachill_shop_start_merch_checkout( $order );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.completed', 'cs_a' ) ) );
		$this->assertSame( 1, $order->paid );
		$this->assertSame( 'pi_cs_a', $order->meta['_extrachill_payment_intent'] );
		$this->assertSame( 'acct_merch', $order->meta['_extrachill_stripe_account'] );
		// The newer Session paying too is a replay, not a second fulfilment.
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.completed', 'cs_b' ) ) );
		$this->assertSame( 1, $order->paid );
	}

	public function test_unrecorded_session_or_wrong_account_is_refused(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a' );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertSame( 'merch_account_mismatch', extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.completed', 'cs_unknown' ) )->get_error_code() );
		$this->assertSame( 'merch_account_mismatch', extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.completed', 'cs_a', 'acct_other' ) )->get_error_code() );
		$this->assertSame( 0, $order->paid );
	}

	public function test_async_payment_succeeded_fulfils(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a' );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.completed', 'cs_a', 'acct_merch', 'unpaid' ) ) );
		$this->assertSame( 0, $order->paid );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.async_payment_succeeded', 'cs_a' ) ) );
		$this->assertSame( 1, $order->paid );
	}

	// ── Expired and failed Sessions ───────────────────────────────────────────

	public function test_expired_latest_session_cancels_the_pending_order(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a' );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.expired', 'cs_a', 'acct_merch', 'unpaid' ) ) );
		$this->assertSame( 'cancelled', $order->status );
		$this->assertStringContainsString( 'cs_a', $order->notes[0] );
	}

	public function test_expiring_a_superseded_session_leaves_the_order_alone(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a', 'cs_b' );
		extrachill_shop_start_merch_checkout( $order );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.expired', 'cs_a', 'acct_merch', 'unpaid' ) ) );
		$this->assertSame( 'pending', $order->status );
		$this->assertSame( array(), $order->notes );
	}

	public function test_expired_session_never_cancels_a_paid_order(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a' );
		extrachill_shop_start_merch_checkout( $order );
		extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.completed', 'cs_a' ) );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.expired', 'cs_a', 'acct_merch', 'unpaid' ) ) );
		$this->assertSame( 'processing', $order->status );
	}

	public function test_async_payment_failed_marks_the_order_failed(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a' );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.async_payment_failed', 'cs_a', 'acct_merch', 'unpaid' ) ) );
		$this->assertSame( 'failed', $order->status );
	}

	public function test_expired_event_for_another_account_is_refused(): void {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a' );
		extrachill_shop_start_merch_checkout( $order );
		$this->assertInstanceOf( WP_Error::class, extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.expired', 'cs_a', 'acct_other', 'unpaid' ) ) );
		$this->assertSame( 'pending', $order->status );
	}

	private function ticket_order( string $status ): void {
		$GLOBALS['test_options']['extrachill_ticket_order_cs_t'] = array( 'account' => 'acct_venue', 'seller' => self::VENUE, 'event' => 101, 'payment_intent' => '', 'status' => $status );
	}

	private function ticket_event( string $type, string $account = 'acct_venue' ) {
		return (object) array(
			'type'    => $type,
			'account' => $account,
			'data'    => (object) array( 'object' => (object) array( 'id' => 'cs_t', 'payment_status' => 'unpaid', 'metadata' => (object) array( 'kind' => 'extrachill_ticket' ) ) ),
		);
	}

	public function test_expired_or_failed_ticket_session_closes_the_pending_row(): void {
		$this->ticket_order( 'pending' );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->ticket_event( 'checkout.session.expired' ) ) );
		$this->assertSame( 'expired', $GLOBALS['test_options']['extrachill_ticket_order_cs_t']['status'] );
		$this->ticket_order( 'pending' );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->ticket_event( 'checkout.session.async_payment_failed' ) ) );
		$this->assertSame( 'failed', $GLOBALS['test_options']['extrachill_ticket_order_cs_t']['status'] );
		// Events recorded nothing for an unpaid Session, so nothing is called there.
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
	}

	public function test_closing_ticket_session_leaves_issued_rows_and_checks_account(): void {
		$this->ticket_order( 'issued' );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->ticket_event( 'checkout.session.expired' ) ) );
		$this->assertSame( 'issued', $GLOBALS['test_options']['extrachill_ticket_order_cs_t']['status'] );
		$this->ticket_order( 'pending' );
		$this->assertSame( 'ticket_account_mismatch', extrachill_shop_route_webhook_event( $this->ticket_event( 'checkout.session.expired', 'acct_other' ) )->get_error_code() );
		$this->assertSame( 'pending', $GLOBALS['test_options']['extrachill_ticket_order_cs_t']['status'] );
	}

	// ── Merch disputes ────────────────────────────────────────────────────────

	private function paid_order(): MerchCheckoutOrder {
		$order                         = $this->order();
		$GLOBALS['stripe_session_ids'] = array( 'cs_a' );
		extrachill_shop_start_merch_checkout( $order );
		extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.completed', 'cs_a' ) );
		return $order;
	}

	private function dispute( string $account = 'acct_merch' ) {
		return (object) array(
			'type'    => 'charge.dispute.created',
			'account' => $account,
			'data'    => (object) array( 'object' => (object) array( 'id' => 'dp_merch', 'payment_intent' => 'pi_cs_a', 'amount' => 2500, 'reason' => 'product_not_received', 'metadata' => (object) array() ) ),
		);
	}

	public function test_merch_dispute_holds_the_order_and_emails_seller_and_admin(): void {
		$order                          = $this->paid_order();
		$GLOBALS['test_owner_calls']    = array();
		$GLOBALS['test_owner_callback'] = static fn() => array( 'sent' => 1 );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->dispute() ) );
		$this->assertSame( 'on-hold', $order->status );
		$this->assertSame( 'dp_merch', $order->meta['_extrachill_dispute'] );
		$this->assertStringContainsString( '$25.00', end( $order->notes ) );
		$this->assertStringContainsString( 'product not received', end( $order->notes ) );
		// The venue's finance owners are emailed through Events.
		$this->assertCount( 1, $GLOBALS['test_owner_calls'] );
		$this->assertStringContainsString( 'notify-commerce-seller', $GLOBALS['test_owner_calls'][0]['route'] );
		$input = $GLOBALS['test_owner_calls'][0]['args']['body']['input'];
		$this->assertSame( self::VENUE, $input['seller'] );
		$this->assertSame( 'Order #123 was disputed', $input['subject'] );
		// The admin gets a copy.
		$this->assertCount( 1, $GLOBALS['notification_mail'] );
		$this->assertSame( 'admin@extrachill.com', $GLOBALS['notification_mail'][0]['to'] );
		$this->assertStringContainsString( 'acct_merch', $GLOBALS['notification_mail'][0]['context']['body_html'] );
		// Stripe retries are acknowledged without a second round of email.
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->dispute() ) );
		$this->assertCount( 1, $GLOBALS['test_owner_calls'] );
		$this->assertCount( 1, $GLOBALS['notification_mail'] );
	}

	public function test_artist_merch_dispute_emails_the_roster(): void {
		// An artist no other suite resolves, so the per-request artist cache stays cold for them.
		$artist = array( 'type' => 'artist', 'id' => 19 );
		extrachill_shop_set_product_seller( 10, $artist );
		extrachill_shop_set_seller_stripe_account_record( $artist, array( 'account_id' => 'acct_artist', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00' ) );
		$GLOBALS['test_owner_callback'] = static fn() => array( 'id' => 19, 'name' => 'Artist' );
		$order                          = $this->order();
		$GLOBALS['stripe_session_ids']  = array( 'cs_a' );
		extrachill_shop_start_merch_checkout( $order );
		extrachill_shop_route_webhook_event( $this->session_event( 'checkout.session.completed', 'cs_a', 'acct_artist' ) );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->dispute( 'acct_artist' ) ) );
		$this->assertSame( 'on-hold', $order->status );
		$to = array_column( $GLOBALS['notification_mail'], 'to' );
		$this->assertContains( 'user4@example.com', $to );
		$this->assertContains( 'user5@example.com', $to );
		$this->assertContains( 'admin@extrachill.com', $to );
	}

	public function test_dispute_on_another_account_does_not_touch_the_order(): void {
		$order = $this->paid_order();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->dispute( 'acct_other' ) ) );
		$this->assertSame( 'processing', $order->status );
		$this->assertSame( array(), $GLOBALS['notification_mail'] );
	}

	// ── Removed transfer-era handlers ─────────────────────────────────────────

	public function test_removed_platform_events_change_nothing(): void {
		$order = $this->paid_order();
		foreach ( array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'charge.refunded' ) as $type ) {
			$event = (object) array(
				'type'    => $type,
				'account' => '',
				'data'    => (object) array( 'object' => (object) array( 'id' => 'pi_cs_a', 'payment_intent' => 'pi_cs_a', 'amount_refunded' => 2500, 'currency' => 'usd', 'metadata' => (object) array( 'order_id' => '123' ) ) ),
			);
			$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
		}
		$this->assertSame( 'processing', $order->status );
		$this->assertSame( 1, $order->paid );
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/stripe/webhooks.php' );
		foreach ( array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'extrachill_shop_payment_succeeded', 'extrachill_shop_payment_failed', 'extrachill_shop_charge_refunded', 'extrachill_shop_stripe_account_updated', '_stripe_charges' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $source );
		}
		$this->assertStringNotContainsString( '_extrachill_checkout_session\'', file_get_contents( dirname( __DIR__ ) . '/inc/stripe/checkout-handler.php' ) );
		$this->assertStringNotContainsString( 'Account::retrieve', file_get_contents( dirname( __DIR__ ) . '/inc/stripe/checkout-handler.php' ) . file_get_contents( dirname( __DIR__ ) . '/inc/stripe/tickets.php' ) );
	}

	public function test_payments_page_lists_the_events_the_code_handles(): void {
		$page = file_get_contents( dirname( __DIR__, 2 ) . '/extrachill-network/admin/network-payments-settings.php' );
		preg_match( '/"Your account" events: (.+?)\. Paste/', $page, $platform );
		preg_match( '/"Connected accounts" events: (.+?)\. Paste/', $page, $connect );
		$sessions = array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired' );
		$this->assertNotEmpty( $platform );
		$this->assertNotEmpty( $connect );
		$listed = static fn( $text ) => preg_match_all( '/[a-z_]+\.[a-z_.]+/', $text, $m ) ? $m[0] : array();
		$this->assertSame( $sessions, $listed( $platform[1] ) );
		$this->assertSame( array_merge( $sessions, array( 'charge.refunded', 'charge.dispute.created', 'account.updated' ) ), $listed( $connect[1] ) );
	}
}
