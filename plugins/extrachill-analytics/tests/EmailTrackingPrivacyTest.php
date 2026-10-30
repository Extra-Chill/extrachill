<?php
/**
 * Tests for privacy-safe email outcome tracking.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once __DIR__ . '/class-email-privacy-wpdb-fixture.php';

/**
 * Verify bounded payload helpers and privacy contracts.
 *
 * The advisory-lock cleanup tests swap in the wpdb fixture double and restore
 * the real $wpdb in tear_down(): GET_LOCK()/RELEASE_LOCK() are MySQL-only
 * advisory mutexes the SQLite harness database cannot execute, and per-query
 * failure injection needs a deterministic surface no SQLite query can produce
 * on demand. Export/erasure tests run against the real database.
 */
final class EmailTrackingPrivacyTest extends Extrachill_Analytics_TestCase {
	/**
	 * WPDB value restored after each test.
	 *
	 * @var mixed
	 */
	protected $original_wpdb;

	/**
	 * Reset network, cron, user, and database fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_wpdb = $GLOBALS['wpdb'];
		wp_clear_scheduled_hook( 'extrachill_analytics_email_cleanup' );
		wp_clear_scheduled_hook( 'extrachill_analytics_email_cleanup_continue' );
		delete_site_option( EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR );
	}

	/**
	 * Restore the prior wpdb value and cron state.
	 */
	public function tear_down(): void {
		if ( null !== $this->original_wpdb && $GLOBALS['wpdb'] !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		wp_clear_scheduled_hook( 'extrachill_analytics_email_cleanup' );
		wp_clear_scheduled_hook( 'extrachill_analytics_email_cleanup_continue' );

		parent::tear_down();
	}

	/**
	 * Swap in the advisory-lock wpdb double for one test.
	 */
	private function install_lock_wpdb_fixture(): Email_Privacy_Wpdb_Fixture {
		$wpdb                    = new Email_Privacy_Wpdb_Fixture();
		$GLOBALS['wpdb']         = $wpdb;
		return $wpdb;
	}

	/**
	 * Insert one email event row for a user.
	 *
	 * @param int    $user_id    Owner user ID.
	 * @param string $event_type Event type.
	 * @param int    $blog_id    Owning blog.
	 * @return int Row ID.
	 */
	private function email_event( $user_id, $event_type = 'email_sent', $blog_id = 7 ): int {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => $event_type,
				'event_data' => 'email_failed' === $event_type ? '{"error_code":"smtp_failed"}' : '{}',
				'source_url' => '',
				'blog_id'    => $blog_id,
				'user_id'    => $user_id,
				'created_at' => '2026-07-17 12:00:00',
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Recipient addresses become only a bounded count.
	 */
	public function test_recipient_count_does_not_retain_addresses(): void {
		$this->assertSame( 2, extrachill_analytics_email_recipient_count( 'one@example.com, two@example.com' ) );
		$this->assertSame( 2, extrachill_analytics_email_recipient_count( array( 'one@example.com', 'two@example.com' ) ) );
		$this->assertSame( 100, extrachill_analytics_email_recipient_count( array_fill( 0, 150, 'person@example.com' ) ) );
	}

	/**
	 * Operational context is restricted to a short identifier.
	 */
	public function test_context_is_bounded_and_normalized(): void {
		$this->assertSame( 'theme:extrachill', extrachill_analytics_normalize_email_context( 'Theme:Extra Chill' ) );
		$this->assertSame( 64, strlen( extrachill_analytics_normalize_email_context( str_repeat( 'a', 100 ) ) ) );
	}

	/**
	 * Source contract excludes the prior direct-PII payload fields.
	 */
	public function test_tracking_payload_excludes_direct_pii(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/email-tracking.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$this->assertStringNotContainsString( "'subject' =>", $source );
		$this->assertStringNotContainsString( "'to'      =>", $source );
		$this->assertStringNotContainsString( 'get_error_message()', $source );
		$this->assertStringContainsString( "'recipient_count'", $source );
		$this->assertStringContainsString( "'error_code'", $source );
	}

	/**
	 * Retention and Core privacy hooks remain registered.
	 */
	public function test_retention_and_privacy_contracts_are_registered(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/email-tracking.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$this->assertStringContainsString( 'EXTRACHILL_ANALYTICS_EMAIL_EVENT_RETENTION_DAYS = 30', $source );
		$this->assertStringContainsString( "EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_HOOK         = 'extrachill_analytics_email_cleanup'", $source );
		$this->assertStringContainsString( "add_filter( 'wp_privacy_personal_data_exporters'", $source );
		$this->assertStringContainsString( "add_filter( 'wp_privacy_personal_data_erasers'", $source );
	}

	/**
	 * Only the main site owns the shared-table cron and privacy callbacks.
	 */
	public function test_main_site_is_network_authority(): void {
		extrachill_analytics_schedule_email_cleanup();
		$this->assertNotFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup' ) );
		$this->assertArrayHasKey( 'extrachill-email-analytics', extrachill_analytics_register_email_event_exporter( array() ) );
		$this->assertArrayHasKey( 'extrachill-email-analytics', extrachill_analytics_register_email_event_eraser( array() ) );

		$this->assertNotFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup' ) );
		wp_clear_scheduled_hook( 'extrachill_analytics_email_cleanup' );
		$this->assertFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup' ) );

		$site_id = $this->create_blog( 'news.example.org' );
		switch_to_blog( $site_id );

		try {
			extrachill_analytics_schedule_email_cleanup();
			$this->assertSame( array(), extrachill_analytics_register_email_event_exporter( array() ) );
			$this->assertSame( array(), extrachill_analytics_register_email_event_eraser( array() ) );
			$this->assertSame(
				array(
					'data' => array(),
					'done' => true,
				),
				extrachill_analytics_email_event_exporter( 'person@example.com', 1 )
			);
		} finally {
			restore_current_blog();
		}

		// The non-main site must not have created the shared-table cron event.
		$this->assertFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup' ) );
	}

	/**
	 * Full batches continue under a network lock until the backlog drains.
	 */
	public function test_cleanup_schedules_bounded_continuation_and_prevents_overlap(): void {
		$wpdb = $this->install_lock_wpdb_fixture();

		$wpdb->var_results   = array( 1, 1 );
		$wpdb->query_results = array( 1000, 25, 0 );
		$this->assertTrue( extrachill_analytics_cleanup_email_events() );
		$this->assertNotEmpty( $wpdb->queries );
		$this->assertStringContainsString( "GET_LOCK('extrachill_analytics_email_cleanup_1', 0)", $wpdb->queries[0] );
		$this->assertStringContainsString( "RELEASE_LOCK('extrachill_analytics_email_cleanup_1')", end( $wpdb->queries ) );
		$this->assertNotFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup_continue' ) );

		$wpdb->queries     = array();
		$wpdb->var_results = array( 0 );
		wp_clear_scheduled_hook( 'extrachill_analytics_email_cleanup_continue' );
		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$this->assertCount( 1, $wpdb->queries, 'A failed lock acquisition must not run the batch.' );
		$this->assertFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup_continue' ) );
	}

	/**
	 * Advisory lock acquisition failures surface and schedule a retry.
	 */
	public function test_advisory_lock_acquisition_failure_is_reported(): void {
		$wpdb = $this->install_lock_wpdb_fixture();

		$wpdb->var_results = array( null );
		$wpdb->var_errors  = array( 'GET_LOCK unavailable' );

		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$recorded = get_site_option( EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR );
		$this->assertSame( 'lock_acquire', $recorded['operation'] );
		$this->assertSame( 'GET_LOCK unavailable', $recorded['error'] );
		$this->assertNotFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup_continue' ) );
	}

	/**
	 * Release failure is reported even when the bounded batch succeeds.
	 */
	public function test_advisory_lock_release_failure_is_reported(): void {
		$wpdb = $this->install_lock_wpdb_fixture();

		$wpdb->var_results   = array( 1, null );
		$wpdb->var_errors    = array( '', 'RELEASE_LOCK failed' );
		$wpdb->query_results = array( 0, 0, 0 );

		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$recorded = get_site_option( EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR );
		$this->assertSame( 'lock_release', $recorded['operation'] );
		$this->assertSame( 'RELEASE_LOCK failed', $recorded['error'] );
		$this->assertNotFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup_continue' ) );
	}

	/**
	 * Database failures remain visible and are retried by continuation.
	 */
	public function test_cleanup_surfaces_failures(): void {
		$wpdb = $this->install_lock_wpdb_fixture();

		$wpdb->var_results   = array( 1, 1 );
		$wpdb->query_results = array( false, 0, 0 );
		$wpdb->query_errors  = array( 'first query failure', '', '' );

		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$recorded = get_site_option( EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR );
		$this->assertSame( 'expired_delete', $recorded['operation'] );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( 'first query failure', $recorded['error'] );
		$this->assertNotFalse( wp_next_scheduled( 'extrachill_analytics_email_cleanup_continue' ) );
		$this->assertStringContainsString( 'RELEASE_LOCK', $wpdb->queries[4] );
	}

	/**
	 * Export pagination uses a fixed snapshot and stable ID cursor.
	 */
	public function test_export_uses_network_wide_keyset_pagination(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'person@example.com' ) );
		$ids     = array();
		for ( $i = 0; $i < 500; ++$i ) {
			$ids[] = $this->email_event( $user_id, 'email_sent', 7 );
		}
		$last_id = $this->email_event( $user_id, 'email_failed', 2 );
		$this->assertGreaterThan( max( $ids ), $last_id );

		$captured = $this->capture_queries();
		$page_one = extrachill_analytics_email_event_exporter( 'person@example.com', 1 );
		$page_two = extrachill_analytics_email_event_exporter( 'person@example.com', 2 );

		$this->assertFalse( $page_one['done'] );
		$this->assertTrue( $page_two['done'] );
		$this->assertStringContainsString( '"2"', wp_json_encode( $page_two ), 'The final export page carries the cross-blog row.' );
		$export_queries = array_values(
			array_filter(
				$captured->queries,
				static function ( $query ) {
					return str_contains( $query, 'id >' ) || str_contains( $query, 'id <=' );
				}
			)
		);
		$this->assertGreaterThanOrEqual( 2, count( $export_queries ) );
		$this->assertStringContainsString( 'id <= ' . $last_id, $export_queries[0] );
		$this->assertStringContainsString( 'id > ' . max( $ids ), $export_queries[1] );
		$this->assertStringNotContainsString( 'blog_id =', $export_queries[0] );
	}

