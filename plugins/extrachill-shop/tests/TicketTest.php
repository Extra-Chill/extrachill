<?php
namespace Stripe\Checkout {
	class Session {
		public $id;
		public $status;
		public static function create( $params, $options ) {
			$GLOBALS['ticket_session_request'] = array( $params, $options );
			$GLOBALS['stripe_sessions_created'][] = array( $params, $options );
			$id = empty( $GLOBALS['stripe_session_ids'] ) ? 'cs_ticket' : array_shift( $GLOBALS['stripe_session_ids'] );
			return (object) array( 'id' => $id, 'url' => 'https://checkout.stripe.com/test' );
		}
		public static function retrieve( $id, $options = null ) {
			if ( ! empty( $GLOBALS['stripe_session_retrieve_fail'] ) ) { throw new \RuntimeException( 'Stripe unavailable' ); }
			$session         = new self();
			$session->id     = $id;
			$session->status = $GLOBALS['stripe_session_status'][ $id ] ?? 'open';
			$GLOBALS['stripe_session_retrieved'][] = array( $id, $options );
			return $session;
		}
		public function expire( $params = null, $options = null ) {
			$GLOBALS['stripe_session_expired'][] = array( $this->id, $options );
			$this->status = 'expired';
			return $this;
		}
	}
}
namespace Stripe {
	class Refund {
		public static function create( $params, $options ) {
			if ( ! empty( $GLOBALS['ticket_refund_fail'] ) ) { throw new \RuntimeException( 'Stripe unavailable' ); }
			$GLOBALS['ticket_refunds'][] = array( $params, $options );
			return (object) array( 'id' => 're_ticket', 'amount' => 2000, 'created' => 1700000000, 'metadata' => (object) $params['metadata'] );
		}
	}
}
namespace {
	function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
	function extrachill_shop_stripe_init() { return true; }
	function do_action( ...$args ) { $GLOBALS['ticket_actions'][] = $args; }
	require_once dirname( __DIR__ ) . '/inc/core/ticket-service-authority.php';
	require_once dirname( __DIR__ ) . '/inc/stripe/tickets.php';
	require_once dirname( __DIR__ ) . '/inc/stripe/webhooks.php';
	// Ticket orders are option rows; the show-wide refund scans them by prefix.
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
			$GLOBALS['test_options'] = array( 'extrachill_shop_stripe_seller_venue:9911' => array( 'account_id' => 'acct_venue', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00' ) );
			$GLOBALS['test_user_id'] = 551;
			$GLOBALS['test_site_options'] = $GLOBALS['test_blog_options'] = array();
			$GLOBALS['ticket_refunds'] = array();
			$GLOBALS['ticket_refund_fail'] = false;
			$GLOBALS['test_owner_calls'] = array();
			$GLOBALS['ticket_offer'] = array( 'event_id' => 101, 'title' => 'Lo-Fi show', 'url' => 'https://events.extrachill.com/show', 'enabled' => true, 'sale_open' => true, 'remaining' => null, 'price_minor' => 1000, 'currency' => 'usd', 'seller' => array( 'type' => 'venue', 'id' => 9911 ) );
			$GLOBALS['ticket_issue_status'] = 'issued';
			$GLOBALS['ticket_redeemed'] = false;
			$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route, $args ) {
				if ( str_contains( $route, 'get-event-ticket-offer' ) ) { return $GLOBALS['ticket_offer']; }
				if ( str_contains( $route, 'get-commerce-seller' ) ) { return array( 'type' => 'venue', 'id' => 9911, 'can_manage_finances' => true ); }
				if ( str_contains( $route, 'issue-ticket-passes' ) ) { return array( 'status' => $GLOBALS['ticket_issue_status'], 'passes' => array() ); }
				if ( str_contains( $route, 'get-ticket-order-status' ) ) { return array( 'event_id' => 101, 'passes' => array(), 'any_redeemed' => $GLOBALS['ticket_redeemed'], 'refundable' => ! $GLOBALS['ticket_redeemed'] ); }
				if ( str_contains( $route, 'record-checkout-ticket-sales' ) ) { return array( 'recorded' => true ); }
				if ( $GLOBALS['ticket_redeemed'] ) { return new WP_Error( 'ticket_already_redeemed', 'Already redeemed.' ); }
				return array( 'revoked' => true );
			};
		}
		private function checkout() { return extrachill_shop_start_ticket_checkout( array( 'event' => 101, 'quantity' => 2, 'name' => 'Buyer', 'email' => 'buyer@example.com' ) ); }
		private function event( $type = 'checkout.session.completed' ) {
			if ( 'charge.refunded' === $type ) {
				// Real charges carry no PaymentIntent metadata.
				return (object) array( 'type' => $type, 'account' => 'acct_venue', 'data' => (object) array( 'object' => (object) array( 'refunds' => (object) array( 'has_more' => false, 'data' => array( (object) array( 'id' => 're_ticket', 'amount' => 2000, 'created' => 1700000000, 'status' => 'succeeded', 'metadata' => (object) array() ) ) ), 'id' => 'ch_ticket', 'refunded' => true, 'payment_intent' => 'pi_ticket', 'metadata' => (object) array() ) ) );
			}
			return (object) array( 'type' => $type, 'account' => 'acct_venue', 'data' => (object) array( 'object' => (object) array( 'id' => 'cs_ticket', 'payment_status' => 'paid', 'payment_intent' => 'pi_ticket', 'customer_email' => 'buyer@example.com', 'metadata' => (object) array( 'kind' => 'extrachill_ticket', 'quantity' => 2, 'name' => 'Buyer' ) ) ) );
		}
		public function test_checkout_and_refund_observations(): void {
			$this->checkout();
			extrachill_shop_route_webhook_event( $this->event() );
			extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) );
			$calls = array_values( array_filter( $GLOBALS['test_owner_calls'], static fn( $call ) => str_contains( $call['route'], 'record-checkout-ticket-sales' ) ) );
			$this->assertCount( 2, $calls );
			$this->assertSame( 'cs_ticket', $calls[0]['args']['body']['input']['external_report_id'] );
			$this->assertSame( 2000, $calls[0]['args']['body']['input']['gross_minor'] );
			$this->assertSame( 're_ticket', $calls[1]['args']['body']['input']['external_report_id'] );
			$this->assertSame( -2000, $calls[1]['args']['body']['input']['gross_minor'] );
			$this->assertSame( -2, $calls[1]['args']['body']['input']['tickets_sold'] );
		}

		public function test_fee_math_and_defaults(): void {
			$seller = array( 'type' => 'venue', 'id' => 9911 );
			$this->assertSame( array( 'buyer_fee' => 0, 'application_fee' => 0 ), extrachill_commerce_fee( 'extrachill_ticket', 2000, $seller ) );
			$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'ticket_buyer_fee_flat_minor' => 100, 'ticket_buyer_fee_percent' => 3, 'ticket_percent' => 5 );
			$this->assertSame( array( 'buyer_fee' => 160, 'application_fee' => 260 ), extrachill_commerce_fee( 'extrachill_ticket', 2000, $seller ) );
			$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'ticket_buyer_fee_percent' => 3.3, 'ticket_percent' => 3.3 );
			$this->assertSame( array( 'buyer_fee' => 33, 'application_fee' => 66 ), extrachill_commerce_fee( 'extrachill_ticket', 999, $seller ) );
		}
		public function test_direct_charge_session(): void {
			$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'ticket_buyer_fee_flat_minor' => 100, 'ticket_buyer_fee_percent' => 3, 'ticket_percent' => 5 );
			$this->assertSame( 'cs_ticket', $this->checkout()['session_id'] );
			list( $params, $opts ) = $GLOBALS['ticket_session_request'];
			$this->assertSame( 'acct_venue', $opts['stripe_account'] );
			$this->assertSame( 260, $params['payment_intent_data']['application_fee_amount'] );
			$this->assertSame( 'Service fee', $params['line_items'][1]['price_data']['product_data']['name'] );
			$this->assertSame( 160, $params['line_items'][1]['price_data']['unit_amount'] );
			$this->assertSame( 'extrachill_ticket', $params['payment_intent_data']['metadata']['kind'] );
			$this->assertStringContainsString( 'session_id={CHECKOUT_SESSION_ID}', $params['success_url'] );
			$this->assertEqualsWithDelta( time() + 1800, $params['expires_at'], 2 );
			$this->assertArrayNotHasKey( 'automatic_tax', $params );
		}
		public function test_zero_fees_and_refusals(): void {
			$this->checkout();
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
			$event = $this->event();
			$event->account = 'acct_other';
			$this->assertSame( 'ticket_account_mismatch', extrachill_shop_route_webhook_event( $event )->get_error_code() );
			$event = $this->event();
			$event->data->object->payment_status = 'unpaid';
			$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
			$this->assertSame( 'pending', $GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] );
		}
		public function test_source_grants_are_bounded(): void {
			add_filter( 'extrachill_shop_ticket_service_assertion_keys', static function () { return array( 'test' => 'secret' ); } );
			add_filter( 'extrachill_shop_ticket_service_assertion_active_key_id', static function () { return 'test'; } );
			$grants = extrachill_shop_register_ticket_source_grants( array() );
			$this->assertCount( 5, $grants );
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
			$this->assertTrue( extrachill_shop_route_webhook_event( (object) array( 'type' => 'payment_intent.succeeded', 'data' => (object) array( 'object' => (object) array( 'metadata' => (object) array() ) ) ) ) );
		}
			public function test_partial_dashboard_refund_records_money_and_keeps_passes(): void {
			$this->checkout();
			extrachill_shop_route_webhook_event( $this->event() );
			$event = $this->event( 'charge.refunded' );
			$event->data->object->refunded = false;
			$event->data->object->refunds->data[0]->amount = 500;
			$GLOBALS['test_owner_calls'] = array();
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
			return (object) array( 'type' => 'charge.dispute.created', 'account' => 'acct_venue', 'data' => (object) array( 'object' => (object) array( 'id' => 'dp_ticket', 'payment_intent' => 'pi_ticket', 'metadata' => (object) array() ) ) );
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
		public function test_show_refund_refunds_open_orders_and_counts_check_ins(): void {
			$this->checkout();
			extrachill_shop_route_webhook_event( $this->event() );
			$GLOBALS['test_options']['extrachill_ticket_order_cs_other_show'] = array( 'event' => 202, 'status' => 'issued', 'seller' => array( 'type' => 'venue', 'id' => 9911 ) );
			$GLOBALS['test_options']['extrachill_ticket_order_cs_abandoned'] = array( 'event' => 101, 'status' => 'pending', 'seller' => array( 'type' => 'venue', 'id' => 9911 ) );
			$this->assertSame( array( 'refunded' => 1, 'checked_in' => 0, 'failed' => 0 ), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) ) );
			$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
			// Running it again finds nothing left to refund.
			$this->assertSame( array( 'refunded' => 0, 'checked_in' => 0, 'failed' => 0 ), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) ) );
			$GLOBALS['test_options']['extrachill_ticket_order_cs_ticket']['status'] = 'issued';
			$GLOBALS['ticket_redeemed'] = true;
			$this->assertSame( array( 'refunded' => 0, 'checked_in' => 1, 'failed' => 0 ), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) ) );
		}
		public function test_show_refund_requires_seller_finance_authority(): void {
			// A seller no earlier test resolved, so the per-request seller cache is cold.
			$GLOBALS['ticket_offer']['seller'] = array( 'type' => 'venue', 'id' => 9922 );
			$callback = $GLOBALS['test_owner_callback'];
			$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route, $args ) use ( $callback ) {
				if ( str_contains( $route, 'get-commerce-seller' ) ) { return array( 'type' => 'venue', 'id' => 9922, 'can_manage_finances' => false ); }
				return $callback( $site, $method, $route, $args );
			};
			$this->assertSame( 'cannot_manage_seller', extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) )->get_error_code() );
			$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
		}
	}
}
