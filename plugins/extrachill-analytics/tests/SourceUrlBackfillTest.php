<?php
/**
 * Tests for historical source URL redaction.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify the privacy backfill is bounded and dry-run by default.
 */
final class SourceUrlBackfillTest extends Extrachill_Analytics_TestCase {
	/**
	 * Insert one event row with a historical source URL.
	 *
	 * @param string $source_url Historical source URL.
	 * @return int Row ID.
	 */
	private function event_with_source( string $source_url ): int {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => 'pageview',
				'event_data' => '{}',
				'source_url' => $source_url,
				'blog_id'    => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Historical values are canonicalized without writes during a dry run.
	 */
	public function test_backfill_dry_run_counts_redactions_without_writing(): void {
		$this->event_with_source( 'https://extrachill.com/login/?token=fixture#form' );
		$this->event_with_source( 'https://extrachill.com/story/' );
		$this->event_with_source( 'not a URL' );

		$result = extrachill_analytics_redact_source_urls( array( 'batch_size' => 2 ) );

		$this->assertSame( 3, $result['scanned'] );
		$this->assertSame( 2, $result['redacted'] );
		$this->assertSame( 1, $result['invalid'] );
		$this->assertFalse( $result['live'] );

		$remaining = array_column(
			(array) $GLOBALS['wpdb']->get_results( "SELECT source_url FROM " . extrachill_analytics_events_table() . " WHERE source_url <> ''" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
			'source_url'
		);
		$this->assertContains( 'https://extrachill.com/login/?token=fixture#form', $remaining );
		$this->assertNotContains( '', $remaining );
	}

	/**
	 * The live pass writes canonicalized source URLs back to the rows.
	 */
	public function test_backfill_live_run_writes_canonicalized_urls(): void {
		$this->event_with_source( 'https://extrachill.com/login/?token=fixture#form' );
		$this->event_with_source( 'https://extrachill.com/story/' );
		$this->event_with_source( 'not a URL' );

		$result = extrachill_analytics_redact_source_urls(
			array(
				'batch_size' => 2,
				'live'       => true,
			)
		);

		$this->assertSame( 3, $result['scanned'] );
		$this->assertSame( 2, $result['redacted'] );
		$this->assertSame( 1, $result['invalid'] );
		$this->assertTrue( $result['live'] );

		$remaining = array_column(
			(array) $GLOBALS['wpdb']->get_results( "SELECT source_url FROM " . extrachill_analytics_events_table() . " WHERE source_url <> ''" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
			'source_url'
		);
		$this->assertSame( array( 'https://extrachill.com/login/', 'https://extrachill.com/story/' ), $remaining );
	}
}
