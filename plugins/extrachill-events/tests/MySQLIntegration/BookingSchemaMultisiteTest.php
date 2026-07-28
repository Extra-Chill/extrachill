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
				BookingSchema::show_settlements_table(),
				BookingSchema::show_settlement_actions_table(),
			);

			$this->assertSame( $prefix, $wpdb->prefix );
			$this->assertTrue( BookingSchema::is_ready() );
			$this->assertTrue( BookingSchema::health() );
			$this->assertCount( 16, $tables );
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

	/** Verify explicit v13 identity/provenance migration preserves every legacy table. */
	public function test_v13_ticket_provenance_upgrade_preserves_rows(): void {
		global $wpdb;

		switch_to_blog( 7 );
		try {
			$reports     = BookingSchema::sales_reports_table();
			$sources     = BookingSchema::ticket_sources_table();
			$external_id = 'Legacy/Report-é-' . wp_generate_uuid4();
			$source_key  = 'Legacy/Case-é-' . wp_generate_uuid4();
			$wpdb->query( "ALTER TABLE `{$sources}` DROP INDEX `booking_provider_source`, DROP COLUMN `source_key_hash`, ADD UNIQUE KEY `booking_provider_source` (`booking_id`, `provider`, `source_key`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the exact v13 source contract in a disposable test database.
			$wpdb->query( "ALTER TABLE `{$reports}` DROP INDEX `provider_external_report`, DROP COLUMN `external_report_id_hash`, DROP COLUMN `provenance_version`, DROP COLUMN `ticket_source_request_hash`, DROP COLUMN `evidence_attachment_request_hash`, DROP COLUMN `evidence_content_hash`, DROP COLUMN `evidence_byte_size`, ADD UNIQUE KEY `provider_external_report` (`booking_id`, `provider`, `external_report_id`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the exact v13 report contract in a disposable test database.
			$this->assertSame( '', (string) $wpdb->last_error );
			$fixtures  = $this->v13_fixture_rows( $external_id, $source_key );
			$snapshots = $this->seed_and_snapshot_v13_rows( $fixtures );
			$this->assertCount( 14, $snapshots );
			update_option( BookingSchema::VERSION_OPTION, '13', false );

			$this->assertTrue( BookingSchema::maybe_install() );
			$this->assertSame( '15', get_option( BookingSchema::VERSION_OPTION ) );
			$this->assert_v13_snapshots_survive( $fixtures, $snapshots );
			$this->assert_migrated_financial_graph_readable();
			$this->assertSame( '73001', $wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM `{$reports}` WHERE external_report_id = %s", $external_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms migration preserved the fixture.
			$this->assertSame( hash( 'sha256', $external_id ), $wpdb->get_var( $wpdb->prepare( "SELECT external_report_id_hash FROM `{$reports}` WHERE external_report_id = %s", $external_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms exact report identity backfill.
			$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT provenance_version FROM `{$reports}` WHERE external_report_id = %s", $external_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy hashes remain versioned and immutable.
			$this->assertSame( hash( 'sha256', $source_key ), $wpdb->get_var( $wpdb->prepare( "SELECT source_key_hash FROM `{$sources}` WHERE source_key = %s", $source_key ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms exact source identity backfill.
			$this->assertSame( '73110', $wpdb->get_var( "SELECT ticket_source_id FROM `{$reports}` WHERE id = 73111" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immutable source relationship survives dbDelta.
			$deliveries = BookingSchema::attachment_deliveries_table();
			$this->assertSame( '73103', $wpdb->get_var( "SELECT attachment_id FROM `{$deliveries}` WHERE id = 73104" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private-delivery attachment relationship survives dbDelta.
			$resolutions = BookingSchema::sales_resolutions_table();
			$settlements = BookingSchema::settlements_table();
			$this->assertSame( '73111', $wpdb->get_var( "SELECT report_id FROM `{$resolutions}` WHERE id = 73112" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolution remains bound to its immutable report.
			$this->assertSame( '[73111]', $wpdb->get_var( "SELECT included_report_ids FROM `{$settlements}` WHERE id = 73113" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy settlement evidence remains frozen.
			$this->assertSame( '2', $wpdb->get_var( "SELECT formula_version FROM `{$settlements}` WHERE id = 73113" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Formula-v2 settlement remains immutable.

			$index_rows = $wpdb->get_results( "SHOW INDEX FROM `{$reports}` WHERE Key_name = 'provider_external_report'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies the repaired real-MySQL index.
			usort( $index_rows, static fn( array $left, array $right ): int => (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index'] );
			$this->assertSame( array( 'booking_id', 'provider', 'external_report_id_hash' ), array_column( $index_rows, 'Column_name' ) );
		} finally {
			restore_current_blog();
		}
	}

	/** Return one coherent representative v13 row for every booking table. */
	private function v13_fixture_rows( string $external_id, string $source_key ): array {
		$created        = '2026-07-01 00:00:00';
		$reconciliation = new ExtraChillEvents\Core\TicketReconciliationService();
		$settlements    = new ExtraChillEvents\Core\TicketSettlementService();
		$row_hash       = new ReflectionMethod( ExtraChillEvents\Core\TicketReconciliationService::class, 'hash' );
		$row_hash->setAccessible( true );
		$source_row                 = array( 'id' => 73110, 'public_id' => wp_generate_uuid4(), 'booking_id' => 73001, 'event_id' => 73002, 'venue_term_id' => 73003, 'provider' => 'legacy-provider', 'source_key' => $source_key, 'canonical_url' => 'https://tickets.example.test/legacy', 'url_hash' => hash( 'sha256', 'https://tickets.example.test/legacy' ), 'created_by_user_id' => 1, 'created_at' => $created );
		$source_row['request_hash'] = $row_hash->invoke( $reconciliation, $source_row, array( 'booking_id', 'event_id', 'venue_term_id', 'provider', 'source_key', 'canonical_url' ) );
		$report_source               = array( 'certificate' => 'legacy-v13-proof' );
		$report_row                  = array( 'id' => 73111, 'booking_id' => 73001, 'event_id' => 73002, 'venue_term_id' => 73003, 'ticket_source_id' => 73110, 'evidence_attachment_id' => null, 'provider' => 'legacy-provider', 'external_report_id' => $external_id, 'source_type' => 'manual', 'period_start' => $created, 'period_end' => '2026-07-01 23:59:59', 'tickets_sold' => 10, 'tickets_refunded' => 1, 'gross_minor' => 10000, 'fees_minor' => 500, 'tax_minor' => 300, 'refunds_minor' => 1000, 'net_minor' => 8200, 'currency' => 'USD', 'source_payload' => wp_json_encode( array( 'version' => 1, 'data' => $report_source ) ), 'created_by_user_id' => 1, 'created_at' => $created );
		$report_hashable             = $report_row;
		$report_hashable['source']   = $report_source;
		$report_row['request_hash']  = ExtraChillEvents\Core\TicketSettlementService::report_request_hash( $report_hashable );
		$resolution_row              = array( 'id' => 73112, 'public_id' => wp_generate_uuid4(), 'booking_id' => 73001, 'report_id' => 73111, 'venue_term_id' => 73003, 'version' => 1, 'decision' => 'admit', 'ticket_source_id' => 73110, 'supersedes_resolution_id' => null, 'reason' => 'Legacy certified evidence accepted.', 'created_by_user_id' => 1, 'created_at' => $created );
		$resolution_row['request_hash'] = $row_hash->invoke( $reconciliation, $resolution_row, array( 'booking_id', 'report_id', 'venue_term_id', 'version', 'decision', 'ticket_source_id', 'supersedes_resolution_id', 'reason', 'created_by_user_id', 'created_at' ) );
		$evidence                    = array(
			array(
				'id'           => 73111,
				'request_hash' => $report_row['request_hash'],
				'resolution'   => array( 'id' => 73112, 'version' => 1, 'decision' => 'admit', 'ticket_source_id' => 73110, 'request_hash' => $resolution_row['request_hash'] ),
			),
		);
		$evidence_hash               = new ReflectionMethod( ExtraChillEvents\Core\TicketSettlementService::class, 'evidence_hash' );
		$evidence_hash->setAccessible( true );
		$settlement_row              = array( 'id' => 73113, 'booking_id' => 73001, 'event_id' => 73002, 'venue_term_id' => 73003, 'status' => 'paid', 'version' => 2, 'booking_version' => 7, 'basis' => 'gross_ticket_sales', 'basis_points' => 2000, 'currency' => 'USD', 'formula_version' => 2, 'included_report_ids' => '[73111]', 'evidence_hash' => $evidence_hash->invoke( $settlements, $evidence ), 'basis_amount_minor' => 10000, 'adjustment_minor' => 0, 'amount_due_minor' => 2000, 'finalized_by_user_id' => 1, 'finalized_at' => '2026-07-02 00:00:00', 'paid_by_user_id' => 1, 'paid_at' => '2026-07-03 00:00:00', 'payment_reference' => 'legacy-payment-reference', 'created_at' => '2026-07-02 00:00:00', 'updated_at' => '2026-07-03 00:00:00' );
		$integrity_hash              = new ReflectionMethod( ExtraChillEvents\Core\TicketSettlementService::class, 'settlement_integrity_hash' );
		$integrity_hash->setAccessible( true );
		$settlement_row['integrity_hash'] = $integrity_hash->invoke( $settlements, $settlement_row );
		return array(
			BookingSchema::bookings_table()              => array(
				'key' => 'id',
				'row' => array(
					'id' => 73001, 'public_id' => wp_generate_uuid4(), 'venue_term_id' => 73003, 'artist_name' => 'Legacy Migration Artist', 'contact_email' => 'legacy@example.test', 'inquiry_idempotency_key' => 'legacy-migration-inquiry', 'inquiry_request_hash' => str_repeat( '1', 64 ), 'space_key' => 'main-room', 'status' => 'completed', 'version' => 7, 'intake_payload' => '{"version":1,"data":{"legacy":true}}', 'event_id' => 73002, 'created_at' => $created, 'updated_at' => '2026-07-02 00:00:00',
				),
			),
			BookingSchema::activity_table()              => array(
				'key' => 'id',
				'row' => array(
					'id' => 73101, 'booking_id' => 73001, 'kind' => 'legacy_notification', 'actor_type' => 'user', 'actor_id' => 1, 'direction' => 'outbound', 'channel' => 'email', 'communication_intent_id' => 73102, 'is_communication' => 1, 'payload' => '{"version":1,"data":{"subject":"Legacy"}}', 'external_id' => 'legacy-message-id', 'idempotency_key' => 'legacy-activity', 'occurred_at' => $created, 'created_at' => $created,
				),
			),
			BookingSchema::communication_state_table()   => array(
				'key' => 'intent_id',
				'row' => array( 'intent_id' => 73102, 'booking_id' => 73001, 'status' => 'delivered', 'claim_stage' => 'completed', 'action_id' => 73120, 'updated_activity_id' => 73101, 'created_at' => $created, 'updated_at' => '2026-07-01 00:05:00' ),
			),
			BookingSchema::attachments_table()           => array(
				'key' => 'id',
				'row' => array(
					'id' => 73103, 'public_id' => wp_generate_uuid4(), 'booking_id' => 73001, 'uploader_type' => 'user', 'uploader_user_id' => 1, 'purpose' => 'other_private_evidence', 'original_filename' => 'legacy-sales.csv', 'mime_type' => 'text/csv', 'byte_size' => 128, 'content_hash' => str_repeat( '2', 64 ), 'storage_reference' => 'legacy-private-reference', 'state' => 'active', 'idempotency_key' => 'legacy-attachment', 'request_hash' => str_repeat( '3', 64 ), 'created_at' => $created, 'updated_at' => $created,
				),
			),
			BookingSchema::attachment_deliveries_table() => array(
				'key' => 'id',
				'row' => array( 'id' => 73104, 'correlation_id' => wp_generate_uuid4(), 'booking_id' => 73001, 'attachment_id' => 73103, 'actor_id' => 1, 'expected_bytes' => 128, 'state' => 'completed', 'outcome' => 'complete', 'bytes_sent' => 128, 'issued_at' => $created, 'consumed_at' => '2026-07-01 00:01:00', 'terminal_at' => '2026-07-01 00:02:00', 'updated_at' => '2026-07-01 00:02:00' ),
			),
			BookingSchema::memberships_table()           => array(
				'key' => 'id',
				'row' => array( 'id' => 73105, 'venue_term_id' => 73003, 'user_id' => 1, 'is_owner' => 1, 'status' => 'active', 'version' => 3, 'created_by_user_id' => 1, 'created_at' => $created, 'updated_at' => '2026-07-02 00:00:00' ),
			),
			BookingSchema::claims_table()                => array(
				'key' => 'id',
				'row' => array( 'id' => 73106, 'public_id' => wp_generate_uuid4(), 'venue_term_id' => 73003, 'claimant_user_id' => 2, 'status' => 'approved', 'version' => 2, 'reviewed_by_user_id' => 1, 'created_at' => $created, 'updated_at' => '2026-07-02 00:00:00', 'resolved_at' => '2026-07-02 00:00:00' ),
			),
			BookingSchema::invitations_table()           => array(
				'key' => 'id',
				'row' => array(
					'id' => 73107, 'public_id' => wp_generate_uuid4(), 'venue_term_id' => 73003, 'user_id' => 3, 'is_owner' => 0, 'status' => 'accepted', 'token_hash' => str_repeat( '4', 64 ), 'email_hash' => str_repeat( '5', 64 ), 'account_created' => 1, 'delivery_id' => wp_generate_uuid4(), 'delivery_status' => 'delivered', 'delivery_attempts' => 1, 'version' => 2, 'invited_by_user_id' => 1, 'created_at' => $created, 'updated_at' => '2026-07-02 00:00:00', 'expires_at' => '2026-08-01 00:00:00', 'resolved_at' => '2026-07-02 00:00:00', 'delivered_at' => '2026-07-01 00:01:00',
				),
			),
			BookingSchema::onboarding_audit_table()      => array(
				'key' => 'id',
				'row' => array( 'id' => 73108, 'venue_term_id' => 73003, 'entity_type' => 'membership', 'entity_id' => 73105, 'event' => 'legacy_membership_created', 'actor_user_id' => 1, 'subject_user_id' => 1, 'payload' => '{"version":1,"data":{"owner":true}}', 'created_at' => $created ),
			),
			BookingSchema::holds_table()                 => array(
				'key' => 'id',
				'row' => array( 'id' => 73109, 'booking_id' => 73001, 'venue_term_id' => 73003, 'space_key' => 'main-room', 'start_at' => '2026-08-01 18:00:00', 'end_at' => '2026-08-01 23:00:00', 'expires_at' => '2026-07-02 00:00:00', 'status' => 'converted', 'version' => 2, 'created_by_user_id' => 1, 'created_at' => $created, 'updated_at' => '2026-07-02 00:00:00', 'converted_at' => '2026-07-02 00:00:00', 'converted_by_user_id' => 1 ),
			),
			BookingSchema::ticket_sources_table()        => array(
				'key' => 'id',
				'row' => $source_row,
			),
			BookingSchema::sales_reports_table()         => array(
				'key' => 'id',
				'row' => $report_row,
			),
			BookingSchema::sales_resolutions_table()     => array(
				'key' => 'id',
				'row' => $resolution_row,
			),
			BookingSchema::settlements_table()           => array(
				'key' => 'id',
				'row' => $settlement_row,
			),
		);
	}

	/** Seed and snapshot the exact legacy columns before dbDelta runs. */
	private function seed_and_snapshot_v13_rows( array $fixtures ): array {
		global $wpdb;
		$snapshots = array();
		foreach ( $fixtures as $table => $fixture ) {
			$this->assertSame( 1, $wpdb->insert( $table, $fixture['row'] ), $table . ': ' . $wpdb->last_error ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Disposable migration fixture.
			$snapshots[ $table ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$fixture['key']}` = %d", $fixture['row'][ $fixture['key'] ] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Captures every v13 field before migration.
			$this->assertIsArray( $snapshots[ $table ], $table );
		}
		return $snapshots;
	}

	/** Verify every legacy column remains byte-for-byte equal after migration. */
	private function assert_v13_snapshots_survive( array $fixtures, array $snapshots ): void {
		global $wpdb;
		foreach ( $snapshots as $table => $before ) {
			$fixture = $fixtures[ $table ];
			$after   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$fixture['key']}` = %d", $fixture['row'][ $fixture['key'] ] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the migrated representative row.
			$this->assertIsArray( $after, $table . ' row was lost during migration.' );
			foreach ( $before as $column => $value ) {
				$this->assertSame( $value, $after[ $column ] ?? null, $table . '.' . $column . ' changed during migration.' );
			}
		}
	}

	/** Prove migrated immutable hashes still hydrate and formula-v2 evidence verifies. */
	private function assert_migrated_financial_graph_readable(): void {
		global $wpdb;
		$reconciliation = new ExtraChillEvents\Core\TicketReconciliationService();
		$settlements    = new ExtraChillEvents\Core\TicketSettlementService();
		$source_row     = $wpdb->get_row( 'SELECT * FROM `' . BookingSchema::ticket_sources_table() . '` WHERE id = 73110', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable migrated fixture read.
		$report_row     = $wpdb->get_row( 'SELECT * FROM `' . BookingSchema::sales_reports_table() . '` WHERE id = 73111', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable migrated fixture read.
		$settlement_row = $wpdb->get_row( 'SELECT * FROM `' . BookingSchema::settlements_table() . '` WHERE id = 73113', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable migrated fixture read.
		$hydrate_source = new ReflectionMethod( ExtraChillEvents\Core\TicketReconciliationService::class, 'hydrate_source' );
		$hydrate_report = new ReflectionMethod( ExtraChillEvents\Core\TicketSettlementService::class, 'hydrate_report' );
		$hydrate_settlement = new ReflectionMethod( ExtraChillEvents\Core\TicketSettlementService::class, 'hydrate_settlement' );
		$verify_evidence = new ReflectionMethod( ExtraChillEvents\Core\TicketSettlementService::class, 'verify_settlement_evidence' );
		foreach ( array( $hydrate_source, $hydrate_report, $hydrate_settlement, $verify_evidence ) as $method ) {
			$method->setAccessible( true );
		}
		$source     = $hydrate_source->invoke( $reconciliation, $source_row );
		$report     = $hydrate_report->invoke( $settlements, $report_row );
		$settlement = $hydrate_settlement->invoke( $settlements, $settlement_row );
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$this->assertIsArray( $report, is_wp_error( $report ) ? $report->get_error_code() : '' );
		$this->assertIsArray( $settlement, is_wp_error( $settlement ) ? $settlement->get_error_code() : '' );
		$this->assertSame( array(), $verify_evidence->invoke( $settlements, $settlement, false, 1, null, false ) );
	}
}
