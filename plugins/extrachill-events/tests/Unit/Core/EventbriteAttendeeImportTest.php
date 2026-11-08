<?php
/**
 * Eventbrite attendee import tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Test doubles and WordPress stubs.

namespace {
	foreach ( array(
		'extrachill_events_perk_enabled' => static fn( $id ) => ! empty( $GLOBALS['eb_perk'] ),
		'extrachill_cache_purge_post'    => static function ( $id ) { $GLOBALS['eb_purged'][] = $id; },
	) as $name => $impl ) {
		if ( ! function_exists( $name ) ) {
			$GLOBALS['eb_impl'][ $name ] = $impl;
			eval( "function {$name}( ...\$a ) { return ( \$GLOBALS['eb_impl']['{$name}'] )( ...\$a ); }" );
		}
	}

	/** Pass-table fake: answers the existing-key lookup and records inserts. */
	final class EventbriteImportWpdb {
		public $prefix = 'wp_';
		public $rows   = array();
		public function prepare( $sql, ...$args ) {
			return array( $sql, $args );
		}
		public function get_var( $query ) {
			list( , $args ) = $query;
			foreach ( $this->rows as $row ) {
				if ( $row['event_id'] === $args[0] && $row['idempotency_key'] === $args[1] ) {
					return 1;
				}
			}
			return null;
		}
		public function insert( $table, $data ) {
			$this->rows[] = $data;
			return 1;
		}
	}

	require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
	require_once dirname( __DIR__, 3 ) . '/inc/core/eventbrite-attendee-import.php';
}

namespace ExtraChillEvents\Tests\Unit\Core {
	use PHPUnit\Framework\TestCase;

	final class EventbriteAttendeeImportTest extends TestCase {
		const SUMMARY = "\xEF\xBB\xBFOrder #,Order Date,First Name,Last Name,Email,Quantity,Ticket Type,Attendee #,Attendee Status\r\n"
			. "111,2026-11-01,Ada,Lovelace,ada@example.com,1,GA,9001,Attending\r\n"
			. "111,2026-11-01,Grace,Hopper,ada@example.com,1,GA,9002,Attending\r\n"
			. "222,2026-11-02,Cy,Refund,cy@example.com,1,GA,9003,Not Attending\r\n"
			. "333,2026-11-03,\"Smith, Jr.\",Bo,bo@example.com,1,GA,9004,Checked In\r\n"
			. ",,,,,,,,\r\n";

		protected function setUp(): void {
			$GLOBALS['wpdb']      = new \EventbriteImportWpdb();
			$GLOBALS['eb_perk']   = true;
			$GLOBALS['eb_purged'] = array();
		}

		public function test_attendee_summary_yields_one_ticket_per_attending_row() {
			$parsed = extrachill_events_parse_eventbrite_attendees( self::SUMMARY );

			$this->assertSame(
				array(
					array( 'key' => 'attendee:9001', 'name' => 'Ada Lovelace' ),
					array( 'key' => 'attendee:9002', 'name' => 'Grace Hopper' ),
					array( 'key' => 'attendee:9004', 'name' => 'Smith, Jr. Bo' ),
				),
				$parsed['tickets']
			);
			$this->assertSame( 1, $parsed['skipped'] );
		}

		public function test_order_report_without_attendee_ids_expands_quantity() {
			$parsed = extrachill_events_parse_eventbrite_attendees( "Order #,Buyer Name,Quantity\n555,Dee Dee,3\n" );

			$this->assertSame( array( 'order:555:1', 'order:555:2', 'order:555:3' ), array_column( $parsed['tickets'], 'key' ) );
			$this->assertSame( array( 'Dee Dee' ), array_values( array_unique( array_column( $parsed['tickets'], 'name' ) ) ) );
		}

		public function test_unrecognized_or_empty_files_are_refused() {
			$this->assertSame( 'eventbrite_import_unrecognized', extrachill_events_parse_eventbrite_attendees( "foo,bar\n1,2\n" )->get_error_code() );
			$this->assertSame( 'eventbrite_import_empty', extrachill_events_parse_eventbrite_attendees( "  \n" )->get_error_code() );
		}

		public function test_import_adds_passes_without_emails_and_reimport_only_adds_new() {
			$first = extrachill_events_import_eventbrite_attendees( 42, self::SUMMARY, 7 );

			$this->assertSame( array( 'added' => 3, 'already_listed' => 0, 'skipped' => 1 ), $first );
			$rows = $GLOBALS['wpdb']->rows;
			$this->assertSame( 'eventbrite:42:attendee:9001', $rows[0]['idempotency_key'] );
			$this->assertSame( 'Ada Lovelace', $rows[0]['guest_name'] );
			$this->assertSame( 'active', $rows[0]['status'] );
			$this->assertSame( 7, $rows[0]['issued_by_user_id'] );
			$this->assertArrayNotHasKey( 'order_reference', $rows[0], 'Imported passes must not look like Extra Chill ticket sales.' );
			$this->assertStringNotContainsString( '@', wp_json_encode( $rows ) );
			$this->assertTrue( extrachill_events_pass_is_eventbrite_import( $rows[0] ) );
			$this->assertSame( array( 42 ), $GLOBALS['eb_purged'] );

			$newer  = self::SUMMARY . "444,2026-11-04,Dot,New,d@example.com,1,GA,9005,Attending\r\n";
			$second = extrachill_events_import_eventbrite_attendees( 42, $newer, 7 );
			$this->assertSame( array( 'added' => 1, 'already_listed' => 3, 'skipped' => 1 ), $second );
			$this->assertCount( 4, $GLOBALS['wpdb']->rows );
		}

		public function test_import_needs_a_door_list() {
			$GLOBALS['eb_perk'] = false;
			$this->assertSame( 'guest_list_unavailable', extrachill_events_import_eventbrite_attendees( 42, self::SUMMARY, 7 )->get_error_code() );
			$this->assertSame( array(), $GLOBALS['wpdb']->rows );
		}
	}
}
