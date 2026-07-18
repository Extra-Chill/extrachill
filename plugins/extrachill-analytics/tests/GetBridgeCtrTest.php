<?php
/**
 * Behavioral tests for the bridge stored-event report (issues #206 and #201).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-bridge-ctr.php';

/**
 * Verify bridge reporting follows the shipped lossy storage contract.
 */
final class GetBridgeCtrTest extends TestCase {

	/**
	 * Clear fixture state after each test.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['extrachill_analytics_bridge_fixture_rows'],
			$GLOBALS['extrachill_analytics_bridge_query_args']
		);
	}

	/**
	 * Build one canonical event fixture.
	 *
	 * @param int       $id         Row ID.
	 * @param string    $type       Event type.
	 * @param string    $dest       Destination site.
	 * @param bool|null $is_bot     Optional canonical bot stamp.
	 * @param string    $visitor_id Optional visitor ID.
	 * @return object
	 */
	private function event( $id, $type, $dest = 'events', $is_bot = null, $visitor_id = '00000000-0000-4000-8000-000000000001' ) {
		$data = array(
			'dest_site'   => $dest,
			'source_post' => 42,
			'source_site' => 'main',
			'term'        => 'Upcoming Events',
		);
		if ( null !== $is_bot ) {
			$data['is_bot'] = $is_bot;
		}

		return (object) array(
			'id'         => $id,
			'event_type' => $type,
			'event_data' => $data,
			'source_url' => '/story/',
			'blog_id'    => 1,
			'user_id'    => null,
			'visitor_id' => $visitor_id,
			'created_at' => '2026-07-18 01:00:00',
		);
	}

	/**
	 * Duplicate-looking rows remain independent without an opportunity ID.
	 */
	public function test_duplicate_lossy_impressions_are_not_deduplicated(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_impression' ),
			$this->event( 2, 'bridge_impression' ),
			$this->event( 3, 'bridge_click' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 2, $report['stored_impression_events'] );
		$this->assertSame( 1, $report['stored_click_events'] );
		$this->assertSame( 0.5, $report['stored_click_impression_ratio'] );
		$this->assertArrayNotHasKey( 'dedupe', $report );
		$this->assertArrayNotHasKey( 'observed_exposures', $report );
		$this->assertArrayNotHasKey( 'click_proven_missing_exposures', $report );
	}

	/**
	 * Independent writes may produce an honest ratio over 100 percent.
	 */
	public function test_clicks_exceeding_impressions_are_not_clamped(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_impression' ),
			$this->event( 2, 'bridge_click' ),
			$this->event( 3, 'bridge_click' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 2, $report['clicks'] );
		$this->assertSame( 1, $report['impressions'] );
		$this->assertSame( 2.0, $report['ctr'] );
		$this->assertStringContainsString( 'exceed 100%', $report['note'] );
	}

	/**
	 * Truthy bot stamps are excluded before totals and destinations.
	 */
	public function test_bot_stamped_rows_are_excluded_and_unstamped_rows_remain(): void {
		$bot_click                     = $this->event( 4, 'bridge_click', 'etcpasswd', true, '' );
		$bot_click->event_data['term'] = 'scanner payload';
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$bot_click,
			$this->event( 3, 'bridge_impression', 'events', 1, '' ),
			$this->event( 2, 'bridge_impression', 'events', false ),
			$this->event( 1, 'bridge_click', 'events' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 1, $report['clicks'] );
		$this->assertSame( 1, $report['impressions'] );
		$this->assertSame( 2, $report['coverage']['bot_rows_excluded'] );
		$this->assertSame( 'events', $report['by_dest_site'][0]['dest_site'] );
		$this->assertCount( 1, $report['by_dest_site'] );
	}