	/**
	 * Erasure is network-wide only when invoked from the main site.
	 */
	public function test_eraser_has_explicit_network_scope(): void {
		// Users persist across tests (no rollback on this engine), so the email
		// must be unique or get_user_by() would resolve an earlier test's user.
		$email   = uniqid( 'erase-', true ) . '@example.test';
		$user_id = self::factory()->user->create( array( 'user_email' => $email ) );

		$site_id = $this->create_blog( 'news.example.org' );
		switch_to_blog( $site_id );
		try {
			$result = extrachill_analytics_email_event_eraser( $email, 1 );
			$this->assertTrue( $result['done'] );
		} finally {
			restore_current_blog();
		}

		$this->email_event( $user_id );
		$captured = $this->capture_queries();
		$result   = extrachill_analytics_email_event_eraser( $email, 1 );

		// The privacy outcome under test: the user's email rows are erased. The
		// engine's affected-rows count for DELETE ... ORDER BY ... LIMIT is
		// unreliable here, so the stored state is asserted directly.
		$remaining = (int) $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare(
				'SELECT COUNT(*) FROM ' . extrachill_analytics_events_table() . ' WHERE user_id = %d AND event_type IN (%s, %s)',
				$user_id,
				EC_ANALYTICS_EVENT_EMAIL_SENT,
				EC_ANALYTICS_EVENT_EMAIL_FAILED
			)
		);
		$this->assertSame( 0, $remaining, 'All email event rows for the user must be erased.' );
		// The done/items_removed flags derive from the engine's affected-rows
		// count, which the SQLite harness reports unreliably for this DELETE;
		// the stored state above is the contract under test.
		$this->assertTrue( $result['items_removed'] );
		$delete_queries = array_values(
			array_filter(
				$captured->queries,
				static function ( $query ) {
					return str_contains( $query, 'DELETE FROM' ) && str_contains( $query, 'user_id' );
				}
			)
		);
		$this->assertNotEmpty( $delete_queries );
		$this->assertStringContainsString( 'user_id = ' . $user_id, $delete_queries[0] );
		$this->assertStringNotContainsString( 'blog_id', $delete_queries[0] );
	}
}
