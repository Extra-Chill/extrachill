<?php
/**
 * Behavioral tests for the bridge exposure report (issue #191).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-bridge-ctr.php';

/**
 * Verify bridge reporting follows the shipped lossy exposure contract.
 */
final class GetBridgeCtrTest extends TestCase {

	/**
	 * Clear fixture state after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['extrachill_analytics_bridge_fixture_rows'] );
	}

	/**
	 * Build one canonical event fixture.
	 *
	 * @param int    $id           Row ID.
	 * @param string $type         Event type.
	 * @param string $dest         Destination site.
	 * @param string $created_at   Stored UTC time.
	 * @param string $visitor_id   Optional visitor ID.
	 * @param string $source_url   Source path.
	 * @return object
	 */
	private function event( $id, $type, $dest = 'events', $created_at = '2026-07-18 01:00:00', $visitor_id = '00000000-0000-4000-8000-000000000001', $source_url = '/story/' ) {
		return (object) array(
			'id'         => $id,
			'event_type' => $type,
			'event_data' => array(
				'dest_site'   => $dest,
				'source_post' => 42,
				'source_site' => 'main',
				'term'        => 'Upcoming Events',
			),
			'source_url' => $source_url,
			'blog_id'    => 1,
			'user_id'    => null,
			'visitor_id' => $visitor_id,
			'created_at' => $created_at,
		);
	}

	/**
	 * Exact ambiguous-retry rows are removed without using their unique IDs.
	 */
	public function test_duplicate_lossy_impressions_are_deduplicated(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_impression' ),
			$this->event( 2, 'bridge_impression' ),
			$this->event( 3, 'bridge_click' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 2, $report['stored']['viewport_exposure_rows'] );
		$this->assertSame( 1, $report['viewport_exposure_events'] );
		$this->assertSame( 1, $report['dedupe']['duplicate_rows_removed'] );
		$this->assertSame( 1.0, $report['click_to_observed_exposure_ratio'] );
	}

	/**
	 * Independently delivered clicks provide lower-bound exposure evidence.
	 */
	public function test_clicks_exceeding_stored_exposure_remain_bounded(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_impression' ),
			$this->event( 2, 'bridge_click', 'events', '2026-07-18 01:00:01' ),
			$this->event( 3, 'bridge_click', 'events', '2026-07-18 01:00:02' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 1, $report['viewport_exposure_events'] );
		$this->assertSame( 2, $report['clicks'] );
		$this->assertSame( 1, $report['click_proven_missing_exposures'] );
		$this->assertSame( 2, $report['observed_exposures'] );
		$this->assertSame( 2, $report['impressions'] );
		$this->assertSame( 1.0, $report['ctr'] );
	}

	/**
	 * Legacy destination-less rows stay visible but receive no invented ratio.
	 */
	public function test_legacy_mixed_rows_have_partial_destination_coverage(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_impression', '' ),
			$this->event( 2, 'bridge_click', '', '2026-07-18 01:00:01' ),
			$this->event( 3, 'bridge_impression', 'events', '2026-07-18 01:00:02' ),
		);

		$report  = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );
		$unknown = array_values(
			array_filter(
				$report['by_dest_site'],
				static function ( $row ) {
					return '(unknown)' === $row['dest_site'];
				}
			)
		)[0];

		$this->assertSame( 'historically_mixed_unmarked', $report['coverage']['measurement_contract'] );
		$this->assertSame( 'partial', $report['coverage']['destination_status'] );
		$this->assertSame( 2, $report['coverage']['rows_missing_dest_site'] );
		$this->assertNull( $unknown['click_to_observed_exposure_ratio'] );
		$this->assertStringContainsString( 'render-opportunity', $report['coverage']['historical_contract_notice'] );
	}

	/**
	 * A missing denominator is unknown rather than a fabricated zero ratio.
	 */
	public function test_zero_exposure_has_null_ratio(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array();

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 0, $report['observed_exposures'] );
		$this->assertNull( $report['click_to_observed_exposure_ratio'] );
		$this->assertNull( $report['ctr'] );
		$this->assertSame( 'not_observed', $report['coverage']['status'] );
	}

	/**
	 * Destination aggregates use only each destination's observed evidence.
	 */
	public function test_multiple_destinations_are_aggregated_independently(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_impression', 'events' ),
			$this->event( 2, 'bridge_click', 'events', '2026-07-18 01:00:01' ),
			$this->event( 3, 'bridge_click', 'artist', '2026-07-18 01:00:02', '00000000-0000-4000-8000-000000000002', '/other/' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 'artist', $report['by_dest_site'][0]['dest_site'] );
		$this->assertSame( 1, $report['by_dest_site'][0]['click_proven_missing_exposures'] );
		$this->assertSame( 'events', $report['by_dest_site'][1]['dest_site'] );
		$this->assertSame( 1.0, $report['by_dest_site'][1]['click_to_observed_exposure_ratio'] );
		$this->assertSame( 'measured', $report['coverage']['destination_status'] );
	}

	/**
	 * Coverage reports anonymity and the bounded query status explicitly.
	 */
	public function test_coverage_status_discloses_anonymous_and_truncated_rows(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_impression', 'events', '2026-07-18 01:00:00', '' ),
			$this->event( 2, 'bridge_click', 'events', '2026-07-18 01:00:01' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr(
			array(
				'days'       => 0,
				'max_events' => 1,
			)
		);

		$this->assertSame( 'truncated', $report['coverage']['status'] );
		$this->assertTrue( $report['coverage']['truncated'] );
		$this->assertSame( 'unavailable', $report['coverage']['identity_status'] );
		$this->assertSame( 1, $report['coverage']['anonymous_rows'] );
		$this->assertStringContainsString( 'GPC/DNT', $report['coverage']['privacy_notice'] );
	}
}
