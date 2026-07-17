<?php
/**
 * Tests for the bounded first-party route-transition report.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-route-transitions.php';

/**
 * Protect route journey semantics and the ability contract.
 */
final class RouteTransitionsTest extends TestCase {
	/**
	 * Same/cross-surface transitions, route families, gap boundaries, loops,
	 * direct terminals, anonymous rows, historical routes, and multisite nodes
	 * are all represented without changing session identity semantics.
	 */
	public function test_route_transition_fixture_matrix(): void {
		$events                  = array(
			$this->event( 'anonymous', 1, 'home', 90, 90 ),
			$this->event( 'v1', 1, 'singular', 100, 100 ),
			$this->event( 'v1', 1, 'singular', 110, 100 ),
			$this->event( 'v1', 7, 'home', 120, 100 ),
			$this->event( 'v1', 2, 'directory', 2001, 100 ),
			$this->event( 'v2', 1, 'taxonomy-archive', 100, 50 ),
			$this->event( 'v2', 1, 'home', 1900, 50 ),
			$this->event( 'v3', 4, 'app-account', 100, 100 ),
			$this->event( 'v4', 9, 'unclassified', 100, 100 ),
		);
		$events[0]['visitor_id'] = '';

		$report = extrachill_analytics_build_route_transition_report(
			$events,
			array(
				'since_ts'        => 1,
				'until_ts'        => 3000,
				'gap_secs'        => 1800,
				'sequence_length' => 3,
				'cohort'          => 'all',
				'limit'           => 25,
			)
		);

		$this->assertSame( 5, $report['counts']['sessions'] );
		$this->assertSame( 3, $report['counts']['first_time_sessions'] );
		$this->assertSame( 2, $report['counts']['returning_sessions'] );
		$this->assertSame( 3, $report['counts']['direct_terminal_sessions'] );
		$this->assertSame( 3, $report['counts']['transitions'] );
		$this->assertSame( 2, $report['counts']['same_surface_transitions'] );
		$this->assertSame( 1, $report['counts']['cross_surface_transitions'] );
		$this->assertSame( 1, $report['counts']['sequence_windows'] );

		$loop = $this->find_transition( $report['transitions'], 1, 'singular', 1, 'singular' );
		$this->assertSame( 1, $loop['count'] );
		$this->assertTrue( $loop['same_surface'] );

		$cross = $this->find_transition( $report['transitions'], 1, 'singular', 7, 'home' );
		$this->assertSame( 1, $cross['count'] );
		$this->assertFalse( $cross['same_surface'] );

		$this->assertSame( 9, $this->find_route( $report['terminals'], 9, 'unclassified' )['route']['blog_id'] );
		$this->assertSame( 3, count( $report['sequences'][0]['path'] ) );
	}

	/**
	 * The cohort option uses first-observed acquisition rather than an in-window
	 * proxy, and returns only matching sessions.
	 */
	public function test_first_time_and_returning_cohort_filters(): void {
		$events  = array(
			$this->event( 'new', 1, 'home', 100, 100 ),
			$this->event( 'old', 1, 'home', 100, 50 ),
		);
		$options = array(
			'since_ts'        => 1,
			'until_ts'        => 200,
			'gap_secs'        => 1800,
			'sequence_length' => 2,
			'cohort'          => 'first_time',
			'limit'           => 25,
		);

		$first = extrachill_analytics_build_route_transition_report( $events, $options );
		$this->assertSame( 1, $first['counts']['sessions'] );
		$this->assertSame( 1, $first['counts']['first_time_sessions'] );

		$options['cohort'] = 'returning';
		$returning         = extrachill_analytics_build_route_transition_report( $events, $options );
		$this->assertSame( 1, $returning['counts']['sessions'] );
		$this->assertSame( 1, $returning['counts']['returning_sessions'] );
	}

	/**
	 * Buffered rows prevent a session crossing the lower boundary from becoming
	 * a false in-window entry.
	 */
	public function test_pre_window_session_is_excluded(): void {
		$events = array(
			$this->event( 'v1', 1, 'home', 90, 90 ),
			$this->event( 'v1', 7, 'directory', 110, 90 ),
			$this->event( 'v1', 7, 'singular', 2000, 90 ),
		);

		$report = extrachill_analytics_build_route_transition_report(
			$events,
			array(
				'since_ts'        => 100,
				'until_ts'        => 3000,
				'gap_secs'        => 1800,
				'sequence_length' => 2,
				'cohort'          => 'all',
				'limit'           => 25,
			)
		);

		$this->assertSame( 1, $report['counts']['sessions'] );
		$this->assertSame( 0, $report['counts']['transitions'] );
		$this->assertSame( 'singular', $report['entries'][0]['route']['route_family'] );
	}

