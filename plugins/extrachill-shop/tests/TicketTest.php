<?php
/**
 * Ticket checkout, fulfilment and refund tests.
 *
 * @package ExtraChillShop
 */

require_once __DIR__ . '/fixtures/class-stripe-checkout-session.php';
require_once __DIR__ . '/fixtures/class-stripe-refund.php';
require_once __DIR__ . '/fixtures/ticket-test-functions.php';
require_once dirname( __DIR__ ) . '/inc/core/ticket-service-authority.php';
require_once dirname( __DIR__ ) . '/inc/stripe/tickets.php';
require_once dirname( __DIR__ ) . '/inc/stripe/webhooks.php';
require_once dirname( __DIR__ ) . '/inc/core/legal-documents.php';
require_once dirname( __DIR__ ) . '/inc/core/account-tickets.php';
require_once dirname( __DIR__ ) . '/inc/abilities/tickets.php';
require_once dirname( __DIR__ ) . '/inc/core/seller-order-notifications.php';
// Ticket orders are option rows; the show-wide refund scans them by prefix.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for the options table scan.
$GLOBALS['wpdb'] = new class() {
	public $options = 'wp_options';
	public function esc_like( $text ) { return $text; }
	public function prepare( $query, ...$args ) { return $args[0]; }
	public function get_col( $prefix ) {
		return array_values( array_filter( array_keys( $GLOBALS['test_options'] ), static fn( $name ) => str_starts_with( $name, rtrim( $prefix, '%' ) ) ) );
	}
};