	/**
	 * Legacy destination-less rows remain visible with mixed coverage.
	 */
	public function test_legacy_mixed_rows_have_partial_destination_coverage(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 3, 'bridge_impression', '' ),
			$this->event( 2, 'bridge_click', '' ),
			$this->event( 1, 'bridge_impression', 'events' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 'historically_mixed_unmarked', $report['coverage']['measurement_contract'] );
		$this->assertSame( 'partial', $report['coverage']['destination_status'] );
		$this->assertSame( 2, $report['coverage']['rows_missing_dest_site'] );
		$this->assertStringContainsString( 'render-opportunity', $report['coverage']['historical_contract_notice'] );
		$this->assertStringContainsString( 'no page-load or DOM-element identifier', $report['by_dest_site'][0]['measurement_grain_notice'] );
	}

	/**
	 * Legacy zero-denominator keys retain numeric scalar types.
	 */
	public function test_zero_impressions_preserve_legacy_types(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_click' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 1, $report['clicks'] );
		$this->assertSame( 0, $report['impressions'] );
		$this->assertSame( 0.0, $report['ctr'] );
		$this->assertIsInt( $report['clicks'] );
		$this->assertIsInt( $report['impressions'] );
		$this->assertIsFloat( $report['ctr'] );
	}

	/**
	 * Multiple destinations retain independent stored ratios.
	 */
	public function test_multiple_destinations_are_aggregated_independently(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 4, 'bridge_click', 'artist' ),
			$this->event( 3, 'bridge_click', 'events' ),
			$this->event( 2, 'bridge_impression', 'events' ),
			$this->event( 1, 'bridge_impression', 'events' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 'events', $report['by_dest_site'][0]['dest_site'] );
		$this->assertSame( 1, $report['by_dest_site'][0]['stored_click_events'] );
		$this->assertSame( 2, $report['by_dest_site'][0]['stored_impression_events'] );
		$this->assertIsInt( $report['by_dest_site'][0]['stored_click_events'] );
		$this->assertIsInt( $report['by_dest_site'][0]['stored_impression_events'] );
		$this->assertSame( 0.5, $report['by_dest_site'][0]['stored_click_impression_ratio'] );
		$this->assertSame( 'artist', $report['by_dest_site'][1]['dest_site'] );
		$this->assertSame( 1, $report['by_dest_site'][1]['stored_click_events'] );
		$this->assertSame( 0, $report['by_dest_site'][1]['stored_impression_events'] );
		$this->assertSame( 0.0, $report['by_dest_site'][1]['stored_click_impression_ratio'] );
	}

	/**
	 * The newest IDs are selected in true descending order before truncation.
	 */
	public function test_descending_id_order_and_truncation_are_stable(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_click', 'oldest' ),
			$this->event( 3, 'bridge_impression', 'newest' ),
			$this->event( 2, 'bridge_click', 'middle' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr(
			array(
				'days'       => 0,
				'max_events' => 2,
			)
		);

		$this->assertSame( 1, $report['clicks'] );
		$this->assertSame( 1, $report['impressions'] );
		$this->assertSame( 'truncated', $report['coverage']['status'] );
		$this->assertSame( array( 'newest', 'middle' ), array_column( $report['by_dest_site'], 'dest_site' ) );
		$this->assertSame( 'id DESC', $report['pagination']['order'] );
		$this->assertArrayNotHasKey( 'offset', $GLOBALS['extrachill_analytics_bridge_query_args'][0] );
	}

	/**
	 * Multi-page reads advance with an exclusive ID cursor, never an offset.
	 */
	public function test_keyset_pagination_uses_last_descending_id(): void {
		$rows = array();
		for ( $id = 1; $id <= 1002; ++$id ) {
			$rows[] = $this->event( $id, 'bridge_impression' );
		}
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array_reverse( $rows );

		$report = extrachill_analytics_ability_get_bridge_ctr(
			array(
				'days'       => 0,
				'max_events' => 1001,
			)
		);

		$this->assertSame( 1001, $report['impressions'] );
		$this->assertTrue( $report['coverage']['truncated'] );
		$this->assertCount( 2, $GLOBALS['extrachill_analytics_bridge_query_args'] );
		$this->assertArrayNotHasKey( 'before_id', $GLOBALS['extrachill_analytics_bridge_query_args'][0] );
		$this->assertSame( 3, $GLOBALS['extrachill_analytics_bridge_query_args'][1]['before_id'] );
		$this->assertArrayNotHasKey( 'offset', $GLOBALS['extrachill_analytics_bridge_query_args'][1] );
	}

	/**
	 * The canonical query helper binds the exclusive cursor without SQL offset.
	 */
	public function test_canonical_query_helper_supports_keyset_cursor(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/events.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "'before_id'  => 0", $source );
		$this->assertStringContainsString( "\$where[]  = 'id < %d';", $source );
		$this->assertStringContainsString( 'ORDER BY {$orderby} {$order} LIMIT %d";', $source );
	}

	/**
	 * Coverage discloses anonymous eligible rows and privacy limitations.
	 */
	public function test_coverage_status_discloses_anonymous_rows(): void {
		$GLOBALS['extrachill_analytics_bridge_fixture_rows'] = array(
			$this->event( 1, 'bridge_impression', 'events', null, '' ),
			$this->event( 2, 'bridge_click' ),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 'partial', $report['coverage']['identity_status'] );
		$this->assertSame( 1, $report['coverage']['anonymous_rows'] );
		$this->assertStringContainsString( 'GPC/DNT', $report['coverage']['privacy_notice'] );
	}
}