	/**
	 * Equal counts sort by machine identity and limits apply after sorting.
	 */
	public function test_rank_is_deterministic_and_limited(): void {
		$buckets = array(
			'2:home' => array(
				'route' => array(
					'blog_id'      => 2,
					'route_family' => 'home',
				),
				'count' => 1,
			),
			'1:home' => array(
				'route' => array(
					'blog_id'      => 1,
					'route_family' => 'home',
				),
				'count' => 1,
			),
			'7:home' => array(
				'route' => array(
					'blog_id'      => 7,
					'route_family' => 'home',
				),
				'count' => 2,
			),
		);

		$ranked = extrachill_analytics_route_transition_rank( $buckets, 2 );
		$this->assertCount( 2, $ranked );
		$this->assertSame( 7, $ranked[0]['route']['blog_id'] );
		$this->assertSame( 1, $ranked[1]['route']['blog_id'] );
	}

	/**
	 * Registration, schema, cost bounds, coverage, and read-only conventions stay
	 * explicit and no persistence substrate is introduced.
	 */
	public function test_ability_contract_and_query_bounds(): void {
		$GLOBALS['extrachill_analytics_registered_abilities'] = array();
		extrachill_analytics_register_route_transitions_ability();
		$ability = $GLOBALS['extrachill_analytics_registered_abilities']['extrachill/get-route-transitions'];

		$this->assertSame( 'extrachill_analytics_can_read_reports', $ability['permission_callback'] );
		$this->assertFalse( $ability['meta']['show_in_rest'] );
		$this->assertTrue( $ability['meta']['annotations']['readonly'] );
		$this->assertArrayHasKey( 'sequence_length', $ability['input_schema']['properties'] );
		$this->assertSame( array( 'all', 'first_time', 'returning' ), $ability['input_schema']['properties']['cohort']['enum'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-route-transitions.php' );
		$this->assertStringContainsString( 'event_type_created index', $source );
		$this->assertStringContainsString( 'visitor_created', $source );
		$this->assertStringContainsString( 'LIMIT %d', $source );
		$this->assertStringContainsString( 'max_pageviews', $source );
		$this->assertStringContainsString( 'cutoff_ts + $gap_secs', $source );
		$this->assertStringContainsString( "'ranking_since'", $source );
		$this->assertStringContainsString( 'historical_unclassified_pageviews', $source );
		$this->assertStringContainsString( 'anonymous_pageviews', $source );
		$this->assertStringNotContainsString( 'CREATE TABLE', $source );
		$this->assertStringNotContainsString( 'INSERT ', $source );
		$this->assertStringNotContainsString( 'UPDATE ', $source );
	}

	/**
	 * Create one normalized pageview fixture.
	 *
	 * @param string $visitor_id    Visitor ID.
	 * @param int    $blog_id       Blog ID.
	 * @param string $route_family Route family.
	 * @param int    $ts            Timestamp.
	 * @param int    $acquisition   Acquisition timestamp.
	 * @return array Event fixture.
	 */
	private function event( $visitor_id, $blog_id, $route_family, $ts, $acquisition ) {
		return array(
			'id'             => $ts,
			'visitor_id'     => $visitor_id,
			'blog_id'        => $blog_id,
			'route_family'   => $route_family,
			'ts'             => $ts,
			'acquisition_ts' => $acquisition,
		);
	}

	/**
	 * Find a transition fixture.
	 *
	 * @param array  $rows        Transition rows.
	 * @param int    $from_blog   Source blog.
	 * @param string $from_family Source family.
	 * @param int    $to_blog     Target blog.
	 * @param string $to_family   Target family.
	 * @return array Transition row.
	 */
	private function find_transition( $rows, $from_blog, $from_family, $to_blog, $to_family ) {
		foreach ( $rows as $row ) {
			if ( $from_blog === $row['from']['blog_id'] && $from_family === $row['from']['route_family'] && $to_blog === $row['to']['blog_id'] && $to_family === $row['to']['route_family'] ) {
				return $row;
			}
		}
		$this->fail( 'Transition was not found.' );
	}

	/**
	 * Find a route fixture.
	 *
	 * @param array  $rows   Route rows.
	 * @param int    $blog   Blog ID.
	 * @param string $family Route family.
	 * @return array Route row.
	 */
	private function find_route( $rows, $blog, $family ) {
		foreach ( $rows as $row ) {
			if ( $blog === $row['route']['blog_id'] && $family === $row['route']['route_family'] ) {
				return $row;
			}
		}
		$this->fail( 'Route was not found.' );
	}
}
