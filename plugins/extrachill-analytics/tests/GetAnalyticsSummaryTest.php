<?php
/**
 * Regression tests for analytics summary event detail (issue #177).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify event detail is exposed only for explicitly filtered summaries.
 */
final class GetAnalyticsSummaryTest extends Extrachill_Analytics_TestCase {
	/**
	 * Insert one event row inside the summary window.
	 *
	 * @param string $event_type Event type.
	 * @param string $source_url Source URL.
	 * @param string $context    Context dimension.
	 * @return int Row ID.
	 */
	private function summary_event( $event_type, $source_url, $context = '' ): int {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => $event_type,
				'event_data' => wp_json_encode( '' !== $context ? array( 'context' => $context ) : array() ),
				'source_url' => $source_url,
				'blog_id'    => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * An explicit event type exposes typed rows from the existing aggregation.
	 */
	public function test_explicit_event_type_exposes_typed_detail_rows(): void {
		if ( ! $this->sqlite_has_json1() ) {
			self::markTestSkipped( 'Harness database engine lacks JSON1; the by-context detail query requires MySQL JSON functions.' );
		}
		$day = gmdate( 'Y-m-d', time() - HOUR_IN_SECONDS );
		for ( $i = 0; $i < 3; ++$i ) {
			$this->summary_event( 'newsletter_signup', 'https://extrachill.com/newsletter/', 'footer' );
		}

		$summary = extrachill_analytics_ability_get_summary(
			array(
				'days'       => 28,
				'event_type' => 'newsletter_signup',
			)
		);

		$this->assertSame( 3, $summary['total'] );
		$this->assertSame(
			array(
				array(
					'date'  => $day,
					'count' => 3,
				),
			),
			$summary['by_date']
		);
		$this->assertSame(
			array(
				array(
					'source_url' => 'https://extrachill.com/newsletter/',
					'count'      => 3,
				),
			),
			$summary['by_source']
		);
		$this->assertSame(
			array(
				array(
					'context' => 'footer',
					'count'   => 3,
				),
			),
			$summary['by_context']
		);
	}

	/**
	 * The all-event contract remains compact and does not run detail queries.
	 */
	public function test_all_event_summary_contract_is_unchanged(): void {
		$captured = $this->capture_queries();
		$summary  = extrachill_analytics_ability_get_summary( array( 'days' => 28 ) );

		$this->assertArrayNotHasKey( 'by_date', $summary );
		$this->assertArrayNotHasKey( 'by_source', $summary );
		$this->assertArrayNotHasKey( 'by_context', $summary );
		$detail_queries = array_filter(
			$captured->queries,
			static function ( $query ) {
				return (bool) preg_match( '/GROUP BY\s+(DATE\(created_at\)|source_url|context)/i', $query );
			}
		);
		$this->assertCount( 0, $detail_queries );
		$this->assertNotEmpty( $captured->queries, 'The compact summary still reads available event types.' );
	}

	/**
	 * Canonical onboarding grants require no parallel summary reader.
	 */
	public function test_onboarding_grant_is_readable_by_existing_summary(): void {
		$this->summary_event( EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED, 'https://example.org/', 'studio' );
		$this->summary_event( EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED, 'https://example.org/', 'studio' );

		$captured = $this->capture_queries();
		$summary  = extrachill_analytics_ability_get_summary(
			array(
				'days'       => 28,
				'event_type' => EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED,
			)
		);

		$this->assertSame( 'artist_access_granted', $summary['event_types'][0]['event_type'] );
		$this->assertSame( 2, $summary['event_types'][0]['count'] );
		$bound = implode( "\n", $captured->queries );
		$this->assertStringContainsString( 'artist_access_granted', $bound );
	}

	/**
	 * Source and context rankings are deterministic and bounded in SQL.
	 */
	public function test_source_and_context_queries_are_stable_and_bounded(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/events.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'ORDER BY count DESC, source_url ASC', $source );
		$this->assertStringContainsString( 'ORDER BY count DESC, context ASC', $source );
		$this->assertSame( 2, substr_count( $source, 'LIMIT 20' ) );
	}
}
