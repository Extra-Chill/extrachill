<?php
/**
 * Seller ticket sales (Tickets tab) tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Test doubles and WordPress stubs.

namespace {
	foreach ( array(
		'is_user_logged_in' => static fn() => ! empty( $GLOBALS['ts_logged_in'] ),
		'__'                => static fn( $text ) => $text,
	) as $name => $impl ) {
		if ( ! function_exists( $name ) ) {
			$GLOBALS['ts_impl'][ $name ] = $impl;
			eval( "function {$name}( ...\$a ) { return ( \$GLOBALS['ts_impl']['{$name}'] )( ...\$a ); }" );
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {
	use ExtraChillEvents\Abilities\CommerceSellerAbilities;
	use ExtraChillEvents\Abilities\TicketSalesAbilities;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/TicketSalesAbilities.php';

	/** Seller resolver double. */
	class FakeSellers extends CommerceSellerAbilities {
		public $result;
		public function __construct() {}
		public function execute( array $input ) {
			return $this->result;
		}
	}

	final class TicketSalesAbilitiesTest extends TestCase {
		private function pass( $reference, $status, $issued = '2026-11-08 12:00:00', $name = 'Ada' ) {
			return array(
				'event_id'        => 9,
				'order_reference' => $reference,
				'guest_name'      => $name,
				'status'          => $status,
				'issued_at'       => $issued,
			);
		}

		private function settings() {
			return array(
				'tickets_enabled'    => true,
				'ticket_price_minor' => 1500,
				'ticket_currency'    => 'usd',
				'ticket_quantity'    => 100,
			);
		}

		public function test_orders_group_passes_and_refund_stops_at_check_in() {
			$summary = TicketSalesAbilities::summarize_event(
				array( 'event_id' => 9 ),
				$this->settings(),
				array(
					$this->pass( 'cs_paid', 'active' ),
					$this->pass( 'cs_paid', 'active', '2026-11-08 11:00:00' ),
					$this->pass( 'cs_mixed', 'redeemed', '2026-11-08 13:00:00', 'Bo' ),
					$this->pass( 'cs_mixed', 'active', '2026-11-08 13:00:00', 'Bo' ),
					$this->pass( 'cs_gone', 'revoked', '2026-11-08 14:00:00', 'Cy' ),
				)
			);

			$this->assertSame( 4, $summary['sold'] );
			$this->assertSame( 1, $summary['checked_in'] );
			$this->assertSame( 1, $summary['refunded'] );
			$this->assertSame( 1500, $summary['price_minor'] );
			$this->assertSame( 100, $summary['quantity'] );

			$orders = array_column( $summary['orders'], null, 'order_reference' );
			$this->assertSame( 2, $orders['cs_paid']['quantity'] );
			$this->assertSame( '2026-11-08 11:00:00', $orders['cs_paid']['bought_at'] );
			$this->assertSame( 'paid', $orders['cs_paid']['status'] );
			$this->assertTrue( $orders['cs_paid']['refundable'] );
			$this->assertSame( 'checked_in', $orders['cs_mixed']['status'] );
			$this->assertFalse( $orders['cs_mixed']['refundable'] );
			$this->assertSame( 'refunded', $orders['cs_gone']['status'] );
			$this->assertFalse( $orders['cs_gone']['refundable'] );
			$this->assertArrayNotHasKey( 'revoked', $orders['cs_paid'] );
		}

		public function test_upcoming_shows_first_then_recent_past_then_undated() {
			$sorted = TicketSalesAbilities::sort_events(
				array(
					array( 'event_id' => 1, 'start' => '2026-10-01 20:00:00', 'timing' => 'past' ),
					array( 'event_id' => 2, 'start' => '', 'timing' => 'past' ),
					array( 'event_id' => 3, 'start' => '2026-12-01 20:00:00', 'timing' => 'upcoming' ),
					array( 'event_id' => 4, 'start' => '2026-11-10 20:00:00', 'timing' => 'ongoing' ),
					array( 'event_id' => 5, 'start' => '2026-10-20 20:00:00', 'timing' => 'past' ),
				)
			);
			$this->assertSame( array( 4, 3, 5, 1, 2 ), array_column( $sorted, 'event_id' ) );
		}

		public function test_permission_requires_login_and_finance_authority() {
			$sellers = new FakeSellers();
			$ability = new TicketSalesAbilities( $sellers, false );
			$input   = array( 'type' => 'venue', 'id' => 1524 );

			$GLOBALS['ts_logged_in'] = false;
			$this->assertSame( 401, $ability->permission( $input )->get_error_data()['status'] );

			$GLOBALS['ts_logged_in'] = true;
			$sellers->result         = array( 'can_manage_finances' => false );
			$this->assertSame( 'cannot_manage_seller_finances', $ability->permission( $input )->get_error_code() );

			$sellers->result = new \WP_Error( 'commerce_seller_not_found', 'Missing', array( 'status' => 404 ) );
			$this->assertSame( 'commerce_seller_not_found', $ability->permission( $input )->get_error_code() );

			$sellers->result = array( 'can_manage_finances' => true );
			$this->assertTrue( $ability->permission( $input ) );
		}
	}
}
