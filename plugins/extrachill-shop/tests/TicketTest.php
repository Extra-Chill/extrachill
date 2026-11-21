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
// Ticket sales are WooCommerce orders; CI has no WooCommerce, so they are doubles.
require_once __DIR__ . '/fixtures/ticket-wc-orders.php';
require_once dirname( __DIR__ ) . '/inc/core/order-seller-index.php';
require_once dirname( __DIR__ ) . '/inc/stripe/checkout-handler.php';
require_once dirname( __DIR__ ) . '/inc/core/ticket-reconciliation.php';

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
		foreach ( $GLOBALS['test_recurring_actions'] ?? array() as $action ) {
			if ( $hook === $action[2] && $group === $action[4] ) {
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '' ) {
		$GLOBALS['test_recurring_actions'][] = array( $timestamp, $interval, $hook, $args, $group );
		return count( $GLOBALS['test_recurring_actions'] );
	}
}

final class TicketTest extends \PHPUnit\Framework\TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['test_wc_get_orders_callback'] );
	}
	protected function setUp(): void {
		extrachill_shop_reset_seller_permission_cache();
		$GLOBALS['test_orders']                 = array();
		$GLOBALS['test_wc_refunds']             = array();
		$GLOBALS['test_wc_refund_objects']      = array();
		$GLOBALS['ticket_order_queries']        = array();
		$GLOBALS['test_wc_create_order_fail']   = false;
		$GLOBALS['test_wc_get_orders_callback'] = 'ticket_test_wc_get_orders';
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
		$GLOBALS['test_options']           = array(
			'extrachill_shop_stripe_seller_venue:9911' => array(
				'account_id'       => 'acct_venue',
				'status'           => 'active',
				'status_synced_at' => '2026-11-01T00:00:00+00:00',
				// The venue pays processing (a Standard account): #1553's math.
				'fee_payer'        => 'account',
			),
		);
		$GLOBALS['test_transients']          = array();
		$GLOBALS['test_stripe_status']       = null;
		$GLOBALS['test_stripe_status_calls'] = array();
		$GLOBALS['test_user_id']           = 551;
		$GLOBALS['test_site_options']      = array();
		$GLOBALS['test_blog_options']      = array();
		$GLOBALS['test_post_meta']         = array();
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
		$GLOBALS['ticket_emails_failed']   = 0;
		$GLOBALS['ticket_resend']          = array(
			'emailed' => 2,
			'failed'  => 0,
		);
		$GLOBALS['stripe_session_data']      = array();
		$GLOBALS['stripe_session_status']    = array();
		$GLOBALS['stripe_session_retrieved'] = array();
		$GLOBALS['stripe_session_retrieve_fail'] = false;
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
					'status'        => $GLOBALS['ticket_issue_status'],
					'passes'        => array(),
					'emails_failed' => $GLOBALS['ticket_emails_failed'],
				); }
			if ( str_contains( $route, 'resend-ticket-pass-emails' ) ) {
				return $GLOBALS['ticket_resend']; }
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
	/** The ticket order with this reference. */
	private function order( string $reference = 'cs_ticket' ): TicketTestOrder {
		$order = extrachill_shop_get_ticket_order( $reference );
		$this->assertInstanceOf( TicketTestOrder::class, $order, "Ticket order $reference" );
		return $order;
	}
	/** Mark an order paid as fulfilment would, without the webhook. */
	private function mark_paid( string $reference, string $intent ): void {
		$order = $this->order( $reference );
		$order->update_meta_data( '_extrachill_payment_intent', $intent );
		$order->status = 'completed';
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
						'order_id' => (string) ( extrachill_shop_get_ticket_order( 'cs_ticket' )?->get_id() ?? 0 ),
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

	/** @return array<string,array{int,int,string,int,int}> */
	public static function payer_orders(): array {
		// price, quantity, Stripe controller.fees.payer, service fee, application fee.
		return array(
			'account pays, $20 x 1'      => array( 2000, 1, 'account', 194, 100 ),
			'account pays, $20 x 4'      => array( 2000, 4, 'account', 682, 400 ),
			'Express pays, $20 x 1'      => array( 2000, 1, 'application_express', 194, 194 ),
			'Express pays, $20 x 4'      => array( 2000, 4, 'application_express', 682, 682 ),
			'application pays, $20 x 1'  => array( 2000, 1, 'application', 194, 194 ),
			'application pays, $20 x 4'  => array( 2000, 4, 'application', 682, 682 ),
		);
	}

	/**
	 * The buyer pays the same either way; the application fee follows who
	 * Stripe debits for processing, so the venue nets the subtotal and Extra
	 * Chill about $1 per ticket in both cases.
	 *
	 * @dataProvider payer_orders
	 */
	public function test_application_fee_follows_the_processing_fee_payer( int $price, int $quantity, string $payer, int $service_fee, int $application_fee ): void {
		$subtotal = $price * $quantity;
		$fees     = extrachill_commerce_fee( 'extrachill_ticket', $subtotal, array( 'type' => 'venue', 'id' => 9911 ), $quantity, $payer );
		$this->assertSame( array(
			'buyer_fee'       => $service_fee,
			'application_fee' => $application_fee,
		), $fees );
		$total = $subtotal + $fees['buyer_fee'];
		// What Stripe takes (2.9% + 30¢, nearest cent) and from whom.
		$processing = (int) round( $total * 0.029 ) + 30;
		if ( 'account' === $payer ) {
			$venue_net    = $total - $processing - $fees['application_fee'];
			$platform_net = $fees['application_fee'];
		} else {
			$venue_net    = $total - $fees['application_fee'];
			$platform_net = $fees['application_fee'] - $processing;
		}
		$this->assertSame( $subtotal, $venue_net, 'Venue nets exactly the subtotal.' );
		$this->assertSame( 100 * $quantity, $platform_net, 'Extra Chill nets $1 per ticket.' );
	}

	public function test_unknown_payer_uses_the_venue_safe_account_math(): void {
		$seller  = array( 'type' => 'venue', 'id' => 9911 );
		$account = extrachill_commerce_fee( 'extrachill_ticket', 8000, $seller, 4, 'account' );
		foreach ( array( '', 'something_new' ) as $payer ) {
			$this->assertSame( 'account', extrachill_commerce_processing_payer( $payer ) );
			$this->assertSame( $account, extrachill_commerce_fee( 'extrachill_ticket', 8000, $seller, 4, $payer ), "payer '$payer'" );
		}
		$this->assertSame( $account, extrachill_commerce_fee( 'extrachill_ticket', 8000, $seller, 4 ) );
		foreach ( array( 'application', 'application_express', 'application_custom' ) as $payer ) {
			$this->assertSame( 'platform', extrachill_commerce_processing_payer( $payer ) );
		}
		// Whoever really pays, the venue never nets less than the subtotal.
		$total      = 8000 + $account['buyer_fee'];
		$processing = (int) round( $total * 0.029 ) + 30;
		$this->assertSame( 8000, $total - $processing - $account['application_fee'] );
		$this->assertGreaterThanOrEqual( 8000, $total - $account['application_fee'] );
	}

	public function test_seller_fee_is_still_taken_from_the_venue_when_the_platform_pays(): void {
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'ticket_percent' => 5 );
		$fees = extrachill_commerce_fee( 'extrachill_ticket', 2000, array( 'type' => 'venue', 'id' => 9911 ), 1, 'application_express' );
		$this->assertSame( 2000 - 100, 2000 + $fees['buyer_fee'] - $fees['application_fee'] );
	}

	public function test_express_checkout_charges_the_full_service_fee_as_application_fee(): void {
		$GLOBALS['test_options']['extrachill_shop_stripe_seller_venue:9911']['fee_payer'] = 'application_express';
		$this->assertSame( 'cs_ticket', $this->checkout()['session_id'] );
		list( $params ) = $GLOBALS['ticket_session_request'];
		// The buyer pays the same $22.97; the whole $2.97 Service fee is the application fee.
		$this->assertSame( 297, $params['line_items'][1]['price_data']['unit_amount'] );
		$this->assertSame( 297, $params['payment_intent_data']['application_fee_amount'] );
		$amounts = extrachill_shop_ticket_order_amounts( $this->order() );
		$this->assertSame( 2297, $amounts['total_minor'] );
		$this->assertSame( 297, $amounts['application_fee_minor'] );
		$this->assertSame( 'platform', $amounts['processing_payer'] );
		$this->assertSame( 'application_express', $this->order()->get_meta( '_extrachill_fee_payer' ) );
		$this->assertSame( array(), $GLOBALS['test_stripe_status_calls'], 'A stored payer needs no Stripe call.' );
		// A full refund returns the application fee actually charged.
		extrachill_shop_route_webhook_event( $this->event() );
		$this->assertSame( array( 'refunded' => true ), extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) ) );
		list( $refund ) = $GLOBALS['ticket_refunds'][0];
		$this->assertArrayNotHasKey( 'amount', $refund );
		$this->assertTrue( $refund['refund_application_fee'] );
		$this->assertSame( '297', $refund['metadata']['application_fee_minor'] );
	}

	public function test_unknown_payer_is_looked_up_once_and_cached(): void {
		unset( $GLOBALS['test_options']['extrachill_shop_stripe_seller_venue:9911']['fee_payer'] );
		$GLOBALS['test_stripe_status'] = array(
			'success'   => true,
			'fee_payer' => 'application_express',
		);
		$this->checkout();
		$this->assertSame( 297, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( 'application_express', extrachill_shop_get_seller_stripe_account_record( array( 'type' => 'venue', 'id' => 9911 ) )['fee_payer'] );
		// The status Stripe reported earlier is kept.
		$this->assertSame( 'active', extrachill_shop_get_seller_stripe_account_record( array( 'type' => 'venue', 'id' => 9911 ) )['status'] );
		$this->checkout();
		$this->assertSame( array( 'acct_venue' ), $GLOBALS['test_stripe_status_calls'] );
	}

	public function test_failed_payer_lookup_falls_back_to_the_venue_safe_fee(): void {
		unset( $GLOBALS['test_options']['extrachill_shop_stripe_seller_venue:9911']['fee_payer'] );
		$GLOBALS['test_stripe_status'] = array(
			'success' => false,
			'error'   => 'timeout',
		);
		$this->checkout();
		$this->assertSame( 200, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( 'account', extrachill_shop_ticket_order_amounts( $this->order() )['processing_payer'] );
		// The miss is remembered briefly: the next checkout does not ask again.
		$this->checkout();
		$this->assertSame( array( 'acct_venue' ), $GLOBALS['test_stripe_status_calls'] );
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
		$estimate = extrachill_commerce_processing_estimate( $total, ec_commerce_fee_settings() );
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
				$this->assertSame( $subtotal, $total - extrachill_commerce_processing_estimate( $total, $settings ) - $fees['application_fee'], "$price x $quantity" );
				$this->assertGreaterThanOrEqual( 0, $fees['application_fee'] );
			}
		}
		// A processing estimate with no buyer fee still keeps the venue whole.
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'ticket_buyer_fee_per_ticket_minor' => 0 );
		$fees = extrachill_commerce_fee( 'extrachill_ticket', 1000, array( 'type' => 'venue', 'id' => 9911 ), 1 );
		$this->assertGreaterThanOrEqual( 0, $fees['application_fee'] );
		$this->assertSame( 1000, 1000 + $fees['buyer_fee'] - extrachill_commerce_processing_estimate( 1000 + $fees['buyer_fee'], ec_commerce_fee_settings() ) - $fees['application_fee'] );
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
		// The WooCommerce order keeps the split.
		$order = $this->order();
		$this->assertSame( array(
			'subtotal_minor'        => 2000,
			'service_fee_minor'     => 297,
			'total_minor'           => 2297,
			'application_fee_minor' => 200,
			'currency'              => 'usd',
			'processing_payer'      => 'account',
		), extrachill_shop_ticket_order_amounts( $order ) );
		$this->assertSame( (string) $order->get_id(), $params['metadata']['order_id'] );
	}

	public function test_checkout_creates_a_pending_woocommerce_order_without_products(): void {
		$this->checkout();
		$order = $this->order();
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'extrachill_ticket', $order->created_via );
		$this->assertTrue( extrachill_shop_order_is_ticket( $order ) );
		// One product-less ticket line: quantity = tickets, total = subtotal.
		$lines = $order->get_items( 'line_item' );
		$this->assertCount( 1, $lines );
		$this->assertSame( 'Event tickets: Lo-Fi show', $lines[0]->get_name() );
		$this->assertSame( 0, $lines[0]->get_product_id() );
		$this->assertSame( 2, $lines[0]->get_quantity() );
		$this->assertSame( 20.0, $lines[0]->get_total() );
		$this->assertSame( '101', $lines[0]->get_meta( 'event_id' ) );
		$fees = $order->get_items( 'fee' );
		$this->assertCount( 1, $fees );
		$this->assertSame( 'Service fee', $fees[0]->get_name() );
		$this->assertSame( 2.97, $fees[0]->get_total() );
		$this->assertSame( 22.97, $order->get_total() );
		$this->assertSame( 'USD', $order->get_currency() );
		$this->assertSame( 'Buyer', $order->get_billing_first_name() );
		$this->assertSame( 'buyer@example.com', $order->get_billing_email() );
		$this->assertSame( EXTRACHILL_SHOP_TICKET_PAYMENT_METHOD, $order->get_payment_method() );
		// Order meta: kind, seller index, bound account, event and reference.
		$this->assertSame( 'venue:9911', $order->get_meta( '_extrachill_seller' ) );
		$this->assertSame( 'acct_venue', $order->get_meta( '_extrachill_stripe_account' ) );
		$this->assertSame( 'account', $order->get_meta( '_extrachill_fee_payer' ) );
		$this->assertSame( '101', $order->get_meta( EXTRACHILL_SHOP_TICKET_EVENT_META ) );
		$this->assertSame( 'cs_ticket', $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META ) );
		$this->assertSame( array( 'cs_ticket' => 'acct_venue' ), $order->get_meta( '_extrachill_checkout_sessions' ) );
		$this->assertSame( '', $order->get_meta( '_extrachill_payment_intent' ) );
	}

	public function test_failed_stripe_session_leaves_no_order_behind(): void {
		$GLOBALS['ticket_refund_fail'] = false;
		$GLOBALS['test_session_create_fail'] = true;
		$this->assertSame( 'ticket_checkout_failed', $this->checkout()->get_error_code() );
		unset( $GLOBALS['test_session_create_fail'] );
		$this->assertSame( array(), array_filter( $GLOBALS['test_orders'], static fn( $order ) => $order instanceof TicketTestOrder ) );
		$GLOBALS['test_wc_create_order_fail'] = true;
		$this->assertSame( 'ticket_checkout_failed', $this->checkout()->get_error_code() );
		$this->assertNull( $GLOBALS['ticket_session_request'], 'No order, no Stripe Session.' );
	}

	public function test_ticket_orders_send_no_woocommerce_emails_and_keep_stock(): void {
		$this->checkout();
		$ticket = $this->order();
		$merch  = new TicketTestOrder( 77 );
		foreach ( array( 'new_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_on_hold_order', 'cancelled_order', 'failed_order' ) as $email ) {
			$this->assertContains( 'extrachill_shop_suppress_ticket_order_email', array_column( $GLOBALS['test_filters'][ 'woocommerce_email_enabled_' . $email ] ?? array(), 'callback' ), $email );
			$this->assertFalse( apply_filters( 'woocommerce_email_enabled_' . $email, true, $ticket ), $email );
			$this->assertTrue( apply_filters( 'woocommerce_email_enabled_' . $email, true, $merch ), "Merch keeps $email" );
		}
		// Emails without an order object are untouched.
		$this->assertTrue( extrachill_shop_suppress_ticket_order_email( true, null ) );
		// No stock changes, no shipping, and out of My Account > Orders.
		$this->assertFalse( apply_filters( 'woocommerce_can_reduce_order_stock', true, $ticket ) );
		$this->assertTrue( apply_filters( 'woocommerce_can_reduce_order_stock', true, $merch ) );
		$this->assertSame( 0.0, $ticket->get_shipping_total() );
		$this->assertSame( array(), array_filter( $ticket->get_items( 'line_item' ), static fn( $item ) => $item->get_product() ) );
		$query = apply_filters( 'woocommerce_my_account_my_orders_query', array( 'customer' => 551 ) );
		$this->assertContains( extrachill_shop_not_ticket_meta_clause(), $query['meta_query'] );
		// The seller's merch notification never fires for a ticket order.
		$this->assertNull( extrachill_shop_get_order_seller_record( $ticket ) );
	}

	public function test_fulfilment_completes_the_order_and_stores_the_payment(): void {
		$this->checkout();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		$order = $this->order();
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 1, $order->paid );
		$this->assertSame( 'pi_ticket', $order->get_meta( '_extrachill_payment_intent' ) );
		$this->assertSame( 'pi_ticket', $order->transaction_id );
		$this->assertSame( 'issued', extrachill_shop_ticket_order_state( $order ) );
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
			$this->mark_paid( $reference, 'pi_' . $reference );
		}
		$this->assertSame( 4563, extrachill_shop_ticket_order_amounts( $this->order( 'cs_two' ) )['total_minor'] );
		$this->assertSame( 400, extrachill_shop_ticket_order_amounts( $this->order( 'cs_two' ) )['application_fee_minor'] );
		$counts = extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) );
		$this->assertSame( 2, $counts['refunded'] );
		$this->assertCount( 2, $GLOBALS['ticket_refunds'] );
		foreach ( $GLOBALS['ticket_refunds'] as $refund ) {
			$this->assertArrayNotHasKey( 'amount', $refund[0] );
			$this->assertTrue( $refund[0]['refund_application_fee'] );
		}
		$this->assertSame( array( '200', '400' ), array_map( static fn( $refund ) => $refund[0]['metadata']['application_fee_minor'], $GLOBALS['ticket_refunds'] ) );
		$this->assertSame( 'refunded', $this->order( 'cs_one' )->get_status() );
		// The show's orders come from an event + status query, never a scan.
		$query = current( array_filter( $GLOBALS['ticket_order_queries'], static fn( $args ) => isset( $args['status'] ) ) );
		$this->assertSame( array( 'wc-processing', 'wc-completed' ), $query['status'] );
		$this->assertContains( array( 'key' => EXTRACHILL_SHOP_TICKET_EVENT_META, 'value' => '101' ), $query['meta_query'] );
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
	public function test_checkout_charges_the_event_fee_override_and_orders_keep_their_fee(): void {
		// #1698: a zero-fee show (a memorial): no Service fee, no application fee.
		$GLOBALS['test_post_meta'][101]['_extrachill_commerce_fee_override'] = array(
			'ticket_percent'                    => 0,
			'ticket_buyer_fee_per_ticket_minor' => 0,
			'ticket_buyer_fee_flat_minor'       => 0,
			'ticket_buyer_fee_percent'          => 0,
			'processing_percent'                => 0,
			'processing_fixed_minor'            => 0,
		);
		$this->checkout();
		list( $params ) = $GLOBALS['ticket_session_request'];
		$this->assertCount( 1, $params['line_items'], 'No Service fee line.' );
		$this->assertSame( 0, $params['payment_intent_data']['application_fee_amount'] );
		$stored = array(
			'subtotal_minor'        => 2000,
			'service_fee_minor'     => 0,
			'total_minor'           => 2000,
			'application_fee_minor' => 0,
			'currency'              => 'usd',
			'processing_payer'      => 'account',
		);
		$this->assertSame( $stored, extrachill_shop_ticket_order_amounts( $this->order() ) );

		// Clearing the override changes later checkouts, never a placed order.
		unset( $GLOBALS['test_post_meta'][101] );
		$this->assertSame( $stored, extrachill_shop_ticket_order_amounts( $this->order() ) );
		$GLOBALS['stripe_session_ids'] = array( 'cs_later' );
		$this->checkout();
		$this->assertSame( 200, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( $stored, extrachill_shop_ticket_order_amounts( $this->order() ) );
		unset( $GLOBALS['stripe_session_ids'] );
	}
	public function test_checkout_applies_the_seller_override_under_the_event_override(): void {
		$GLOBALS['test_blog_options'][3]['extrachill_shop_fee_seller_venue:9911'] = array( 'ticket_percent' => 10 );
		$this->checkout();
		// The $2.00 the defaults take, plus 10% of the $20 subtotal from the venue.
		$this->assertSame( 400, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
		$GLOBALS['test_post_meta'][101]['_extrachill_commerce_fee_override'] = array( 'ticket_percent' => 0 );
		$GLOBALS['stripe_session_ids'] = array( 'cs_event' );
		$this->checkout();
		$this->assertSame( 200, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'], 'The event wins.' );
		unset( $GLOBALS['stripe_session_ids'], $GLOBALS['test_post_meta'][101] );
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
	/** @dataProvider held_pass_statuses */
	public function test_sold_out_duplicate_with_existing_passes_never_refunds( $status ): void {
		$this->checkout();
		$GLOBALS['ticket_issue_status'] = 'sold_out';
		$GLOBALS['ticket_passes'] = array( array( 'code' => 'pass', 'status' => $status ) );
		try {
			$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
			$this->assertSame( 'completed', $this->order()->get_status() );
			$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
			$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
		} finally { unset( $GLOBALS['ticket_passes'] ); }
	}
	public function held_pass_statuses(): array {
		return array( array( 'active' ), array( 'redeemed' ) );
	}
	public function test_sold_out_status_lookup_failure_retries_without_refund(): void {
		$this->checkout();
		$GLOBALS['ticket_issue_status'] = 'sold_out';
		$callback = $GLOBALS['test_owner_callback'];
		$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route ) use ( $callback ) {
			if ( str_contains( $route, 'get-ticket-order-status' ) ) {
				return new WP_Error( 'events_unavailable', 'Retry later.' );
			}
			return $callback( $site, $method, $route );
		};
		$this->assertSame( 'events_unavailable', extrachill_shop_route_webhook_event( $this->event() )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
		$this->assertSame( 'pending', $this->order()->get_status() );
	}
	public function test_sold_out_failed_refund_retry_does_not_issue_again(): void {
		$this->checkout();
		$GLOBALS['ticket_issue_status'] = 'sold_out';
		$GLOBALS['ticket_refund_fail'] = true;
		$this->assertSame( 'ticket_refund_failed', extrachill_shop_route_webhook_event( $this->event() )->get_error_code() );
		$this->assertSame( 'sold_out', $this->order()->get_meta( EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META ) );
		$GLOBALS['ticket_refund_fail'] = false;
		$GLOBALS['ticket_issue_status'] = 'issued';
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		$this->assertSame( 'refunded', $this->order()->get_status() );
		$this->assertSame( 0, $this->order()->paid );
	}
	public function test_sold_out_auto_refund_and_replay(): void {
		$this->checkout();
		$GLOBALS['ticket_issue_status'] = 'sold_out';
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		extrachill_shop_route_webhook_event( $this->event() );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
		$this->assertTrue( $GLOBALS['ticket_refunds'][0][0]['refund_application_fee'] );
		$this->assertSame( 'acct_venue', $GLOBALS['ticket_refunds'][0][1]['stripe_account'] );
		$order = $this->order();
		$this->assertSame( 'sold_out', $order->get_meta( EXTRACHILL_SHOP_TICKET_REFUND_REASON_META ) );
		// Never issued, so never paid in WooCommerce: straight from pending to refunded.
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertSame( 0, $order->paid );
		$this->assertSame( array( 22.97 ), $order->refunds );
		$this->assertSame( 're_ticket', $order->get_refunds()[0]->get_meta( EXTRACHILL_SHOP_REFUND_ID_META ) );
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
		$GLOBALS['ticket_refund_fail']         = true;
		$GLOBALS['stripe_refund_attempt_keys'] = array();
		$this->assertSame( 'ticket_refund_failed', extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) )->get_error_code() );
		$this->assertSame( 'refund_pending', extrachill_shop_ticket_order_state( $this->order() ) );
		$this->assertSame( array(), $this->order()->refunds, 'Nothing is recorded until the refund succeeds.' );
		$GLOBALS['ticket_refund_fail'] = false;
		$this->assertSame( array( 'refunded' => true ), extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) ) );
		// Stripe would replay the refusal under the first key; the retry gets its own.
		$this->assertSame( array( 'ticket-refund-cs_ticket-1', 'ticket-refund-cs_ticket-2' ), $GLOBALS['stripe_refund_attempt_keys'] );
		$this->assertSame( 'ticket-refund-cs_ticket-2', $GLOBALS['ticket_refunds'][0][1]['idempotency_key'] );
		$this->assertSame( '2', $this->order()->get_meta( EXTRACHILL_SHOP_TICKET_REFUND_ATTEMPTS_META ) );
		$this->assertSame( 'refunded', extrachill_shop_ticket_order_state( $this->order() ) );
		$this->assertSame( '', $this->order()->get_meta( EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META ) );
	}
	public function test_public_ticket_holds_ability(): void {
		extrachill_shop_register_ticket_abilities();
		$ability = $GLOBALS['test_abilities']['extrachill/get-event-ticket-holds'];
		$this->assertSame( '__return_true', $ability['permission_callback'] );
		$this->assertTrue( $ability['meta']['show_in_rest'] );
		$this->assertTrue( $ability['meta']['annotations']['readonly'] );
		$this->assertSame( array( 'event' ), $ability['input_schema']['required'] );
		$none = array( 'held' => 0, 'by_type' => array() );
		$this->assertSame( $none, call_user_func( $ability['execute_callback'], array( 'event' => 101 ) ) );
		$this->checkout();
		$this->assertSame( array( 'held' => 2, 'by_type' => array( 'general' => 2 ) ), call_user_func( $ability['execute_callback'], array( 'event' => 101 ) ) );
		$this->assertSame( $none, call_user_func( $ability['execute_callback'], array( 'event' => 202 ) ) );
		$this->order()->update_meta_data( EXTRACHILL_SHOP_TICKET_HOLD_UNTIL_META, time() - 1 );
		$this->assertSame( $none, call_user_func( $ability['execute_callback'], array( 'event' => 101 ) ) );
	}

	public function test_open_checkouts_hold_their_tickets(): void {
		$GLOBALS['ticket_offer']['remaining'] = 3;
		$GLOBALS['stripe_session_ids']        = array( 'cs_one', 'cs_two' );
		$this->assertSame( 'cs_one', $this->checkout()['session_id'] );
		$order = $this->order( 'cs_one' );
		$hold  = (int) $order->get_meta( EXTRACHILL_SHOP_TICKET_HOLD_UNTIL_META );
		// The hold ends when Stripe closes the Session.
		$this->assertSame( $GLOBALS['ticket_session_request'][0]['expires_at'], $hold );
		$this->assertEqualsWithDelta( time() + EXTRACHILL_SHOP_TICKET_HOLD_SECONDS, $hold, 5 );
		$this->assertSame( 2, extrachill_shop_held_ticket_quantity( 101 ) );
		$this->assertSame( 0, extrachill_shop_held_ticket_quantity( 101, $order->get_id() ) );
		$this->assertSame( 0, extrachill_shop_held_ticket_quantity( 202 ), 'Holds are per show.' );

		// Events still reports 3 left, but two of them are held.
		$sessions = count( $GLOBALS['stripe_sessions_created'] ?? array() );
		$error    = $this->checkout();
		$this->assertSame( 'tickets_held', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertCount( $sessions, $GLOBALS['stripe_sessions_created'] ?? array(), 'No Session for a held-out checkout.' );
		$this->assertCount( 1, array_filter( $GLOBALS['test_orders'], static fn( $o ) => $o instanceof TicketTestOrder ), 'No order left behind.' );
		// The one ticket not held can still be bought.
		$this->assertSame( 'cs_two', $this->checkout( array( 'quantity' => 1 ) )['session_id'] );
		$this->assertSame( 3, extrachill_shop_held_ticket_quantity( 101 ) );
		unset( $GLOBALS['stripe_session_ids'] );
	}

	public function test_holds_end_with_the_session_and_last_while_paid_but_unissued(): void {
		$GLOBALS['ticket_offer']['remaining'] = 2;
		$GLOBALS['stripe_session_ids']        = array( 'cs_one', 'cs_two', 'cs_three' );
		$this->checkout();
		$this->assertSame( 'tickets_held', $this->checkout()->get_error_code() );
		$order = $this->order( 'cs_one' );
		// Paid, fulfilment pending: the hold outlives the Session until passes are issued.
		$order->update_meta_data( '_extrachill_payment_intent', 'pi_one' );
		$order->update_meta_data( EXTRACHILL_SHOP_TICKET_HOLD_UNTIL_META, (string) ( time() - 1 ) );
		$this->assertSame( 2, extrachill_shop_held_ticket_quantity( 101 ) );
		// Unpaid and past its expiry: released even before the expired webhook.
		$order->delete_meta_data( '_extrachill_payment_intent' );
		$this->assertSame( 0, extrachill_shop_held_ticket_quantity( 101 ) );
		$order->update_meta_data( EXTRACHILL_SHOP_TICKET_HOLD_UNTIL_META, (string) ( time() + 600 ) );
		// An expired (cancelled) or issued order holds nothing.
		foreach ( array( 'cancelled', 'failed', 'completed', 'refunded' ) as $status ) {
			$order->status = $status;
			$this->assertSame( 0, extrachill_shop_held_ticket_quantity( 101 ), $status );
		}
		$order->status = 'cancelled';
		$this->assertSame( 'cs_two', $this->checkout()['session_id'] );
		unset( $GLOBALS['stripe_session_ids'] );
	}

	public function test_racing_checkouts_never_oversell_the_last_tickets(): void {
		$GLOBALS['ticket_offer']['remaining'] = 2;
		// Another checkout saved its hold after this one's first count.
		$GLOBALS['test_wc_get_orders_callback'] = static function ( array $args ) {
			static $racer = false;
			if ( ! $racer && isset( $args['status'] ) && array( 'wc-pending' ) === $args['status'] && ! empty( $GLOBALS['test_orders'] ) ) {
				$racer = true;
				$other = wc_create_order( array( 'status' => 'pending' ) );
				$item  = new WC_Order_Item_Product();
				$item->set_quantity( 1 );
				$other->add_item( $item );
				$other->update_meta_data( EXTRACHILL_SHOP_ORDER_KIND_META, EXTRACHILL_SHOP_TICKET_KIND );
				$other->update_meta_data( EXTRACHILL_SHOP_TICKET_EVENT_META, '101' );
				$other->update_meta_data( EXTRACHILL_SHOP_TICKET_HOLD_UNTIL_META, (string) ( time() + 600 ) );
			}
			return ticket_test_wc_get_orders( $args );
		};
		$this->assertSame( 'tickets_held', $this->checkout()->get_error_code() );
		$this->assertNull( $GLOBALS['ticket_session_request'], 'The losing checkout never reaches Stripe.' );
		$this->assertSame( 1, extrachill_shop_held_ticket_quantity( 101 ), 'Only the other checkout still holds.' );
	}

	/** A two-type offer as Events returns it, read with $code. */
	private function tiered_offer( ?array $code = null, bool $invalid = false ): void {
		$GLOBALS['ticket_offer']['ticket_types'] = array(
			array( 'id' => 'ga', 'name' => 'GA', 'price_minor' => 1000, 'unit_price_minor' => $code ? 800 : 1000, 'remaining' => null, 'code_applies' => (bool) $code ),
			array( 'id' => 'vip', 'name' => 'VIP', 'price_minor' => 3000, 'unit_price_minor' => 3000, 'remaining' => 2, 'code_applies' => false ),
		);
		$GLOBALS['ticket_offer']['code']         = $code;
		$GLOBALS['ticket_offer']['code_invalid'] = $invalid;
	}

	public function test_checkout_charges_the_chosen_type(): void {
		$this->tiered_offer();
		$this->assertSame( 'ticket_type_unavailable', $this->checkout()->get_error_code(), 'Two types: the buyer must pick.' );
		$this->assertSame( 'ticket_type_unavailable', $this->checkout( array( 'ticket_type' => 'nope' ) )->get_error_code() );
		$this->assertSame( 'cs_ticket', $this->checkout( array( 'ticket_type' => 'vip' ) )['session_id'] );
		list( $params ) = $GLOBALS['ticket_session_request'];
		$this->assertSame( 3000, $params['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'Lo-Fi show — VIP', $params['line_items'][0]['price_data']['product_data']['name'] );
		$this->assertSame( 'vip', $params['metadata']['ticket_type'] );
		$this->assertArrayNotHasKey( 'ticket_code', $params['metadata'] );
		$order = $this->order();
		$this->assertSame( 'vip', $order->get_meta( EXTRACHILL_SHOP_TICKET_TYPE_META ) );
		$this->assertSame( 'Event tickets: Lo-Fi show (VIP)', $order->get_items( 'line_item' )[0]->get_name() );
		$this->assertSame( 6000, extrachill_shop_ticket_order_amounts( $order )['subtotal_minor'] );
		$this->assertSame( array( 'held' => 2, 'by_type' => array( 'vip' => 2 ) ), extrachill_shop_held_ticket_quantities( 101 ) );

		// Fulfilment issues the passes as VIP.
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		$issue = array_values( array_filter( $GLOBALS['test_owner_calls'], static fn( $call ) => str_contains( $call['route'], 'issue-ticket-passes' ) ) );
		$this->assertSame( 'vip', $issue[0]['args']['body']['input']['ticket_type'] );
	}

	public function test_type_holds_are_counted_per_type(): void {
		$this->tiered_offer();
		$GLOBALS['stripe_session_ids'] = array( 'cs_one', 'cs_two' );
		$this->checkout( array( 'ticket_type' => 'vip', 'quantity' => 1 ) );
		$this->assertSame( 'tickets_held', $this->checkout( array( 'ticket_type' => 'vip', 'quantity' => 2 ) )->get_error_code(), 'One of two VIP is held.' );
		$this->assertSame( 'cs_two', $this->checkout( array( 'ticket_type' => 'ga', 'quantity' => 2 ) )['session_id'], 'GA is unaffected.' );
		$this->assertSame( 'ticket_sold_out', $this->checkout( array( 'ticket_type' => 'vip', 'quantity' => 3 ) )->get_error_code() );
		unset( $GLOBALS['stripe_session_ids'] );
	}

	public function test_code_discounts_the_charge_and_the_fees(): void {
		$this->tiered_offer( array( 'code' => 'FRIENDS', 'percent_off' => 20, 'amount_off_minor' => 0, 'max_uses' => 3, 'ticket_types' => array( 'ga' ) ) );
		$this->assertSame( 'ticket_code_not_applicable', $this->checkout( array( 'ticket_type' => 'vip', 'code' => 'friends' ) )->get_error_code() );
		$this->assertSame( 'cs_ticket', $this->checkout( array( 'ticket_type' => 'ga', 'code' => ' friends ' ) )['session_id'] );
		$offer_call = array_values( array_filter( $GLOBALS['test_owner_calls'], static fn( $call ) => str_contains( $call['route'], 'get-event-ticket-offer' ) ) );
		$this->assertSame( 'FRIENDS', end( $offer_call )['args']['query']['input']['code'], 'The code is read upper-cased from Events.' );
		list( $params ) = $GLOBALS['ticket_session_request'];
		$this->assertSame( 800, $params['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'Lo-Fi show — GA (code FRIENDS)', $params['line_items'][0]['price_data']['product_data']['name'] );
		$this->assertSame( 'FRIENDS', $params['metadata']['ticket_code'] );
		$order   = $this->order();
		$amounts = extrachill_shop_ticket_order_amounts( $order );
		$this->assertSame( 1600, $amounts['subtotal_minor'] );
		// $2 platform fee plus processing on $16 instead of $20 (2.97 undiscounted).
		$this->assertSame( 285, $amounts['service_fee_minor'], 'Fees follow the paid subtotal.' );
		$this->assertSame( 1885, $amounts['total_minor'] );
		$line = $order->get_items( 'line_item' )[0];
		$this->assertSame( 20.0, $line->get_subtotal(), 'List price before the code.' );
		$this->assertSame( 16.0, $line->get_total() );
		$this->assertSame( 4.0, $order->get_discount_total() );
		$this->assertSame( 'FRIENDS', $order->get_meta( EXTRACHILL_SHOP_TICKET_CODE_META ) );
		$this->assertSame( '400', $order->get_meta( EXTRACHILL_SHOP_TICKET_DISCOUNT_META ) );
		$this->assertSame( 2, extrachill_shop_ticket_code_uses( 101, 'FRIENDS' ) );
	}

	public function test_code_use_limit_counts_paid_and_open_checkouts(): void {
		$this->tiered_offer( array( 'code' => 'FRIENDS', 'percent_off' => 20, 'amount_off_minor' => 0, 'max_uses' => 3, 'ticket_types' => array() ) );
		$GLOBALS['stripe_session_ids'] = array( 'cs_one', 'cs_two' );
		$this->checkout( array( 'ticket_type' => 'ga', 'code' => 'FRIENDS' ) );
		$this->mark_paid( 'cs_one', 'pi_one' );
		$this->assertSame( 'ticket_code_used_up', $this->checkout( array( 'ticket_type' => 'ga', 'code' => 'FRIENDS' ) )->get_error_code() );
		$this->assertSame( 'cs_two', $this->checkout( array( 'ticket_type' => 'ga', 'code' => 'FRIENDS', 'quantity' => 1 ) )['session_id'] );
		$this->assertSame( 3, extrachill_shop_ticket_code_uses( 101, 'FRIENDS' ) );
		unset( $GLOBALS['stripe_session_ids'] );
	}

	public function test_bad_and_free_codes_are_refused_before_stripe(): void {
		$this->tiered_offer( null, true );
		$this->assertSame( 'ticket_code_invalid', $this->checkout( array( 'ticket_type' => 'ga', 'code' => 'NOPE' ) )->get_error_code() );
		$this->tiered_offer( array( 'code' => 'COMP', 'percent_off' => 0, 'amount_off_minor' => 5000, 'max_uses' => 0, 'ticket_types' => array() ) );
		$GLOBALS['ticket_offer']['ticket_types'][0]['unit_price_minor'] = 0;
		$this->assertSame( 'ticket_code_free', $this->checkout( array( 'ticket_type' => 'ga', 'code' => 'COMP' ) )->get_error_code() );
		$this->assertNull( $GLOBALS['ticket_session_request'] );
		$this->assertSame( array(), array_filter( $GLOBALS['test_orders'], static fn( $o ) => $o instanceof TicketTestOrder ) );
	}

	public function test_single_price_show_needs_no_type_and_keeps_its_names(): void {
		$GLOBALS['ticket_offer']['ticket_types'] = array(
			array( 'id' => 'general', 'name' => 'General admission', 'price_minor' => 1000, 'unit_price_minor' => 1000, 'remaining' => null, 'code_applies' => false ),
		);
		$this->checkout();
		list( $params ) = $GLOBALS['ticket_session_request'];
		$this->assertSame( 'Lo-Fi show', $params['line_items'][0]['price_data']['product_data']['name'] );
		$this->assertSame( 'general', $params['metadata']['ticket_type'] );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		$issue = array_values( array_filter( $GLOBALS['test_owner_calls'], static fn( $call ) => str_contains( $call['route'], 'issue-ticket-passes' ) ) );
		$this->assertArrayNotHasKey( 'ticket_type', $issue[0]['args']['body']['input'], 'Single-price shows send what they always did.' );
	}

	public function test_unlimited_shows_need_no_hold_count(): void {
		$this->checkout();
		$this->assertSame( array(), array_filter( $GLOBALS['ticket_order_queries'], static fn( $args ) => array( 'wc-pending' ) === ( $args['status'] ?? null ) ) );
	}

	public function test_wrong_account_and_unpaid_sessions_do_not_issue(): void {
		$this->checkout();
		$event          = $this->event();
		$event->account = 'acct_other';
		$this->assertSame( 'ticket_account_mismatch', extrachill_shop_route_webhook_event( $event )->get_error_code() );
		$event                               = $this->event();
		$event->data->object->payment_status = 'unpaid';
		$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
		$this->assertSame( 'pending', $this->order()->get_status() );
	}
	public function test_source_grants_are_bounded(): void {
		add_filter( 'extrachill_shop_ticket_service_assertion_keys', static function () { return array( 'test' => 'secret' );
		} );
		add_filter( 'extrachill_shop_ticket_service_assertion_active_key_id', static function () { return 'test';
		} );
		$grants = extrachill_shop_register_ticket_source_grants( array() );
		$this->assertCount( 7, $grants );
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
		$order = $this->order();
		$this->assertSame( 'completed', $order->get_status() );
		// Recorded as a $5 WooCommerce refund, without calling Stripe again.
		$this->assertSame( array( 5.0 ), $order->refunds );
		$this->assertFalse( $GLOBALS['test_wc_refunds'][0]['refund_payment'] );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
		// A replay records nothing new.
		$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
		$this->assertSame( array( 5.0 ), $order->refunds );
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
		$order = $this->order();
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'disputed', extrachill_shop_ticket_order_state( $order ) );
		$this->assertSame( 'dp_ticket', $order->get_meta( '_extrachill_dispute' ) );
		$this->assertSame( 'completed', $order->get_meta( EXTRACHILL_SHOP_DISPUTE_PRIOR_STATUS_META ) );
		$this->assertSame( 'ticket_disputed', extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
	}
	public function test_failed_dispute_revoke_is_retried(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$GLOBALS['test_owner_callback'] = static fn() => new WP_Error( 'owner_runtime_unavailable', 'Events unreachable.' );
		$this->assertSame( 'owner_runtime_unavailable', extrachill_shop_route_webhook_event( $this->dispute() )->get_error_code() );
		$this->assertSame( 'completed', $this->order()->get_status() );
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

		$order = $this->order();
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'dp_ticket:won', $order->get_meta( EXTRACHILL_SHOP_DISPUTE_OUTCOME_META ) );
		$this->assertSame( '', $order->get_meta( '_extrachill_dispute' ) );
		$notes = count( $order->notes );
		$this->assertStringContainsString( '2 pass(es) cancelled by the dispute work again', end( $order->notes ) );

		// Replay: no second restore, so no second buyer email, and no second note.
		$GLOBALS['test_owner_calls'] = array();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'won' ) ) );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
		$this->assertCount( $notes, $this->order()->notes );

		// A late `created` for the same, now closed, dispute does not cancel them again.
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->dispute() ) );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
		$this->assertSame( 'completed', $this->order()->get_status() );

		// Refunds work again.
		$this->assertSame( array( 'refunded' => true ), extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) ) );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
	}
	public function test_warning_closed_inquiry_counts_as_won(): void {
		$this->disputed_order();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'warning_closed' ) ) );
		$this->assertSame( 'restore-ticket-passes', $this->routes()[0] );
		$this->assertSame( 'completed', $this->order()->get_status() );
	}
	public function test_won_dispute_after_check_in_restores_nothing_but_unblocks_refunds(): void {
		$this->disputed_order();
		$GLOBALS['ticket_restore'] = array( 'restored' => 0 );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'won' ) ) );
		$order = $this->order();
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertStringContainsString( 'No passes needed restoring', end( $order->notes ) );
		$this->assertStringNotContainsString( 'passes work again', $GLOBALS['test_owner_calls'][1]['args']['body']['input']['body_html'] );
	}
	public function test_failed_restore_is_retried_without_recording_the_outcome(): void {
		$this->disputed_order();
		$GLOBALS['test_owner_callback'] = static fn() => new WP_Error( 'owner_runtime_unavailable', 'Events unreachable.' );
		$this->assertSame( 'owner_runtime_unavailable', extrachill_shop_route_webhook_event( $this->closed_dispute( 'won' ) )->get_error_code() );

		$order = $this->order();
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( '', $order->get_meta( EXTRACHILL_SHOP_DISPUTE_OUTCOME_META ) );
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

		$order = $this->order();
		$this->assertSame( 'dp_ticket:lost', $order->get_meta( EXTRACHILL_SHOP_DISPUTE_OUTCOME_META ) );
		// The bank returned the whole payment: a WooCommerce refund, tagged with the dispute.
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertSame( array( 22.97 ), $order->refunds );
		$this->assertSame( 'dp_ticket', $order->get_refunds()[0]->get_meta( EXTRACHILL_SHOP_REFUND_ID_META ) );
		$this->assertFalse( $GLOBALS['test_wc_refunds'][0]['refund_payment'] );
		$this->assertStringContainsString( 'passes stay cancelled', end( $order->notes ) );
		$this->assertSame( array( 'refunded' => true ), extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) ), 'Already refunded by the bank.' );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );

		$GLOBALS['test_owner_calls'] = array();
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'lost' ) ) );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
		$this->assertCount( 1, $order->refunds );
	}
	public function test_partly_lost_dispute_stays_on_hold_and_unrefundable(): void {
		$this->disputed_order();
		$closed                       = $this->closed_dispute( 'lost' );
		$closed->data->object->amount = 1000;
		$this->assertTrue( extrachill_shop_route_webhook_event( $closed ) );
		$order = $this->order();
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array( 10.0 ), $order->refunds );
		$this->assertSame( 'ticket_disputed', extrachill_shop_refund_ticket_order( array( 'order_reference' => 'cs_ticket' ) )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['ticket_refunds'] );
	}
	public function test_show_refund_refunds_open_orders_and_counts_check_ins(): void {
		$GLOBALS['stripe_session_ids'] = array( 'cs_ticket', 'cs_other_show', 'cs_abandoned' );
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		// A paid order for another show and an abandoned checkout for this one.
		$GLOBALS['ticket_offer']['event_id'] = 202;
		$this->checkout();
		$this->mark_paid( 'cs_other_show', 'pi_other' );
		$GLOBALS['ticket_offer']['event_id'] = 101;
		$this->checkout();
		$this->assertSame( array(
			'refunded'   => 1,
			'checked_in' => 0,
			'failed'     => 0,
		), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) ) );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
		$this->assertSame( 'completed', $this->order( 'cs_other_show' )->get_status() );
		$this->assertSame( 'pending', $this->order( 'cs_abandoned' )->get_status() );
		// Running it again finds nothing left to refund.
		$this->assertSame( array(
			'refunded'   => 0,
			'checked_in' => 0,
			'failed'     => 0,
		), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 101 ) ) );
		// An order someone checked in on is counted, never refunded.
		$GLOBALS['ticket_redeemed'] = true;
		$this->assertSame( array(
			'refunded'   => 0,
			'checked_in' => 1,
			'failed'     => 0,
		), extrachill_shop_refund_event_ticket_orders( array( 'event_id' => 202 ) ) );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
		unset( $GLOBALS['stripe_session_ids'] );
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
		$this->assertSame( array(), $GLOBALS['test_orders'] );
	}
	public function test_checkout_records_terms_version_and_buyer(): void {
		$this->checkout();
		$order = $this->order();
		$this->assertSame( '2026-11-14', $order->get_meta( '_extrachill_terms_version' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $order->get_meta( '_extrachill_terms_accepted_at' ) );
		$this->assertSame( 551, $order->get_customer_id() );
		$this->assertSame( 'buyer@example.com', $order->get_billing_email() );
		$this->assertSame( 'Lo-Fi show', $order->get_meta( EXTRACHILL_SHOP_TICKET_EVENT_TITLE_META ) );
		$metadata = $GLOBALS['ticket_session_request'][0]['metadata'];
		$this->assertSame( '2026-11-14', $metadata['terms_version'] );
		$this->assertSame( $order->get_meta( '_extrachill_terms_accepted_at' ), $metadata['terms_accepted_at'] );
		$this->assertSame( '551', $metadata['buyer_user_id'] );
		// The order's customer is the buyer index; no user meta is written.
		$this->assertSame( array(), $GLOBALS['test_user_meta'] );
	}
	public function test_guest_checkout_records_no_buyer(): void {
		$GLOBALS['test_user_id'] = 0;
		$this->checkout();
		$this->assertSame( 0, $this->order()->get_customer_id() );
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
		$this->assertSame( $this->order()->get_id(), $orders[0]['order_id'] );
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
		// The query is by the buyer's customer ID and the ticket kind.
		$query = end( $GLOBALS['ticket_order_queries'] );
		$this->assertSame( 552, $query['customer_id'] );
		$this->assertContains( array( 'key' => EXTRACHILL_SHOP_ORDER_KIND_META, 'value' => EXTRACHILL_SHOP_TICKET_KIND ), $query['meta_query'] );
		// A guest checkout under the same email never shows up either.
		$GLOBALS['test_user_id'] = 0;
		$GLOBALS['stripe_session_ids'] = array( 'cs_guest' );
		$this->checkout();
		$this->mark_paid( 'cs_guest', 'pi_guest' );
		$GLOBALS['test_user_id'] = 552;
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
	/** Age the order past the webhook grace period and stub its Session in Stripe. */
	private function missed_webhook( string $payment_status, string $status = 'complete' ): TicketTestOrder {
		$order          = $this->order();
		$order->created = time() - 3600;
		$GLOBALS['stripe_session_status']['cs_ticket'] = $status;
		$GLOBALS['stripe_session_data']['cs_ticket']   = array(
			'payment_status' => $payment_status,
			'payment_intent' => 'pi_ticket',
			'customer_email' => 'buyer@example.com',
			'metadata'       => (object) array(
				'kind'     => 'extrachill_ticket',
				'name'     => 'Buyer',
				'order_id' => (string) $order->get_id(),
			),
		);
		return $order;
	}
	private function owner_calls( string $ability ): array {
		return array_values( array_filter( $GLOBALS['test_owner_calls'], static fn( $call ) => str_contains( $call['route'], $ability ) ) );
	}
	public function test_reconciliation_fulfils_a_paid_order_whose_webhook_never_arrived(): void {
		$this->checkout();
		$this->missed_webhook( 'paid' );
		$this->assertSame( array( 'fulfilled' => 1, 'closed' => 0, 'open' => 0, 'failed' => 0 ), extrachill_shop_reconcile_pending_ticket_orders() );
		$order = $this->order();
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pi_ticket', $order->get_meta( '_extrachill_payment_intent' ) );
		$this->assertSame( 'cs_ticket', $order->get_meta( EXTRACHILL_SHOP_TICKET_RECONCILED_META ) );
		$this->assertStringContainsString( 'never completed fulfilment', end( $order->notes ) );
		// The Session is read on the seller's account, as the webhook would carry it.
		$this->assertSame( array( 'cs_ticket', array( 'stripe_account' => 'acct_venue' ) ), $GLOBALS['stripe_session_retrieved'][0] );
		$issue = $this->owner_calls( 'issue-ticket-passes' );
		$this->assertCount( 1, $issue );
		$this->assertSame( 'cs_ticket', $issue[0]['args']['body']['input']['idempotency_key'] );
		$this->assertSame( 'buyer@example.com', $issue[0]['args']['body']['input']['email'] );
		// A late webhook after reconciliation is a no-op.
		$count = count( $GLOBALS['test_owner_calls'] );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		$this->assertCount( $count, $GLOBALS['test_owner_calls'] );
		$this->assertSame( 1, $this->order()->paid );
		// Completed orders drop out of the next run.
		$this->assertSame( 0, extrachill_shop_reconcile_pending_ticket_orders()['fulfilled'] );
	}
	public function test_reconciliation_leaves_recent_and_open_sessions_alone(): void {
		$this->checkout();
		// Inside the grace period the webhook still has time; Stripe is not asked.
		$this->assertSame( array( 'fulfilled' => 0, 'closed' => 0, 'open' => 0, 'failed' => 0 ), extrachill_shop_reconcile_pending_ticket_orders() );
		$this->assertSame( array(), $GLOBALS['stripe_session_retrieved'] );
		$this->missed_webhook( 'unpaid', 'open' );
		$this->assertSame( 1, extrachill_shop_reconcile_pending_ticket_orders()['open'] );
		$this->assertSame( 'pending', $this->order()->get_status() );
		$this->assertSame( array(), $this->owner_calls( 'issue-ticket-passes' ) );
		// Too old to reconcile: left for an operator.
		$this->assertSame( 0, extrachill_shop_reconcile_pending_ticket_orders( time() + 30 * 86400 )['open'] );
		$this->assertQueryArgs();
	}
	private function assertQueryArgs(): void {
		$query = $GLOBALS['ticket_order_queries'][0];
		$this->assertSame( array( 'wc-pending' ), $query['status'] );
		$this->assertSame( EXTRACHILL_SHOP_TICKET_RECONCILE_BATCH, $query['limit'] );
		$this->assertMatchesRegularExpression( '/^\d+\.\.\.\d+$/', $query['date_created'] );
		$this->assertContains( array( 'key' => EXTRACHILL_SHOP_ORDER_KIND_META, 'value' => EXTRACHILL_SHOP_TICKET_KIND ), $query['meta_query'] );
	}
	public function test_reconciliation_cancels_an_order_whose_session_expired_unpaid(): void {
		$this->checkout();
		$this->missed_webhook( 'unpaid', 'expired' );
		$this->assertSame( 1, extrachill_shop_reconcile_pending_ticket_orders()['closed'] );
		$this->assertSame( 'cancelled', $this->order()->get_status() );
		$this->assertSame( 'expired', extrachill_shop_ticket_order_state( $this->order() ) );
		$this->assertSame( array(), $this->owner_calls( 'issue-ticket-passes' ) );
	}
	public function test_reconciliation_failures_are_retried_on_the_next_run(): void {
		$this->checkout();
		$this->missed_webhook( 'paid' );
		$GLOBALS['stripe_session_retrieve_fail'] = true;
		$this->assertSame( 1, extrachill_shop_reconcile_pending_ticket_orders()['failed'] );
		$this->assertSame( 'pending', $this->order()->get_status() );
		$GLOBALS['stripe_session_retrieve_fail'] = false;
		// Events is down: the order stays pending with the payment stored.
		$callback                       = $GLOBALS['test_owner_callback'];
		$GLOBALS['test_owner_callback'] = static fn( $site, $method, $route, $args ) => str_contains( $route, 'issue-ticket-passes' ) ? new WP_Error( 'owner_unavailable', 'Down.' ) : $callback( $site, $method, $route, $args );
		$this->assertSame( 1, extrachill_shop_reconcile_pending_ticket_orders()['failed'] );
		$this->assertSame( 'pending', $this->order()->get_status() );
		$this->assertSame( 'pi_ticket', $this->order()->get_meta( '_extrachill_payment_intent' ) );
		$GLOBALS['test_owner_callback'] = $callback;
		$this->assertSame( 1, extrachill_shop_reconcile_pending_ticket_orders()['fulfilled'] );
		$this->assertSame( 'completed', $this->order()->get_status() );
	}
	public function test_reconciliation_of_a_sold_out_show_refunds_instead_of_issuing(): void {
		$this->checkout();
		$this->missed_webhook( 'paid' );
		$GLOBALS['ticket_issue_status'] = 'sold_out';
		$this->assertSame( 1, extrachill_shop_reconcile_pending_ticket_orders()['fulfilled'] );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
		$this->assertSame( 'refunded', $this->order()->get_status() );
	}
	public function test_undelivered_pass_emails_are_flagged_and_retried_until_sent(): void {
		$this->checkout();
		$GLOBALS['ticket_emails_failed'] = 2;
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->event() ) );
		$order = $this->order();
		$this->assertSame( 'completed', $order->get_status(), 'The passes exist, so the order is issued.' );
		$this->assertSame( '1', $order->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META ) );
		$GLOBALS['ticket_resend'] = array( 'emailed' => 0, 'failed' => 2 );
		$this->assertSame( array( 'sent' => 0, 'failed' => 1, 'exhausted' => 0 ), extrachill_shop_retry_ticket_pass_emails() );
		$this->assertSame( '1', $this->order()->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_ATTEMPTS_META ) );
		$GLOBALS['ticket_resend'] = array( 'emailed' => 2, 'failed' => 0 );
		$this->assertSame( array( 'sent' => 1, 'failed' => 0, 'exhausted' => 0 ), extrachill_shop_retry_ticket_pass_emails() );
		$resend = $this->owner_calls( 'resend-ticket-pass-emails' );
		$this->assertCount( 2, $resend );
		$this->assertSame( array( 'order_reference' => 'cs_ticket', 'email' => 'buyer@example.com' ), $resend[1]['args']['body']['input'] );
		$this->assertSame( 'extrachill.events.tickets', $resend[1]['args']['service_assertion']['service_id'] );
		$order = $this->order();
		$this->assertSame( '', $order->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META ) );
		$this->assertSame( '', $order->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_ATTEMPTS_META ) );
		$this->assertStringContainsString( 'Re-sent 2 pass email(s)', end( $order->notes ) );
		// Nothing left to retry.
		$this->assertSame( array( 'sent' => 0, 'failed' => 0, 'exhausted' => 0 ), extrachill_shop_retry_ticket_pass_emails() );
		$this->assertCount( 2, $this->owner_calls( 'resend-ticket-pass-emails' ) );
	}
	public function test_delivered_pass_emails_are_not_retried(): void {
		$this->checkout();
		extrachill_shop_route_webhook_event( $this->event() );
		$this->assertSame( '', $this->order()->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META ) );
		extrachill_shop_run_ticket_reconciliation();
		$this->assertSame( array(), $this->owner_calls( 'resend-ticket-pass-emails' ) );
	}
	public function test_pass_email_retries_are_bounded(): void {
		$this->checkout();
		$GLOBALS['ticket_emails_failed'] = 1;
		extrachill_shop_route_webhook_event( $this->event() );
		$callback                       = $GLOBALS['test_owner_callback'];
		$GLOBALS['test_owner_callback'] = static fn( $site, $method, $route, $args ) => str_contains( $route, 'resend-ticket-pass-emails' ) ? new WP_Error( 'owner_unavailable', 'Down.' ) : $callback( $site, $method, $route, $args );
		for ( $i = 1; $i < EXTRACHILL_SHOP_TICKET_PASS_EMAIL_MAX_ATTEMPTS; $i++ ) {
			$this->assertSame( 1, extrachill_shop_retry_ticket_pass_emails()['failed'] );
		}
		$this->assertSame( 1, extrachill_shop_retry_ticket_pass_emails()['exhausted'] );
		$order = $this->order();
		$this->assertSame( 'exhausted', $order->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META ) );
		$this->assertStringContainsString( 'manually', end( $order->notes ) );
		$this->assertSame( array( 'sent' => 0, 'failed' => 0, 'exhausted' => 0 ), extrachill_shop_retry_ticket_pass_emails(), 'An exhausted order is not retried again.' );
		$this->assertCount( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_MAX_ATTEMPTS, $this->owner_calls( 'resend-ticket-pass-emails' ) );
	}
	public function test_refunded_orders_stop_pass_email_retries(): void {
		$this->checkout();
		$GLOBALS['ticket_emails_failed'] = 2;
		extrachill_shop_route_webhook_event( $this->event() );
		// Refunded orders are not queried; a refund_pending order has no active passes left.
		$order         = $this->order();
		$order->status = 'refunded';
		$this->assertSame( array( 'sent' => 0, 'failed' => 0, 'exhausted' => 0 ), extrachill_shop_retry_ticket_pass_emails() );
		$order->status            = 'completed';
		$GLOBALS['ticket_resend'] = array( 'emailed' => 0, 'failed' => 0 );
		$this->assertSame( 1, extrachill_shop_retry_ticket_pass_emails()['sent'] );
		$this->assertSame( '', $this->order()->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META ) );
		$this->assertStringContainsString( 'No active passes', end( $this->order()->notes ) );
	}
	public function test_won_dispute_restore_emails_clear_a_pending_pass_email_retry(): void {
		$this->disputed_order();
		$order = $this->order();
		$order->update_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META, '1' );
		$order->update_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_ATTEMPTS_META, '3' );
		$GLOBALS['ticket_restore'] = array( 'restored' => 2 );
		$this->assertTrue( extrachill_shop_route_webhook_event( $this->closed_dispute( 'won' ) ) );
		$this->assertSame( '', $this->order()->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META ) );
		$this->assertSame( '', $this->order()->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_ATTEMPTS_META ) );
		unset( $GLOBALS['ticket_restore'] );
	}
	public function test_reconciliation_is_scheduled_once_with_action_scheduler(): void {
		$GLOBALS['test_recurring_actions'] = array();
		$this->assertTrue( extrachill_shop_schedule_ticket_reconciliation() );
		$this->assertTrue( extrachill_shop_schedule_ticket_reconciliation() );
		$this->assertCount( 1, $GLOBALS['test_recurring_actions'] );
		$this->assertSame( array( EXTRACHILL_SHOP_TICKET_RECONCILE_INTERVAL, EXTRACHILL_SHOP_TICKET_RECONCILE_HOOK, array(), EXTRACHILL_SHOP_TICKET_RECONCILE_GROUP ), array_slice( $GLOBALS['test_recurring_actions'][0], 1 ) );
		unset( $GLOBALS['test_recurring_actions'] );
	}
}
