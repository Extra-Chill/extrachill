<?php
/** Independent venue schema ownership and upgrade proofs. */
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueSchema;

require_once __DIR__ . '/../../extrachill-booking/tests/Support/BookingTestHarness.php';

final class VenueSchemaTest extends BookingTestCase {
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array( 'options' => array(), 'dbdelta' => array() );
		$GLOBALS['wpdb'] = new BookingWpdb();
	}

	public function test_fresh_install_owns_only_four_venue_tables(): void {
		$this->assertTrue( VenueSchema::maybe_install() );
		$this->assertTrue( VenueSchema::is_ready() );
		$this->assertFalse( BookingSchema::is_ready() );
		$this->assertCount( 4, $GLOBALS['wpdb']->schemas );
		foreach ( array_keys( $GLOBALS['wpdb']->schemas ) as $table ) {
			$this->assertStringStartsWith( 'wp_7_ec_venue_', $table );
		}
		$this->assertTrue( VenueSchema::health() );
	}

	public function test_existing_combined_install_is_adopted_without_ddl_or_data_changes(): void {
		$this->assertTrue( BookingSchema::install() );
		$this->assertTrue( VenueSchema::install() );
		$table = VenueSchema::memberships_table();
		$GLOBALS['wpdb']->rows[ $table ][1] = array( 'id' => 1, 'user_id' => 42 );
		$rows = $GLOBALS['wpdb']->rows;
		$schemas = $GLOBALS['wpdb']->schemas;
		delete_option( VenueSchema::VERSION_OPTION );
		$GLOBALS['ec_artist_test']['dbdelta'] = array();
		$this->assertTrue( VenueSchema::maybe_install() );
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( array(), $GLOBALS['ec_artist_test']['dbdelta'] );
		$this->assertSame( $rows, $GLOBALS['wpdb']->rows );
		$this->assertSame( $schemas, $GLOBALS['wpdb']->schemas );
		$this->assertTrue( VenueSchema::is_ready() );
	}

	public function test_booking_version_does_not_skip_venue_creation_or_stamp_failed_install(): void {
		update_option( BookingSchema::VERSION_OPTION, BookingSchema::SCHEMA_VERSION );
		$GLOBALS['wpdb']->schema_omit = array( VenueSchema::claims_table() => array( 'columns' => array( 'public_id' ) ) );
		$this->assertInstanceOf( WP_Error::class, VenueSchema::maybe_install() );
		$this->assertFalse( VenueSchema::is_ready() );
		$this->assertTrue( BookingSchema::is_ready() );
		$this->assertSame( 'venue_schema_columns_missing', get_option( VenueSchema::FAILURE_OPTION )['code'] );
		$GLOBALS['wpdb']->schema_omit = array();
		$this->assertTrue( VenueSchema::maybe_install() );
		$this->assertFalse( get_option( VenueSchema::FAILURE_OPTION, false ) );
	}
}
