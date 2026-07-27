<?php
/**
 * Booking schema coverage on the canonical Events multisite blog.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

use ExtraChillEvents\Core\BookingSchema;

/** Proves the managed bootstrap installs the site-scoped schema idempotently. */
final class BookingSchemaMultisiteTest extends WP_UnitTestCase {

	/** Verify every table belongs to blog 7 and survives a repeated install. */
	public function test_installs_and_verifies_current_prefix_tables_after_switch(): void {
		global $wpdb;

		$this->assertTrue( is_multisite() );
		$this->assertSame( 1, get_current_blog_id() );

		switch_to_blog( 7 );
		try {
			$prefix = $wpdb->get_blog_prefix( 7 );
			$tables = array(
				BookingSchema::bookings_table(),
				BookingSchema::activity_table(),
				BookingSchema::communication_state_table(),
				BookingSchema::attachments_table(),
				BookingSchema::attachment_deliveries_table(),
				BookingSchema::memberships_table(),
				BookingSchema::claims_table(),
				BookingSchema::invitations_table(),
				BookingSchema::onboarding_audit_table(),
				BookingSchema::holds_table(),
				BookingSchema::sales_reports_table(),
				BookingSchema::settlements_table(),
			);

			$this->assertSame( $prefix, $wpdb->prefix );
			$this->assertTrue( BookingSchema::is_ready() );
			$this->assertTrue( BookingSchema::health() );
			$this->assertCount( 12, $tables );
			foreach ( $tables as $table ) {
				$this->assertStringStartsWith( $prefix, $table );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema integration assertion.
				$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
			}

			$this->assertTrue( BookingSchema::install() );
			$this->assertTrue( BookingSchema::health() );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 1, get_current_blog_id() );
	}
}