final class TicketTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		extrachill_shop_reset_seller_permission_cache();
		$GLOBALS['test_options']           = array(
			'extrachill_shop_stripe_seller_venue:9911' => array(
				'account_id'       => 'acct_venue',
				'status'           => 'active',
				'status_synced_at' => '2026-11-01T00:00:00+00:00',
			),
		);
		$GLOBALS['test_user_id']           = 551;
		$GLOBALS['test_site_options']      = array();
		$GLOBALS['test_blog_options']      = array();
		$GLOBALS['ticket_refunds']         = array();
		$GLOBALS['ticket_refund_fail']     = false;
		$GLOBALS['test_owner_calls']       = array();
		$GLOBALS['test_user_meta']         = array();
		$GLOBALS['ticket_session_request'] = null;
		// Two $10 tickets at the shipped defaults ($1 per ticket, 2.9% + 30¢
		// processing estimate): the buyer pays $22.97.
		$GLOBALS['ticket_charge_amount']   = 2297;
		$GLOBALS['ticket_offer']           = array(
			'event_id'    => 101,
			'title'       => 'Lo-Fi show',
			'url'         => 'https://events.extrachill.com/show',
			'enabled'     => true,
			'sale_open'   => true,
			'remaining'   => null,
			'price_minor' => 1000,
			'currency'    => 'usd',
			'seller'      => array(
				'type' => 'venue',
				'id'   => 9911,
			),
		);
		$GLOBALS['ticket_issue_status']    = 'issued';
		$GLOBALS['ticket_redeemed']        = false;
		$GLOBALS['test_owner_callback']    = static function ( $site, $method, $route ) {
			if ( str_contains( $route, 'get-event-ticket-offer' ) ) {
				return $GLOBALS['ticket_offer']; }
			if ( str_contains( $route, 'get-commerce-seller' ) ) {
				return array(
					'type'                => 'venue',
					'id'                  => 9911,
					'can_manage_finances' => true,
				); }
			if ( str_contains( $route, 'issue-ticket-passes' ) ) {
				return array(
					'status' => $GLOBALS['ticket_issue_status'],
					'passes' => array(),
				); }
			if ( str_contains( $route, 'get-ticket-order-status' ) ) {
				return array(
					'event_id'     => 101,
					'passes'       => $GLOBALS['ticket_passes'] ?? array(),
					'any_redeemed' => $GLOBALS['ticket_redeemed'],
					'refundable'   => ! $GLOBALS['ticket_redeemed'],
				); }
			if ( str_contains( $route, 'record-checkout-ticket-sales' ) ) {
				return array( 'recorded' => true ); }
			if ( str_contains( $route, 'restore-ticket-passes' ) ) {
				return $GLOBALS['ticket_restore'] ?? array( 'restored' => 2 ); }
			if ( str_contains( $route, 'notify-commerce-seller' ) ) {
				return array( 'sent' => 1 ); }
			if ( $GLOBALS['ticket_redeemed'] ) {
				return new WP_Error( 'ticket_already_redeemed', 'Already redeemed.' ); }
			return array( 'revoked' => true );
		};
	}
	private function checkout( array $overrides = array() ) {
		return extrachill_shop_start_ticket_checkout( array_merge( array(
			'event'          => 101,
			'quantity'       => 2,
			'name'           => 'Buyer',
			'email'          => 'buyer@example.com',
			'terms_accepted' => true,
		), $overrides ) );
	}
	private function event( $type = 'checkout.session.completed' ) {
		if ( 'charge.refunded' === $type ) {
			// Real charges carry no PaymentIntent metadata.
			return (object) array(
				'type'    => $type,
				'account' => 'acct_venue',
				'data'    => (object) array(
					'object' => (object) array(
						'refunds'        => (object) array(
							'has_more' => false,
							'data'     => array(
								(object) array(
									'id'       => 're_ticket',
									'amount'   => 2297,
									'created'  => 1700000000,
									'status'   => 'succeeded',
									'metadata' => (object) array(),
								),
							),
						),
						'id'             => 'ch_ticket',
						'refunded'       => true,
						'payment_intent' => 'pi_ticket',
						'metadata'       => (object) array(),
					),
				),
			);
		}
		return (object) array(
			'type'    => $type,
			'account' => 'acct_venue',
			'data'    => (object) array(
				'object' => (object) array(
					'id'             => 'cs_ticket',
					'payment_status' => 'paid',
					'payment_intent' => 'pi_ticket',
					'customer_email' => 'buyer@example.com',
					'metadata'       => (object) array(
						'kind'     => 'extrachill_ticket',
						'quantity' => 2,
						'name'     => 'Buyer',
					),
				),
			),
		);
	}
	public function test_checkout_and_refund_observations(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) );
		$calls = array_values( array_filter( $GLOBALS['test_owner_calls'], static fn( $call ) => str_contains( $call['route'], 'record-checkout-ticket-sales' ) ) );
		$this->assertCount( 2, $calls );
		$this->assertSame( 'cs_ticket', $calls[0]['args']['body']['input']['external_report_id'] );
		$this->assertSame( 2297, $calls[0]['args']['body']['input']['gross_minor'] );
		$this->assertSame( 200, $calls[0]['args']['body']['input']['fees_minor'] );
		$this->assertSame( 're_ticket', $calls[1]['args']['body']['input']['external_report_id'] );
		$this->assertSame( -2297, $calls[1]['args']['body']['input']['gross_minor'] );
		$this->assertSame( -200, $calls[1]['args']['body']['input']['fees_minor'] );
		$this->assertSame( -2, $calls[1]['args']['body']['input']['tickets_sold'] );
	}

	public function test_fee_math_and_defaults(): void {
		$seller = array(
			'type' => 'venue',
			'id'   => 9911,
		);
		// Shipped defaults: $1 per ticket plus the 2.9% + 30¢ processing estimate.
		$this->assertSame( array(
			'buyer_fee'       => 194,
			'application_fee' => 100,
		), extrachill_commerce_fee( 'extrachill_ticket', 2000, $seller, 1 ) );
		$this->assertSame( array(
			'buyer_fee'       => 682,
			'application_fee' => 400,
		), extrachill_commerce_fee( 'extrachill_ticket', 8000, $seller, 4 ) );
		// Older settings still apply on top: per-order fee, buyer percent, seller percent.
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array(
			'ticket_buyer_fee_per_ticket_minor' => 0,
			'ticket_buyer_fee_flat_minor'       => 100,
			'ticket_buyer_fee_percent'          => 3,
			'ticket_percent'                    => 5,
			'ticket_processing_percent'         => 0,
			'ticket_processing_fixed_minor'     => 0,
		);
		$this->assertSame( array(
			'buyer_fee'       => 160,
			'application_fee' => 260,
		), extrachill_commerce_fee( 'extrachill_ticket', 2000, $seller ) );
		// Everything off: no fee at all.
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array(
			'ticket_buyer_fee_per_ticket_minor' => 0,
			'ticket_processing_percent'         => 0,
			'ticket_processing_fixed_minor'     => 0,
		);
		$this->assertSame( array(
			'buyer_fee'       => 0,
			'application_fee' => 0,
		), extrachill_commerce_fee( 'extrachill_ticket', 2000, $seller, 2 ) );
	}

	/** @return array<string,array{int,int}> */
	public static function ticket_orders(): array {
		$cases = array();
		foreach ( array( 500, 2000, 5000 ) as $price ) {
			foreach ( array( 1, 4, 10 ) as $quantity ) {
				$cases[ sprintf( '$%d x %d', $price / 100, $quantity ) ] = array( $price, $quantity );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider ticket_orders
	 */
	public function test_venue_nets_the_full_subtotal_at_the_processing_estimate( int $price, int $quantity ): void {
		$seller   = array(
			'type' => 'venue',
			'id'   => 9911,
		);
		$subtotal = $price * $quantity;
		$fees     = extrachill_commerce_fee( 'extrachill_ticket', $subtotal, $seller, $quantity );
		$total    = $subtotal + $fees['buyer_fee'];
		// The estimate rounds the percentage up, so the venue nets exactly the
		// subtotal at the estimate...
		$estimate = extrachill_commerce_ticket_processing_estimate( $total, ec_commerce_fee_settings() );
		$this->assertSame( (int) ceil( $total * 0.029 ) + 30, $estimate );
		$this->assertSame( $subtotal, $total - $estimate - $fees['application_fee'], 'Venue nets exactly the subtotal.' );
		// ...and the same or a cent more when the processor rounds to the nearest cent.
		$net = $total - ( (int) round( $total * 0.029 ) + 30 ) - $fees['application_fee'];
		$this->assertContains( $net - $subtotal, array( 0, 1 ) );
		// Extra Chill keeps about $1 per ticket (rounding can cost it a cent or two).
		$this->assertEqualsWithDelta( 100 * $quantity, $fees['application_fee'], 2 );
		// The smallest such total: a cent less would not cover the fees and processing.
		$this->assertLessThan( $subtotal + 100 * $quantity + 30, ( $total - 1 ) - (int) ceil( ( $total - 1 ) * 0.029 ) );
	}

	public function test_venue_never_nets_less_than_the_subtotal_at_any_price(): void {
		$settings = ec_commerce_fee_settings();
		foreach ( array( 1, 2, 3, 99, 101, 333, 999, 1234, 4999, 12345, 99999 ) as $price ) {
			foreach ( array( 1, 3, 7, 10 ) as $quantity ) {
				$subtotal = $price * $quantity;
				$fees     = extrachill_commerce_fee( 'extrachill_ticket', $subtotal, array( 'type' => 'venue', 'id' => 9911 ), $quantity );
				$total    = $subtotal + $fees['buyer_fee'];
				$this->assertSame( $subtotal, $total - extrachill_commerce_ticket_processing_estimate( $total, $settings ) - $fees['application_fee'], "$price x $quantity" );
				$this->assertGreaterThanOrEqual( 0, $fees['application_fee'] );
			}
		}
		// A processing estimate with no buyer fee still keeps the venue whole.
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'ticket_buyer_fee_per_ticket_minor' => 0 );
		$fees = extrachill_commerce_fee( 'extrachill_ticket', 1000, array( 'type' => 'venue', 'id' => 9911 ), 1 );
		$this->assertGreaterThanOrEqual( 0, $fees['application_fee'] );
		$this->assertSame( 1000, 1000 + $fees['buyer_fee'] - extrachill_commerce_ticket_processing_estimate( 1000 + $fees['buyer_fee'], ec_commerce_fee_settings() ) - $fees['application_fee'] );
	}

	public function test_free_tickets_and_platform_tickets_have_no_fee(): void {
		$none = array(
			'buyer_fee'       => 0,
			'application_fee' => 0,
		);
		$this->assertSame( $none, extrachill_commerce_fee( 'extrachill_ticket', 0, array( 'type' => 'venue', 'id' => 9911 ), 4 ) );
		$this->assertSame( $none, extrachill_commerce_fee( 'extrachill_ticket', 2000, null, 1 ) );
		// A free offer never reaches Stripe.
		$GLOBALS['ticket_offer']['price_minor'] = 0;
		$this->assertSame( 'invalid_ticket_price', $this->checkout()->get_error_code() );
		$this->assertNull( $GLOBALS['ticket_session_request'] );
	}

	public function test_direct_charge_session(): void {
		$this->assertSame( 'cs_ticket', $this->checkout()['session_id'] );
		list( $params, $opts ) = $GLOBALS['ticket_session_request'];
		$this->assertSame( 'acct_venue', $opts['stripe_account'] );
		// Two $10 tickets: one ticket line, one Service fee line.
		$this->assertCount( 2, $params['line_items'] );
		$this->assertSame( 2, $params['line_items'][0]['quantity'] );
		$this->assertSame( 1000, $params['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'Service fee', $params['line_items'][1]['price_data']['product_data']['name'] );
		$this->assertSame( 1, $params['line_items'][1]['quantity'] );
		$this->assertSame( 297, $params['line_items'][1]['price_data']['unit_amount'] );
		$this->assertSame( 200, $params['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( 'extrachill_ticket', $params['payment_intent_data']['metadata']['kind'] );
		$this->assertStringContainsString( 'session_id={CHECKOUT_SESSION_ID}', $params['success_url'] );
		$this->assertEqualsWithDelta( time() + 1800, $params['expires_at'], 2 );
		$this->assertArrayNotHasKey( 'automatic_tax', $params );
		// The order row keeps the split.
		$order = $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket'];
		$this->assertSame( array(
			'subtotal_minor'        => 2000,
			'service_fee_minor'     => 297,
			'total_minor'           => 2297,
			'application_fee_minor' => 200,
			'currency'              => 'usd',
		), $order['amounts'] );
		$this->assertSame( 2297, $order['sales']['gross_minor'] );
		$this->assertSame( 200, $order['sales']['fees_minor'] );
	}

	public function test_full_refund_returns_the_service_fee_and_the_application_fee(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$this->assertSame( array( 'refunded' => true ), extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) ) );
		list( $params, $opts ) = $GLOBALS['ticket_refunds'][0];
		// No amount: the whole charge, tickets plus Service fee, goes back to the buyer.
		$this->assertArrayNotHasKey( 'amount', $params );
		$this->assertSame( 'pi_ticket', $params['payment_intent'] );
		$this->assertTrue( $params['refund_application_fee'] );
		$this->assertSame( '200', $params['metadata']['application_fee_minor'] );
		$this->assertSame( 'acct_venue', $opts['stripe_account'] );
		$record = end( $GLOBALS['test_owner_calls'] );
		$calls  = array_values( array_filter( $GLOBALS['test_owner_calls'], static fn( $call ) => str_contains( $call['route'], 'record-checkout-ticket-sales' ) ) );
		$refund = end( $calls )['args']['body']['input'];
		$this->assertSame( -2297, $refund['gross_minor'] );
		$this->assertSame( -200, $refund['fees_minor'] );
		$this->assertSame( -2, $refund['tickets_sold'], 'A refund of the whole charge, fee included, cancels the tickets.' );
		$this->assertNotEmpty( $record );
	}

	public function test_refund_everyone_refunds_each_order_in_full(): void {
		$GLOBALS['stripe_session_ids'] = array( 'cs_one', 'cs_two' );
		$this->checkout();
		$this->checkout( array( 'quantity' => 4 ) );
		foreach ( array( 'cs_one', 'cs_two' ) as $reference ) {
			$order                   = $GLOBALS['test_options'][ 'extrachill_ticket_order_' . $reference ];
			$order['status']         = 'issued';
			$order['payment_intent'] = 'pi_' . $reference;
			$GLOBALS['test_options'][ 'extrachill_ticket_order_' . $reference ] = $order;
		}
		$this->assertSame( 4563, $GLOBALS['test_options']['extrachill_ticket_order_cs_two']['amounts']['total_minor'] );
		$this->assertSame( 400, $GLOBALS['test_options']['extrachill_ticket_order_cs_two']['amounts']['application_fee_minor'] );
		$counts = extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) );
		$this->assertSame( 2, $counts['refunded'] );
		$this->assertCount( 2, $GLOBALS['ticket_refunds'] );
		foreach ( $GLOBALS['ticket_refunds'] as $refund ) {
			$this->assertArrayNotHasKey( 'amount', $refund[0] );
			$this->assertTrue( $refund[0]['refund_application_fee'] );
		}
		$this->assertSame( array( '200', '400' ), array_map( static fn( $refund ) => $refund[0]['metadata']['application_fee_minor'], $GLOBALS['ticket_refunds'] ) );
		unset( $GLOBALS['stripe_session_ids'] );
	}
	public function test_zero_fees_and_refusals(): void {
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array(
			'ticket_buyer_fee_per_ticket_minor' => 0,
			'ticket_processing_percent'         => 0,
			'ticket_processing_fixed_minor'     => 0,
		);
		$this->checkout();
		$this->assertCount( 1, $GLOBALS['ticket_session_request'][0]['line_items'], 'No Service fee line without a fee.' );
		$this->assertSame( 0, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
		$GLOBALS['ticket_offer']['sale_open'] = false;
		$this->assertSame( 'ticket_sale_closed', $this->checkout()->get_error_code() );
		$GLOBALS['ticket_offer']['sale_open'] = true;
		$GLOBALS['ticket_offer']['remaining'] = 1;
		$this->assertSame( 'ticket_sold_out', $this->checkout()->get_error_code() );
		$GLOBALS['ticket_offer']['remaining'] = null;
		$GLOBALS['test_options']['extrachill_shop_stripe_seller_venue:9911']['status'] = 'restricted';
		$this->assertSame( 'seller_payments_unavailable', $this->checkout()->get_error_code() );
	}
	public function test_fulfilment_replay_and_service_identity(): void {
		$this->checkout();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		$count = count( $GLOBALS['test_owner_calls'] );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		$this->assertCount( $count, $GLOBALS['test_owner_calls'] );
		$call = end( $GLOBALS['test_owner_calls'] );
		$this->assertSame( 0, $call['args']['user_id'] );
		$this->assertSame( 'extrachill.events.tickets', $call['args']['service_assertion']['service_id'] );
		$this->assertSame( 'cs_ticket', $call['args']['body']['input']['idempotency_key'] );
	}
	public function test_sold_out_auto_refund_and_replay(): void {
		$this->checkout();
		$GLOBALS['ticket_issue_status'] = 'sold_out';
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		extrachill_shop_route_webhook_event( $this->event() );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
		$this->assertTrue( $GLOBALS['ticket_refunds'][0][0]['refund_application_fee'] );
		$this->assertSame( 'acct_venue', $GLOBALS['ticket_refunds'][0][1]['stripe_account'] );
		$this->assertSame( 'sold_out', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['refund_reason'] );
	}
	public function test_refund_refused_after_check_in_then_allowed_before(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$GLOBALS['ticket_redeemed'] = true;
		$this->assertSame( 'ticket_already_redeemed', extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
		$GLOBALS['ticket_redeemed'] = false;
		$this->assertSame( array( 'refunded' => true ), extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) ) );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
	}
	public function test_failed_refund_can_be_retried(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$GLOBALS['ticket_refund_fail'] = true;
		$this->assertSame( 'ticket_refund_failed', extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) )->get_error_code() );
		$this->assertSame( 'refund_pending', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] );
		$GLOBALS['ticket_refund_fail'] = false;
		$this->assertSame( array( 'refunded' => true ), extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) ) );
		$this->assertSame( 'ticket-refund-cs_ticket', $GLOBALS['ticket_refunds'][0][1]['idempotency_key'] );
	}
	public function test_wrong_account_and_unpaid_sessions_do_not_issue(): void {
		$this->checkout();
		$event          = $this->event();
		$event->account = 'acct_other';
		$this->assertSame( 'ticket_account_mismatch', extrachill_shop_route_webhook_event( $event )->get_error_code() );
		$event                               = $this->event();
		$event->data->object->payment_status = 'unpaid';
		$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
		$this->assertSame( 'pending', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] );
	}
	public function test_source_grants_are_bounded(): void {
		add_filter( 'extrachill_shop_ticket_service_assertion_keys', static function () { return array( 'test' => 'secret' );
		} );
		add_filter( 'extrachill_shop_ticket_service_assertion_active_key_id', static function () { return 'test';
		} );
		$grants = extrachill_shop_register_ticket_source_grants( array() );
		$this->assertCount( 6, $grants );
		foreach ( $grants as $grant ) {
			$this->assertSame( 'POST', $grant['method'] );
			$this->assertSame( 'events.extrachill.com', $grant['target_host'] );
			$this->assertSame( 'extrachill/events:tickets', $grant['scope'] );
		}
		$this->assertSame( 'owner_runtime_unavailable', extrachill_shop_execute_ticket_owner_ability( 'delete-event', array() )->get_error_code() );
	}
	public function test_refund_webhook_after_check_in_is_acknowledged(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$GLOBALS['ticket_redeemed'] = true;
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event( 'charge.refunded' ) ) );
	}
	public function test_refund_webhook_revokes_and_non_ticket_routes_normally(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event( 'charge.refunded' ) ) );
		$this->assertStringContainsString( 'revoke-ticket-passes', end( $GLOBALS['test_owner_calls'] )['route'] );
		$this->assertTrue( extrachill_shop_route_webhook_event( (object) array(
			'type' => 'payment_intent.succeeded',
			'data' => (object) array( 'object' => (object) array( 'metadata' => (object) array() ) ),
		) ) );
	}
	public function test_partial_dashboard_refund_records_money_and_keeps_passes(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$event                         = $this->event( 'charge.refunded' );
		$event->data->object->refunded = false;
		$event->data->object->refunds->data[0]->amount = 500;
		$GLOBALS['test_owner_calls']                   = array();
		$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
		$routes = array_column( $GLOBALS['test_owner_calls'], 'route' );
		$this->assertCount( 1, $routes );
		$this->assertStringContainsString( 'record-checkout-ticket-sales', $routes[0] );
		$input = $GLOBALS['test_owner_calls'][0]['args']['body']['input'];
		$this->assertSame( -500, $input['gross_minor'] );
		$this->assertSame( 0, $input['tickets_sold'] );
		$this->assertSame( 'issued', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] );
	}
	private function dispute() {
		return (object) array(
			'type'    => 'charge.dispute.created',
			'account' => 'acct_venue',
			'data'    => (object) array(
				'object' => (object) array(
					'id'             => 'dp_ticket',
					'payment_intent' => 'pi_ticket',
					'metadata'       => (object) array(),
				),
			),
		);
	}
	public function test_dispute_revokes_passes_once_and_blocks_refunds(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$GLOBALS['test_owner_calls'] = array();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->dispute() ) );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->dispute() ) );
		$this->assertCount( 1, $GLOBALS['test_owner_calls'] );
		$this->assertStringContainsString( 'revoke-ticket-passes', $GLOBALS['test_owner_calls'][0]['route'] );
		$this->assertSame( 'dispute', $GLOBALS['test_owner_calls'][0]['args']['body']['input']['reason'] );
		$this->assertSame( 'disputed', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] );
		$this->assertSame( 'dp_ticket', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['dispute'] );
		$this->assertSame( 'ticket_disputed', extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
	}
	public function test_failed_dispute_revoke_is_retried(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$GLOBALS['test_owner_callback'] = static fn() => new WP_Error( 'owner_runtime_unavailable', 'Events unreachable.' );
		$this->assertSame( 'owner_runtime_unavailable', extrachill_shop_route_webhook_event( $this->dispute() )->get_error_code() );
		$this->assertSame( 'issued', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] );
	}
	private function closed_dispute( string $status ) {
		$closed                       = $this->dispute();
		$closed->type                 = 'charge.dispute.closed';
		$closed->data->object->status = $status;
		$closed->data->object->amount = 2297;
		return $closed;
	}
	private function disputed_order(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		extrachill_shop_route_webhook_event( $this->dispute() );
		$GLOBALS['test_owner_calls'] = array();
	}
	private function routes(): array {
		return array_map( static fn( $call ) => basename( dirname( $call['route'] ) ), $GLOBALS['test_owner_calls'] );
	}
	public function test_won_ticket_dispute_restores_passes_and_unblocks_refunds(): void {
		$this->disputed_order();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'won' ) ) );
		$this->assertSame( array( 'restore-ticket-passes', 'notify-commerce-seller' ), $this->routes() );
		$restore = $GLOBALS['test_owner_calls'][0]['args']['body']['input'];
		$this->assertSame(
			array(
				'order_reference' => 'cs_ticket',
				'reason'          => 'dispute',
				'email'           => 'buyer@example.com',
			),
			$restore,
			'Only dispute-cancelled passes come back, emailed to the buyer.'
		);
		$seller = $GLOBALS['test_owner_calls'][1]['args']['body']['input'];
		$this->assertSame( 9911, $seller['seller']['id'] );
		$this->assertSame( 'venue', $seller['seller']['type'] );
		$this->assertStringContainsString( 'settled in your favor', $seller['subject'] );
		$this->assertStringContainsString( 'passes work again', $seller['body_html'] );

		$order = $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket'];
		$this->assertSame( 'issued', $order['status'] );
		$this->assertSame( 'won', $order['dispute_outcome'] );
		$this->assertCount( 1, $order['notes'] );
		$this->assertStringContainsString( '2 pass(es) cancelled by the dispute work again', $order['notes'][0]['note'] );

		// Replay: no second restore, so no second buyer email, and no second note.
		$GLOBALS['test_owner_calls'] = array();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'won' ) ) );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
		$this->assertCount( 1, $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['notes'] );

		// A late `created` for the same, now closed, dispute does not cancel them again.
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->dispute() ) );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
		$this->assertSame( 'issued', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] );

		// Refunds work again.
		$this->assertSame( array( 'refunded' => true ), extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) ) );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
	}
	public function test_warning_closed_inquiry_counts_as_won(): void {
		$this->disputed_order();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'warning_closed' ) ) );
		$this->assertSame( 'restore-ticket-passes', $this->routes()[0] );
		$this->assertSame( 'issued', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] );
	}
	public function test_won_dispute_after_check_in_restores_nothing_but_unblocks_refunds(): void {
		$this->disputed_order();
		$GLOBALS['ticket_restore'] = array( 'restored' => 0 );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'won' ) ) );
		$order = $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket'];
		$this->assertSame( 'issued', $order['status'] );
		$this->assertStringContainsString( 'No passes needed restoring', $order['notes'][0]['note'] );
		$this->assertStringNotContainsString( 'passes work again', $GLOBALS['test_owner_calls'][1]['args']['body']['input']['body_html'] );
	}
	public function test_failed_restore_is_retried_without_recording_the_outcome(): void {
		$this->disputed_order();
		$GLOBALS['test_owner_callback'] = static fn() => new WP_Error( 'owner_runtime_unavailable', 'Events unreachable.' );
		$this->assertSame( 'owner_runtime_unavailable', extrachill_shop_route_webhook_event( $this->closed_dispute( 'won' ) )->get_error_code() );

		$order = $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket'];
		$this->assertSame( 'disputed', $order['status'] );
		$this->assertArrayNotHasKey( 'dispute_outcome', $order );
	}
	public function test_lost_ticket_dispute_keeps_passes_cancelled_and_records_the_returned_money(): void {
		$this->disputed_order();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'lost' ) ) );
		$this->assertSame( array( 'record-checkout-ticket-sales', 'notify-commerce-seller' ), $this->routes() );
		$input = $GLOBALS['test_owner_calls'][0]['args']['body']['input'];
		$this->assertSame( 'dp_ticket', $input['external_report_id'] );
		$this->assertSame( -2297, $input['gross_minor'] );
		$this->assertSame( -2, $input['tickets_sold'] );
		$this->assertSame( 0, $input['fees_minor'], 'The platform fee is not returned on a lost dispute.' );
		$this->assertStringContainsString( 'dispute', $GLOBALS['test_owner_calls'][1]['args']['body']['input']['subject'] );

		$order = $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket'];
		$this->assertSame( 'lost', $order['dispute_outcome'] );
		$this->assertSame( 'disputed', $order['status'] );
		$this->assertStringContainsString( 'passes stay cancelled', $order['notes'][0]['note'] );
		$this->assertSame( 'ticket_disputed', extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );

		$GLOBALS['test_owner_calls'] = array();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'lost' ) ) );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
	}
	public function test_show_refund_refunds_open_orders_and_counts_check_ins(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$GLOBALS['test_options']['extrachill_ticket_order_cs_other_show'] = array(
			'event'  => 202,
			'status' => 'issued',
			'seller' => array(
				'type' => 'venue',
				'id'   => 9911,
			),
		);
		$GLOBALS['test_options']['extrachill_ticket_order_cs_abandoned']  = array(
			'event'  => 101,
			'status' => 'pending',
			'seller' => array(
				'type' => 'venue',
				'id'   => 9911,
			),
		);
		$this->assertSame( array(
			'refunded'   => 1,
			'checked_in' => 0,
			'failed'     => 0,
		), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) ) );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
		// Running it again finds nothing left to refund.
		$this->assertSame( array(
			'refunded'   => 0,
			'checked_in' => 0,
			'failed'     => 0,
		), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) ) );
		$GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] = 'issued';
		$GLOBALS['ticket_redeemed'] = true;
		$this->assertSame( array(
			'refunded'   => 0,
			'checked_in' => 1,
			'failed'     => 0,
		), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) ) );
	}
	public function test_show_refund_requires_seller_finance_authority(): void {
		// A seller no earlier test resolved, so the per-request seller cache is cold.
		$GLOBALS['ticket_offer']['seller'] = array(
			'type' => 'venue',
			'id'   => 9922,
		);
		$callback                          = $GLOBALS['test_owner_callback'];
		$GLOBALS['test_owner_callback']    = static function ( $site, $method, $route, $args ) use ( $callback ) {
			if ( str_contains( $route, 'get-commerce-seller' ) ) {
				return array(
					'type'                => 'venue',
					'id'                  => 9922,
					'can_manage_finances' => false,
				); }
			return $callback( $site, $method, $route, $args );
		};
		$this->assertSame( 'cannot_manage_seller', extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
	}

	public function test_checkout_without_terms_is_rejected_before_stripe(): void {
		foreach ( array( array( 'terms_accepted' => false ), array( 'terms_accepted' => 'yes' ) ) as $overrides ) {
			$error = $this->checkout( $overrides );
			$this->assertSame( 'terms_required', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
			$this->assertSame( 'https://extrachill.com/terms-of-service/', $error->get_error_data()['terms_url'] );
		}
		$input = array(
			'event'    => 101,
			'quantity' => 2,
			'name'     => 'Buyer',
			'email'    => 'buyer@example.com',
		);
		$this->assertSame( 'terms_required', extrachill_shop_start_ticket_checkout( $input )->get_error_code() );
		$this->assertNull( $GLOBALS['ticket_session_request'] );
		$this->assertArrayNotHasKey( 'extrachill_ticket_order_cs_ticket', $GLOBALS['test_options'] );
	}
	public function test_checkout_records_terms_version_and_buyer(): void {
		$this->checkout();
		$order = $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket'];
		$this->assertSame( '2026-11-14', $order['terms_version'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $order['terms_accepted_at'] );
		$this->assertSame( 551, $order['buyer_user_id'] );
		$this->assertSame( 'buyer@example.com', $order['buyer_email'] );
		$this->assertSame( 'Lo-Fi show', $order['event_title'] );
		$metadata = $GLOBALS['ticket_session_request'][0]['metadata'];
		$this->assertSame( '2026-11-14', $metadata['terms_version'] );
		$this->assertSame( $order['terms_accepted_at'], $metadata['terms_accepted_at'] );
		$this->assertSame( '551', $metadata['buyer_user_id'] );
		$this->assertSame( array( 'cs_ticket' ), get_user_meta( 551, EXTRACHILL_SHOP_BUYER_TICKET_ORDER_META, false ) );
	}
	public function test_guest_checkout_records_no_buyer(): void {
		$GLOBALS['test_user_id'] = 0;
		$this->checkout();
		$this->assertSame( 0, $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['buyer_user_id'] );
		$this->assertArrayNotHasKey( 'buyer_user_id', $GLOBALS['ticket_session_request'][0]['metadata'] );
		$this->assertSame( array(), $GLOBALS['test_user_meta'] );
	}
	public function test_account_tab_lists_the_buyers_paid_tickets(): void {
		$this->checkout();
		$GLOBALS['ticket_passes'] = array(
			array(
				'code'   => 'ABC234',
				'status' => 'active',
				'url'    => 'https://events.extrachill.com/show/guest-list/?pass=ABC234',
			),
			array(
				'code'   => 'DEF567',
				'status' => 'redeemed',
			),
		);
		// Unpaid Sessions stay off the list.
		$this->assertSame( array(), extrachill_shop_list_my_ticket_orders()['orders'] );
		extrachill_shop_route_webhook_event( $this->event() );
		$orders = extrachill_shop_list_my_ticket_orders()['orders'];
		$this->assertCount( 1, $orders );
		$this->assertSame( 'cs_ticket', $orders[0]['reference'] );
		$this->assertSame( 'issued', $orders[0]['status'] );
		$this->assertSame( 2, $orders[0]['quantity'] );
		$this->assertSame( 'ABC234', $orders[0]['passes'][0]['code'] );
		$this->assertSame( '', $orders[0]['passes'][1]['url'] );
		$html = extrachill_shop_account_tickets_html( $orders );
		$this->assertStringContainsString( 'Lo-Fi show', $html );
		$this->assertStringContainsString( 'Confirmed', $html );
		$this->assertStringContainsString( 'href="https://events.extrachill.com/show/guest-list/?pass=ABC234"', $html );
		$this->assertStringContainsString( 'DEF567 · Checked in', $html );
		$this->assertSame( 1, substr_count( $html, 'Open pass' ), 'Only active passes link out.' );
		unset( $GLOBALS['ticket_passes'] );
	}
	public function test_buyer_cannot_see_another_buyers_tickets(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$GLOBALS['test_user_id'] = 552;
		$this->assertSame( array(), extrachill_shop_list_my_ticket_orders()['orders'] );
		// Even a reference planted in another user's index is re-checked against the order's buyer.
		add_user_meta( 552, EXTRACHILL_SHOP_BUYER_TICKET_ORDER_META, 'cs_ticket' );
		$this->assertSame( array(), extrachill_shop_list_my_ticket_orders()['orders'] );
		$this->assertSame( array(), extrachill_shop_get_buyer_ticket_orders( 0 ) );
		$this->assertStringContainsString( 'no ticket orders yet', extrachill_shop_account_tickets_html( array() ) );
	}
	public function test_account_menu_and_endpoint_registration(): void {
		$items = extrachill_shop_tickets_menu_item( array(
			'dashboard' => 'Dashboard',
			'orders'    => 'Orders',
			'downloads' => 'Downloads',
		) );
		$this->assertSame( array( 'dashboard', 'orders', 'tickets', 'downloads' ), array_keys( $items ) );
		$this->assertSame( 'Tickets', $items['tickets'] );
		$this->assertSame( 'tickets', extrachill_shop_tickets_query_var( array() )['tickets'] );
		extrachill_shop_register_ticket_abilities();
		$ability = $GLOBALS['test_abilities']['extrachill/list-my-ticket-orders'];
		$this->assertSame( 'is_user_logged_in', $ability['permission_callback'] );
		$this->assertSame( 'boolean', $GLOBALS['test_abilities']['extrachill/start-ticket-checkout']['input_schema']['properties']['terms_accepted']['type'] );
	}
}
