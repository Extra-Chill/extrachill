<?php
/**
 * Search-gap report security-filter regression tests.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verifies that payload terms are removed before both result buckets aggregate.
 */
final class GetSearchGapsSecurityFilterTest extends Extrachill_Analytics_TestCase {

	/**
	 * Insert one raw search event row.
	 *
	 * @param string $term         Search term.
	 * @param int    $result_count Result count.
	 * @param int    $times        Repeat count.
	 */
	private function search_event( $term, $result_count, $times = 1 ): void {
		global $wpdb;
		for ( $i = 0; $i < $times; ++$i ) {
			$wpdb->insert(
				extrachill_analytics_events_table(),
				array(
					'event_type' => 'search',
					'event_data' => wp_json_encode(
						array(
							'search_term'  => $term,
							'result_count' => $result_count,
						)
					),
					'source_url' => '',
					'blog_id'    => 1,
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				),
				array( '%s', '%s', '%s', '%d', '%s' )
			);
		}
	}

	/**
	 * Observed and encoded payload families must not occupy either report bucket.
	 */
	public function test_payload_terms_are_excluded_from_zero_and_low_result_buckets(): void {
		if ( ! $this->sqlite_has_json1() ) {
			self::markTestSkipped( 'Harness database engine lacks JSON1; the search-gap report queries require MySQL JSON functions.' );
		}
		$attack_terms = array(
			'/etc/shells',
			'%2Fetc%2Fshells',
			';assert(base64_decode("Q09NTUFORA=="));',
			'bxss.me',
		);

		$this->search_event( $attack_terms[0], 0, 77 );
		$this->search_event( $attack_terms[1], 0, 4 );
		$this->search_event( $attack_terms[2], 1, 45 );
		$this->search_event( $attack_terms[3], 2, 3 );
		$this->search_event( 'AC/DC', 0, 5 );
		$this->search_event( 'P!nk', 2, 3 );

		$report = extrachill_analytics_ability_get_search_gaps(
			array(
				'days'        => 0,
				'limit'       => 10,
				'max_results' => 3,
			)
		);

		$this->assertSame(
			array(
				array(
					'term'  => 'AC/DC',
					'count' => 5,
				),
			),
			$report['zero_result']
		);
		$this->assertSame(
			array(
				array(
					'term'        => 'P!nk',
					'count'       => 3,
					'min_results' => 2,
				),
			),
			$report['low_result']
		);
		$this->assertSame( 129, $report['excluded_attack_searches'] );
		$this->assertSame( 4, $report['excluded_attack_terms'] );
		$this->assertSame( 129, $report['excluded_bot'] );
		$this->assertSame( 8, $report['total_searches'] );
	}
}
