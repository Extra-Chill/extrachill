<?php
/**
 * Show sales report, door list CSV export and Buy tickets QR tests (extrachill#1746).
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Test doubles and WordPress stubs.

namespace {
	// Same globals as TicketSalesAbilitiesTest, which may define these first.
	foreach ( array(
		'is_user_logged_in'                      => static fn() => ! empty( $GLOBALS['ts_logged_in'] ),
		'get_post_type'                          => static fn( $id ) => $GLOBALS['ts_post_types'][ $id ] ?? false,
		'get_post_meta'                          => static fn( $id, $key, $single = false ) => $GLOBALS['ts_meta'][ $id ][ $key ] ?? '',
		'update_post_meta'                       => static function ( $id, $key, $value ) { $GLOBALS['ts_meta'][ $id ][ $key ] = $value; return true; },
		'get_current_user_id'                    => static fn() => 7,
		'ec_cross_site_rest_request_http'        => static function ( ...$args ) { $GLOBALS['ts_shop_calls'][] = $args; return $GLOBALS['ts_shop_result']; },
		'extrachill_events_user_can_manage_event' => static fn( $user_id, $event_id ) => in_array( $user_id, $GLOBALS['str_managers'] ?? array(), true ),
		'extrachill_events_get_door_list_rows'   => static fn( $event_id ) => array( 'door_rows' => $GLOBALS['str_door_rows'] ?? array() ),
		'get_userdata'                           => static fn( $id ) => 12 === $id ? (object) array( 'user_email' => 'rsvp@example.com' ) : false,
		'get_date_from_gmt'                      => static fn( $gmt, $format = 'Y-m-d H:i:s' ) => gmdate( $format, strtotime( $gmt . ' UTC' ) - 5 * 3600 ),
	) as $name => $impl ) {
		if ( ! function_exists( $name ) ) {
			$GLOBALS['ts_impl'][ $name ] = $impl;
			eval( "function {$name}( ...\$a ) { return ( \$GLOBALS['ts_impl']['{$name}'] )( ...\$a ); }" );
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {
	use ExtraChillEvents\Abilities\ShowTicketReportAbilities;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/ShowTicketReportAbilities.php';
	require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-door.php';
	require_once dirname( __DIR__, 3 ) . '/inc/core/door-list-export.php';

	final class ShowTicketReportTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['ts_logged_in']   = true;
			$GLOBALS['ts_post_types']  = array( 9 => 'data_machine_events', 10 => 'post' );
			$GLOBALS['ts_meta']        = array();
			$GLOBALS['ts_shop_calls']  = array();
			$GLOBALS['ts_shop_result'] = array( 'orders' => array() );
			$GLOBALS['str_managers']   = array( 7 );
			$GLOBALS['str_door_rows']  = array();
		}

		private function tiered(): array {
			return array(
				'tickets_enabled'    => true,
				'ticket_price_minor' => 1500,
				'ticket_currency'    => 'usd',
				'ticket_quantity'    => 100,
				'ticket_types'       => array(
					array( 'id' => 'ga', 'name' => 'GA', 'price_minor' => 1500, 'quantity' => 0, 'hidden' => false ),
					array( 'id' => 'vip', 'name' => 'VIP', 'price_minor' => 4000, 'quantity' => 20, 'hidden' => false ),
				),
				'ticket_codes'       => array(
					array( 'code' => 'FRIENDS', 'percent_off' => 20, 'amount_off_minor' => 0, 'max_uses' => 10, 'ticket_types' => array() ),
					array( 'code' => 'BAND', 'percent_off' => 0, 'amount_off_minor' => 0, 'max_uses' => 0, 'ticket_types' => array( 'vip' ) ),
				),
			);
		}

		private function order( string $reference, string $type, int $quantity, int $subtotal, array $extra = array() ): array {
			return $extra + array(
				'order_reference' => $reference,
				'email'           => $reference . '@example.com',
				'ticket_type'     => $type,
				'code'            => '',
				'quantity'        => $quantity,
				'subtotal_minor'  => $subtotal,
				'discount_minor'  => 0,
				'total_minor'     => $subtotal + 100 * $quantity,
				'fees_minor'      => 100 * $quantity,
				'refunded_minor'  => 0,
				'state'           => 'issued',
			);
		}

		private function pass( string $status, string $type = '' ): array {
			return array( 'status' => $status, 'ticket_type' => $type );
		}

		public function test_totals_break_down_by_type() {
			$report = ShowTicketReportAbilities::build(
				$this->tiered(),
				array( 'ga' => 3, 'vip' => 2 ),
				array(),
				array(
					$this->order( 'cs_ga', 'ga', 3, 4500 ),
					$this->order( 'cs_vip', 'vip', 2, 8000 ),
				)
			);

			$this->assertSame( 5, $report['sold'] );
			$this->assertTrue( $report['money_available'] );
			$types = array_column( $report['ticket_types'], null, 'id' );
			$this->assertSame( array( 'ga', 'vip' ), array_keys( $types ) );
			$this->assertSame( array( 'id' => 'ga', 'name' => 'GA', 'sold' => 3, 'quantity' => 0, 'revenue_minor' => 4500 ), $types['ga'] );
			$this->assertSame( array( 'id' => 'vip', 'name' => 'VIP', 'sold' => 2, 'quantity' => 20, 'revenue_minor' => 8000 ), $types['vip'] );
			$this->assertSame( 13000, $report['gross_minor'] );
			$this->assertSame( 500, $report['fees_minor'] );
			$this->assertSame( 12500, $report['net_minor'] );
		}

		public function test_codes_count_tickets_and_discount_on_orders_that_kept_their_money() {
			$report = ShowTicketReportAbilities::build(
				$this->tiered(),
				array( 'ga' => 3 ),
				array(),
				array(
					$this->order( 'cs_one', 'ga', 2, 2400, array( 'code' => 'FRIENDS', 'discount_minor' => 600 ) ),
					$this->order( 'cs_two', 'ga', 1, 1200, array( 'code' => 'FRIENDS', 'discount_minor' => 300 ) ),
					$this->order( 'cs_gone', 'ga', 4, 4800, array( 'code' => 'FRIENDS', 'discount_minor' => 1200, 'state' => 'refunded', 'fees_minor' => 0, 'refunded_minor' => 5200 ) ),
					// A code the host has since deleted still shows.
					$this->order( 'cs_old', 'ga', 1, 1000, array( 'code' => 'OLDCODE', 'discount_minor' => 500 ) ),
				)
			);

			$codes = array_column( $report['codes'], null, 'code' );
			$this->assertSame( array( 'FRIENDS', 'BAND', 'OLDCODE' ), array_keys( $codes ) );
			$this->assertSame( array( 'code' => 'FRIENDS', 'uses' => 3, 'max_uses' => 10, 'discount_minor' => 900 ), $codes['FRIENDS'], 'Refunded orders give their uses back.' );
			$this->assertSame( 0, $codes['BAND']['uses'] );
			$this->assertSame( array( 'code' => 'OLDCODE', 'uses' => 1, 'max_uses' => 0, 'discount_minor' => 500 ), $codes['OLDCODE'] );
		}

		public function test_refunds_come_off_gross_and_return_their_fees() {
			$report = ShowTicketReportAbilities::build(
				$this->tiered(),
				array( 'ga' => 2 ),
				array( $this->pass( 'active', 'ga' ), $this->pass( 'active', 'ga' ), $this->pass( 'revoked', 'ga' ), $this->pass( 'revoked', 'ga' ) ),
				array(
					$this->order( 'cs_paid', 'ga', 2, 3000 ),
					$this->order( 'cs_gone', 'ga', 2, 3000, array( 'state' => 'refunded', 'fees_minor' => 0, 'refunded_minor' => 3200 ) ),
					// A partial refund from the payout dashboard keeps its tickets and fee.
					$this->order( 'cs_part', 'vip', 1, 4000, array( 'refunded_minor' => 1000 ) ),
				)
			);

			$this->assertSame( 2, $report['refunded'] );
			$this->assertSame( 3200 + 3200 + 4100, $report['gross_minor'] );
			$this->assertSame( 4200, $report['refunds_minor'] );
			$this->assertSame( 300, $report['fees_minor'] );
			$this->assertSame( 10500 - 4200 - 300, $report['net_minor'] );
			$types = array_column( $report['ticket_types'], 'revenue_minor', 'id' );
			$this->assertSame( 3000, $types['ga'], 'A refunded order earns its type nothing.' );
			$this->assertSame( 4000, $types['vip'] );
		}

		public function test_legacy_single_price_show_reports_one_general_type() {
			$report = ShowTicketReportAbilities::build(
				array( 'tickets_enabled' => true, 'ticket_price_minor' => 1500, 'ticket_currency' => 'usd', 'ticket_quantity' => 50 ),
				array( 'general' => 2 ),
				array( $this->pass( 'active' ), $this->pass( 'redeemed' ) ),
				array( $this->order( 'cs_old', '', 2, 3000 ) )
			);

			$this->assertSame( array( array( 'id' => 'general', 'name' => 'General admission', 'sold' => 2, 'quantity' => 0, 'revenue_minor' => 3000 ) ), $report['ticket_types'] );
			$this->assertSame( array(), $report['codes'] );
			$this->assertSame( 'usd', $report['currency'] );
		}

		public function test_check_in_rate_is_redeemed_over_live_passes() {
			$rows   = array( $this->pass( 'redeemed', 'ga' ), $this->pass( 'redeemed', 'vip' ), $this->pass( 'redeemed', 'ga' ), $this->pass( 'active', 'ga' ), $this->pass( 'active', 'ga' ), $this->pass( 'active', 'vip' ), $this->pass( 'revoked', 'ga' ) );
			$report = ShowTicketReportAbilities::build( $this->tiered(), array( 'ga' => 4, 'vip' => 2 ), $rows, array() );
			$this->assertSame( 3, $report['checked_in'] );
			$this->assertSame( 6, $report['sold'] );
			$this->assertSame( 50, $report['check_in_rate'], 'Refunded passes are not counted as issued.' );

			$empty = ShowTicketReportAbilities::build( $this->tiered(), array(), array(), array() );
			$this->assertSame( 0, $empty['check_in_rate'] );
		}

		public function test_money_is_unavailable_without_shop_orders() {
			$report = ShowTicketReportAbilities::build( $this->tiered(), array( 'ga' => 1 ), array( $this->pass( 'active', 'ga' ) ), null );
			$this->assertFalse( $report['money_available'] );
			$this->assertSame( 0, $report['gross_minor'] );
			$this->assertSame( 1, $report['sold'] );
		}

		public function test_permission_matches_the_door_list() {
			$ability = new ShowTicketReportAbilities( false );
			$this->assertTrue( $ability->permission( array( 'event_id' => 9 ) ) );
			$this->assertSame( 'event_not_found', $ability->permission( array( 'event_id' => 10 ) )->get_error_code() );
			$GLOBALS['str_managers'] = array();
			$this->assertSame( 403, $ability->permission( array( 'event_id' => 9 ) )->get_error_data()['status'] );
		}

		public function test_shop_orders_are_read_as_the_user_and_null_when_refused() {
			$GLOBALS['ts_shop_result'] = array( 'orders' => array( $this->order( 'cs_a', 'ga', 1, 1500 ) ) );
			$this->assertCount( 1, ShowTicketReportAbilities::shop_orders( 9 ) );
			list( $site, $method, $route, $args ) = $GLOBALS['ts_shop_calls'][0];
			$this->assertSame( array( 'shop', 'GET' ), array( $site, $method ) );
			$this->assertStringEndsWith( 'extrachill/get-event-ticket-order-report/run', $route );
			$this->assertSame( 7, $args['user_id'] );
			$this->assertSame( array( 'event_id' => 9 ), $args['query']['input'] );

			$GLOBALS['ts_shop_result'] = new \WP_Error( 'rest_forbidden', 'No' );
			$this->assertNull( ShowTicketReportAbilities::shop_orders( 9 ) );
		}

		private function door_rows(): array {
			return array(
				array( 'kind' => 'guest', 'guest_name' => 'Ada', 'source_kind' => 'ticket', 'order_reference' => 'cs_vip', 'ticket_type' => 'vip', 'email' => '', 'status' => 'redeemed', 'checked_in_at' => '2026-11-08 03:15:00' ),
				array( 'kind' => 'guest', 'guest_name' => 'Bo', 'source_kind' => 'eventbrite', 'source' => 'Eventbrite', 'status' => 'active' ),
				array( 'kind' => 'guest', 'guest_name' => 'Cy', 'source_kind' => 'band', 'source' => 'The Rips', 'status' => 'active' ),
				array( 'kind' => 'guest', 'guest_name' => '=HYPERLINK("x")', 'source_kind' => 'guest_list', 'email' => 'comp@example.com', 'status' => 'active' ),
				array( 'kind' => 'guest', 'guest_name' => 'Di', 'source_kind' => 'owner_list', 'source' => 'Venue list', 'status' => 'active' ),
				array( 'kind' => 'attendee', 'user_id' => 12, 'display_name' => 'Ed, Jr.', 'pass_status' => 'active' ),
				// Revoked and pending names are not on the door, so not in the export.
				array( 'kind' => 'guest', 'guest_name' => 'Gone', 'source_kind' => 'guest_list', 'status' => 'revoked' ),
			);
		}

		public function test_door_list_csv_rows_carry_source_type_code_and_check_in() {
			$rows = ShowTicketReportAbilities::door_list_csv_rows(
				extrachill_events_guest_list_door_rows( $this->door_rows() ),
				array( $this->order( 'cs_vip', 'vip', 1, 4000, array( 'code' => 'BAND', 'email' => 'ada@example.com' ) ) ),
				array( 'ga' => 'GA', 'vip' => 'VIP' ),
				static fn( $gmt ) => 'local ' . $gmt
			);

			$this->assertSame( ShowTicketReportAbilities::CSV_HEADER, $rows[0] );
			$this->assertSame( array( 'Ada', 'ada@example.com', 'Ticket', 'VIP', 'BAND', 'cs_vip', 'local 2026-11-08 03:15:00' ), $rows[1] );
			$this->assertSame( array( 'Bo', '', 'Eventbrite', '', '', '', '' ), $rows[2] );
			$this->assertSame( 'Band: The Rips', $rows[3][2] );
			$this->assertSame( array( '\'=HYPERLINK("x")', 'comp@example.com', 'Guest list' ), array_slice( $rows[4], 0, 3 ), 'Formulas are neutralized.' );
			$this->assertSame( 'Venue list', $rows[5][2] );
			$this->assertSame( array( 'Ed, Jr.', '', 'Guest list' ), array_slice( $rows[6], 0, 3 ) );
			$this->assertCount( 7, $rows );

			$csv = ShowTicketReportAbilities::to_csv( array( array( 'Ed, Jr.', 'say "hi"', 'plain' ) ) );
			$this->assertSame( "\"Ed, Jr.\",\"say \"\"hi\"\"\",plain\r\n", $csv );
		}

		public function test_export_is_denied_to_people_who_do_not_run_the_show() {
			$GLOBALS['str_door_rows'] = $this->door_rows();
			$GLOBALS['str_managers']  = array( 7 );

			$denied = extrachill_events_door_list_export( 9, 8 );
			$this->assertInstanceOf( \WP_Error::class, $denied );
			$this->assertSame( 'cannot_export_door_list', $denied->get_error_code() );
			$this->assertSame( 403, $denied->get_error_data()['status'] );
			$this->assertSame( 'cannot_export_door_list', extrachill_events_door_list_export( 9, 0 )->get_error_code(), 'Logged out.' );
			$this->assertSame( 'cannot_export_door_list', extrachill_events_door_list_export( 10, 7 )->get_error_code(), 'Not an event.' );
			$this->assertSame( array(), $GLOBALS['ts_shop_calls'], 'Nothing is read for a denied export.' );
		}

		public function test_export_downloads_every_name_with_shop_emails_and_codes() {
			$GLOBALS['str_door_rows']  = $this->door_rows();
			$GLOBALS['ts_meta'][9]     = array( '_extrachill_ticket_offer' => $this->tiered() );
			$GLOBALS['ts_shop_result'] = array( 'orders' => array( $this->order( 'cs_vip', 'vip', 1, 4000, array( 'code' => 'BAND', 'email' => 'ada@example.com' ) ) ) );

			$export = extrachill_events_door_list_export( 9, 7 );
			$this->assertSame( 'door-list-9.csv', $export['filename'] );
			$lines = explode( "\r\n", trim( $export['csv'] ) );
			$this->assertSame( 'Name,Email,Source,Ticket type,Code,Order reference,Checked in', $lines[0] );
			$this->assertSame( 'Ada,ada@example.com,Ticket,VIP,BAND,cs_vip,2026-11-07 22:15', $lines[1] );
			$this->assertSame( '"Ed, Jr.",rsvp@example.com,Guest list,,,,', $lines[6], 'Accounts get their account email.' );
			$this->assertCount( 7, $lines );
		}

		public function test_buy_tickets_qr_only_while_the_sale_is_open() {
			$offer = array( 'enabled' => true, 'sale_open' => true, 'url' => 'https://events.extrachill.com/events/show/' );
			$this->assertSame( 'https://events.extrachill.com/events/show/', extrachill_events_buy_tickets_qr_target( $offer ) );
			$this->assertSame( '', extrachill_events_buy_tickets_qr_target( array( 'sale_open' => false ) + $offer ), 'Sold out or over.' );
			$this->assertSame( '', extrachill_events_buy_tickets_qr_target( array( 'enabled' => false ) + $offer ), 'Tickets off.' );
			$this->assertSame( '', extrachill_events_buy_tickets_qr_target( new \WP_Error( 'event_not_found', 'Missing' ) ) );
			$this->assertSame( '', extrachill_events_buy_tickets_qr_target( null ) );

			$door = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/guest-list-door.php' );
			$this->assertMatchesRegularExpression( "/if \\( '' !== \\\$buy_tickets_qr \\) :.*ec-door-buy-tickets/s", $door, 'The door renders the QR only when there is one.' );
		}
	}
}
