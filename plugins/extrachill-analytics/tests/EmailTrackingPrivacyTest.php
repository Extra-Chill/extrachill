<?php
/**
 * Tests for privacy-safe email outcome tracking.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/class-email-privacy-wpdb-fixture.php';
require_once dirname( __DIR__ ) . '/inc/core/email-tracking.php';

/**
 * Verify bounded payload helpers and privacy contracts.
 */
final class EmailTrackingPrivacyTest extends TestCase {
	/**
	 * WPDB value restored after each test.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Reset network, cron, user, and database fixtures.
	 */
	protected function setUp(): void {
		global $wpdb;

		$this->original_wpdb = $wpdb ?? null;
		$wpdb                = new Email_Privacy_Wpdb_Fixture();

		$GLOBALS['extrachill_analytics_test_is_main_site']    = true;
		$GLOBALS['extrachill_analytics_test_scheduled']       = array();
		$GLOBALS['extrachill_analytics_test_recurring']       = array();
		$GLOBALS['extrachill_analytics_test_single']          = array();
		$GLOBALS['extrachill_analytics_test_site_options']    = array();
		$GLOBALS['extrachill_analytics_test_site_transients'] = array();
		$GLOBALS['extrachill_analytics_test_transient_ttls']  = array();
		$GLOBALS['extrachill_analytics_test_user']            = (object) array( 'ID' => 42 );
	}

	/**
	 * Restore the prior wpdb value.
	 */
	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;
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
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_recurring'] );
		$this->assertArrayHasKey( 'extrachill-email-analytics', extrachill_analytics_register_email_event_exporter( array() ) );
		$this->assertArrayHasKey( 'extrachill-email-analytics', extrachill_analytics_register_email_event_eraser( array() ) );

		$GLOBALS['extrachill_analytics_test_is_main_site'] = false;
		$GLOBALS['extrachill_analytics_test_scheduled']    = array();
		$GLOBALS['extrachill_analytics_test_recurring']    = array();

		extrachill_analytics_schedule_email_cleanup();
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_recurring'] );
		$this->assertSame( array(), extrachill_analytics_register_email_event_exporter( array() ) );
		$this->assertSame( array(), extrachill_analytics_register_email_event_eraser( array() ) );
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			extrachill_analytics_email_event_exporter( 'person@example.com', 1 )
		);
	}

	/**
	 * Full batches continue under a network lock until the backlog drains.
	 */
	public function test_cleanup_schedules_bounded_continuation_and_prevents_overlap(): void {
		global $wpdb;

		$wpdb->var_results   = array( 1, 1 );
		$wpdb->query_results = array( 1000, 25, 0 );
		$this->assertTrue( extrachill_analytics_cleanup_email_events() );
		$this->assertCount( 5, $wpdb->queries );
		$this->assertStringContainsString( "GET_LOCK('extrachill_analytics_email_cleanup_1', 0)", $wpdb->queries[0] );
		$this->assertStringContainsString( "RELEASE_LOCK('extrachill_analytics_email_cleanup_1')", $wpdb->queries[4] );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_single'] );

		$wpdb->queries     = array();
		$wpdb->var_results = array( 0 );
		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$this->assertCount( 1, $wpdb->queries );
	}

	/**
	 * Advisory lock acquisition failures surface and schedule a retry.
	 */
	public function test_advisory_lock_acquisition_failure_is_reported(): void {
		global $wpdb;

		$wpdb->var_results = array( null );
		$wpdb->var_errors  = array( 'GET_LOCK unavailable' );

		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$this->assertSame( 'lock_acquire', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR ]['operation'] );
		$this->assertSame( 'GET_LOCK unavailable', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR ]['error'] );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_single'] );
	}

	/**
	 * Release failure is reported even when the bounded batch succeeds.
	 */
	public function test_advisory_lock_release_failure_is_reported(): void {
		global $wpdb;

		$wpdb->var_results   = array( 1, null );
		$wpdb->var_errors    = array( '', 'RELEASE_LOCK failed' );
		$wpdb->query_results = array( 0, 0, 0 );

		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$this->assertSame( 'lock_release', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR ]['operation'] );
		$this->assertSame( 'RELEASE_LOCK failed', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR ]['error'] );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_single'] );
	}

	/**
	 * Database failures remain visible and are retried by continuation.
	 */
	public function test_cleanup_surfaces_failures(): void {
		global $wpdb;

		$wpdb->var_results   = array( 1, 1 );
		$wpdb->query_results = array( false, 0, 0 );
		$wpdb->query_errors  = array( 'first query failure', '', '' );

		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$this->assertSame( 'expired_delete', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR ]['operation'] );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( 'first query failure', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR ]['error'] );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_single'] );
		$this->assertStringContainsString( 'RELEASE_LOCK', $wpdb->queries[4] );
	}

	/**
	 * Export pagination uses a fixed snapshot and stable ID cursor.
	 */
	public function test_export_uses_network_wide_keyset_pagination(): void {
		global $wpdb;

		$first_page = array();
		for ( $id = 1; $id <= 500; $id++ ) {
			$first_page[] = (object) array(
				'id'         => $id,
				'blog_id'    => 7,
				'event_type' => 'email_sent',
				'event_data' => '{}',
				'created_at' => '2026-07-17 12:00:00',
			);
		}
		$wpdb->max_id = 700;
		$wpdb->rows   = array(
			$first_page,
			array(
				(object) array(
					'id'         => 700,
					'blog_id'    => 2,
					'event_type' => 'email_failed',
					'event_data' => '{"error_code":"smtp_failed"}',
					'created_at' => '2026-07-17 12:01:00',
				),
			),
		);

		$page_one = extrachill_analytics_email_event_exporter( 'person@example.com', 1 );
		$page_two = extrachill_analytics_email_event_exporter( 'person@example.com', 2 );

		$this->assertFalse( $page_one['done'] );
		$this->assertTrue( $page_two['done'] );
		$this->assertStringNotContainsString( 'OFFSET', $wpdb->queries[1] );
		$this->assertStringContainsString( 'id > 0 AND id <= 700', $wpdb->queries[1] );
		$this->assertStringContainsString( 'id > 500 AND id <= 700', $wpdb->queries[2] );
		$this->assertStringNotContainsString( 'blog_id =', $wpdb->queries[1] );
		$this->assertSame( '2', $page_two['data'][0]['data'][2]['value'] );
	}

	/**
	 * Erasure is network-wide only when invoked from the main site.
	 */
	public function test_eraser_has_explicit_network_scope(): void {
		global $wpdb;

		$GLOBALS['extrachill_analytics_test_is_main_site'] = false;
		$result = extrachill_analytics_email_event_eraser( 'person@example.com', 1 );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array(), $wpdb->queries );

		$GLOBALS['extrachill_analytics_test_is_main_site'] = true;
		$wpdb->query_results                               = array( 500 );
		$result = extrachill_analytics_email_event_eraser( 'person@example.com', 1 );
		$this->assertFalse( $result['done'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertStringContainsString( 'user_id = 42', $wpdb->queries[0] );
		$this->assertStringNotContainsString( 'blog_id', $wpdb->queries[0] );
	}
}
