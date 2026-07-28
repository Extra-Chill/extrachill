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
				BookingSchema::ticket_sources_table(),
				BookingSchema::sales_reports_table(),
				BookingSchema::sales_resolutions_table(),
				BookingSchema::settlements_table(),
			);

			$this->assertSame( $prefix, $wpdb->prefix );
			$this->assertTrue( BookingSchema::is_ready() );
			$this->assertTrue( BookingSchema::health() );
			$this->assertCount( 14, $tables );
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

	/** Verify explicit v13 identity/provenance migration preserves every legacy row. */
	public function test_v13_ticket_provenance_upgrade_preserves_rows(): void {
		global $wpdb;

		switch_to_blog( 7 );
		try {
			$table       = BookingSchema::sales_reports_table();
			$sources     = BookingSchema::ticket_sources_table();
			$external_id = 'Legacy/Report-é-' . wp_generate_uuid4();
			$source_key  = 'Legacy/Case-é-' . wp_generate_uuid4();
			$wpdb->query( "ALTER TABLE `{$sources}` DROP INDEX `booking_provider_source`, DROP COLUMN `source_key_hash`, ADD UNIQUE KEY `booking_provider_source` (`booking_id`, `provider`, `source_key`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the exact v13 source contract in a disposable test database.
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `provider_external_report`, DROP COLUMN `external_report_id_hash`, DROP COLUMN `provenance_version`, DROP COLUMN `ticket_source_request_hash`, DROP COLUMN `evidence_attachment_request_hash`, DROP COLUMN `evidence_content_hash`, DROP COLUMN `evidence_byte_size`, ADD UNIQUE KEY `provider_external_report` (`booking_id`, `provider`, `external_report_id`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the exact v13 report contract in a disposable test database.
			$this->assertSame( '', (string) $wpdb->last_error );
			$this->assertSame(
				1,
				$wpdb->insert(
					$sources,
					array(
						'public_id'          => wp_generate_uuid4(),
						'booking_id'         => 987654,
						'event_id'           => 876543,
						'venue_term_id'      => 765432,
						'provider'           => 'legacy-provider',
						'source_key'         => $source_key,
						'canonical_url'      => 'https://tickets.example.test/legacy',
						'url_hash'           => hash( 'sha256', 'https://tickets.example.test/legacy' ),
						'request_hash'       => str_repeat( 'b', 64 ),
						'created_by_user_id' => 1,
						'created_at'         => '2026-07-01 00:00:00',
					)
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Disposable migration fixture.
			$inserted = $wpdb->insert(
				$table,
				array(
					'booking_id'         => 987654,
					'event_id'           => 876543,
					'venue_term_id'      => 765432,
					'provider'           => 'legacy-provider',
					'external_report_id' => $external_id,
					'source_type'        => 'manual',
					'period_start'       => '2026-07-01 00:00:00',
					'period_end'         => '2026-07-01 23:59:59',
					'tickets_sold'       => 1,
					'tickets_refunded'   => 0,
					'gross_minor'        => 100,
					'fees_minor'         => 0,
					'tax_minor'          => 0,
					'refunds_minor'      => 0,
					'net_minor'          => 100,
					'currency'           => 'USD',
					'source_payload'     => '{"version":1,"data":{}}',
					'request_hash'       => str_repeat( 'a', 64 ),
					'created_by_user_id' => 1,
					'created_at'         => '2026-07-01 00:00:00',
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Disposable migration fixture.
			$this->assertSame( 1, $inserted );
			update_option( BookingSchema::VERSION_OPTION, '13', false );

			$this->assertTrue( BookingSchema::maybe_install() );
			$this->assertSame( '14', get_option( BookingSchema::VERSION_OPTION ) );
			$this->assertSame( '987654', $wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM `{$table}` WHERE external_report_id = %s", $external_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms migration preserved the fixture.
			$this->assertSame( hash( 'sha256', $external_id ), $wpdb->get_var( $wpdb->prepare( "SELECT external_report_id_hash FROM `{$table}` WHERE external_report_id = %s", $external_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms exact report identity backfill.
			$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT provenance_version FROM `{$table}` WHERE external_report_id = %s", $external_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy hashes remain versioned and immutable.
			$this->assertSame( hash( 'sha256', $source_key ), $wpdb->get_var( $wpdb->prepare( "SELECT source_key_hash FROM `{$sources}` WHERE source_key = %s", $source_key ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms exact source identity backfill.

			$index_rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'provider_external_report'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies the repaired real-MySQL index.
			usort( $index_rows, static fn( array $left, array $right ): int => (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index'] );
			$this->assertSame( array( 'booking_id', 'provider', 'external_report_id_hash' ), array_column( $index_rows, 'Column_name' ) );
		} finally {
			restore_current_blog();
		}
	}
}
