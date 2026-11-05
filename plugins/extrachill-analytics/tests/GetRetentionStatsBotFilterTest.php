<?php
/**
 * Human-only retention query regression tests.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify bot stamps cannot create acquisition, returns, or extra depth.
 */
final class GetRetentionStatsBotFilterTest extends Extrachill_Analytics_TestCase {

	/**
	 * Insert a pageview, optionally omitting the legacy bot stamp.
	 *
	 * @param string    $visitor Visitor identity.
	 * @param string    $date UTC date.
	 * @param bool|null $bot Canonical stamp, or null to omit it.
	 * @param int       $blog Blog ID.
	 */
	private function pageview( $visitor, $date, $bot = null, $blog = 1 ): void {
		global $wpdb;
		$data = array();
		if ( null !== $bot ) {
			$data['is_bot'] = $bot;
		}
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => 'pageview',
				'event_data' => wp_json_encode( $data ),
				'visitor_id' => $visitor,
				'blog_id'    => $blog,
				'created_at' => $date . ' 12:00:00',
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Mixed stamps preserve legacy humans but never count bot-only returns.
	 */
	public function test_mixed_stamps_filter_every_query_and_keep_legacy_humans(): void {
		$this->pageview( 'human', '2026-01-05', false );
		$this->pageview( 'human', '2026-01-12', false, 2 );
		$this->pageview( 'legacy', '2026-01-05' );
		$this->pageview( 'legacy', '2026-01-19', null, 2 );
		$this->pageview( 'bot', '2026-01-05', true );
		$this->pageview( 'bot', '2026-01-12', true, 2 );
		$this->pageview( 'mixed', '2026-01-05', false );
		$this->pageview( 'mixed', '2026-01-12', true, 2 );
		$this->pageview( 'human', '2026-01-12', true, 2 );
		// Bot history must not move this human's acquisition into an older cohort.
		$this->pageview( 'mixed', '2025-12-01', true );

		$queries = array();
		$capture = static function ( $query ) use ( &$queries ) {
			if ( false !== strpos( $query, 'SELECT' ) && false !== strpos( $query, extrachill_analytics_events_table() ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture );
		try {
			$report = extrachill_analytics_compute_retention_stats(
				array(
					'start_date'   => '2026-01-01',
					'end_date'     => '2026-02-01',
					'cohort_weeks' => 8,
				)
			);
		} finally {
			remove_filter( 'query', $capture );
		}

		$this->assertCount( 7, $queries );
		foreach ( $queries as $query ) {
			$this->assertStringContainsString( "JSON_EXTRACT(event_data, '$.is_bot') IS NOT TRUE", $query );
		}
		$this->assertStringContainsString( "JSON_EXTRACT(activity.event_data, '$.is_bot') IS NOT TRUE", $queries[1] );
		$this->assertSame( 3, $report['return_rate']['total_visitors'] );
		$this->assertSame( 2, $report['return_rate']['returning_visitors'] );
		$this->assertSame( 0.6667, $report['return_rate']['rate'] );
		$this->assertSame( 2, $report['cross_site_return']['cross_site_visitors'] );
		$this->assertSame( 1.0, $report['session_depth']['avg_pageviews_per_visitor_day'] );
		$this->assertSame( 1, $report['session_depth']['max_pageviews_per_visitor_day'] );
		$this->assertCount( 1, $report['cohort_retention']['cohorts'] );
		$cohort = $report['cohort_retention']['cohorts'][0];
		$this->assertSame( 3, $cohort['cohort_size'] );
		$this->assertSame( 1, $cohort['returned_w1'] );
		$this->assertSame( 1, $cohort['returned_w2'] );
	}

	/**
	 * Alias interpolation is restricted to identifiers, never arbitrary SQL.
	 */
	public function test_helper_rejects_invalid_alias(): void {
		$this->expectException( InvalidArgumentException::class );
		extrachill_analytics_human_events_sql( 'activity; DROP TABLE events' );
	}
}
