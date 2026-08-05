<?php
/**
 * Tests for exact link-page analytics date windows.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/database/link-page-analytics-db.php';
require_once dirname( __DIR__ ) . '/inc/core/link-page-analytics.php';

/** Verify validation, SQL bounds, compatibility, and response metadata. */
final class LinkPageAnalyticsDateRangeTest extends TestCase {
	/** Install deterministic database and site-calendar fixtures. */
	protected function setUp(): void {
		$GLOBALS['extrachill_analytics_test_current_time'] = gmmktime( 12, 0, 0, 3, 10, 2026 );
		$GLOBALS['wpdb']                                   = new class() {
			/**
			 * Database table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_4_';

			/**
			 * Executed SELECT statements.
			 *
			 * @var string[]
			 */
			public $queries = array();

			/**
			 * Substitute integer and string placeholders for query assertions.
			 *
			 * @param string $query SQL query.
			 * @param mixed  ...$args Prepared values.
			 * @return string Prepared fixture query.
			 */
			public function prepare( $query, ...$args ) {
				foreach ( $args as $arg ) {
					$replacement = is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
					$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 );
				}
				return $query;
			}

			/**
			 * Return fixtures for views, daily clicks, and top links in query order.
			 *
			 * @param string $query SQL query.
			 * @return array<object> Aggregate row fixtures.
			 */
			public function get_results( $query ) {
				$this->queries[] = $query;
				$index           = count( $this->queries );

				if ( 1 === $index ) {
					return array(
						(object) array(
							'stat_date'  => '2026-03-08',
							'view_count' => '2',
						),
						(object) array(
							'stat_date'  => '2026-03-10',
							'view_count' => '5',
						),
					);
				}
				if ( 2 === $index ) {
					return array(
						(object) array(
							'stat_date'   => '2026-03-08',
							'click_count' => '1',
						),
						(object) array(
							'stat_date'   => '2026-03-10',
							'click_count' => '3',
						),
					);
				}
				return array(
					(object) array(
						'link_url'     => 'https://example.com',
						'link_text'    => 'Example',
						'total_clicks' => '4',
					),
				);
			}
		};
	}

	/** Exact dates override the numeric range across every query and chart bucket. */
	public function test_exact_window_controls_queries_and_response(): void {
		$window = extrachill_analytics_resolve_date_range(
			array(
				'start_date' => '2026-03-08',
				'end_date'   => '2026-03-10',
			),
			90
		);

		$result = extrachill_analytics_provide_link_page_analytics( null, 42, 90, $window );

		$this->assertSame( '2026-03-08', $result['start_date'] );
		$this->assertSame( '2026-03-10', $result['end_date'] );
		$this->assertSame( 3, $result['days'] );
		$this->assertSame( array( '2026-03-08', '2026-03-09', '2026-03-10' ), $result['chart_data']['labels'] );
		$this->assertSame( array( 2, 0, 5 ), $result['chart_data']['datasets'][0]['data'] );
		$this->assertSame( array( 1, 0, 3 ), $result['chart_data']['datasets'][1]['data'] );
		$this->assertSame(
			array(
				'total_views'  => 7,
				'total_clicks' => 4,
			),
			$result['summary']
		);
		$this->assertSame( 4, $result['top_links'][0]['clicks'] );

		$this->assertCount( 3, $GLOBALS['wpdb']->queries );
		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			$this->assertStringContainsString( "stat_date BETWEEN '2026-03-08' AND '2026-03-10'", $query );
		}
	}

	/** External consumers may pass raw paired dates to the owning provider. */
	public function test_raw_exact_pair_is_validated_by_provider(): void {
		$result = extrachill_analytics_provide_link_page_analytics( null, 42, 90, '2026-03-08', '2026-03-10' );

		$this->assertSame( '2026-03-08', $result['start_date'] );
		$this->assertSame( '2026-03-10', $result['end_date'] );
		$this->assertSame( 3, $result['days'] );

		$partial = extrachill_analytics_provide_link_page_analytics( null, 42, 90, '2026-03-08', '' );
		$this->assertSame( 'invalid_analytics_date_range', $partial->code );
	}

	/** Numeric callers retain their inclusive relative site-calendar window. */
	public function test_legacy_numeric_range_remains_supported(): void {
		$result = extrachill_analytics_provide_link_page_analytics( null, 42, 2 );

		$this->assertSame( '2026-03-09', $result['start_date'] );
		$this->assertSame( '2026-03-10', $result['end_date'] );
		$this->assertSame( 2, $result['days'] );
		$this->assertSame( array( '2026-03-09', '2026-03-10' ), $result['chart_data']['labels'] );
	}

	/** The ability exposes paired dates and uses the shared 90-day validator. */
	public function test_ability_date_contract_uses_shared_validation(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-link-page-analytics.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.

		$this->assertStringContainsString( "'start_date'", $source );
		$this->assertStringContainsString( "'end_date'", $source );
		$this->assertStringContainsString( 'extrachill_analytics_resolve_date_range( $input, 90 )', $source );
		$this->assertStringContainsString( '$date_range, $date_window', $source );

		$partial = extrachill_analytics_resolve_date_range( array( 'start_date' => '2026-01-01' ), 90 );
		$large   = extrachill_analytics_resolve_date_range(
			array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-04-01',
			),
			90
		);

		$this->assertSame( 'invalid_analytics_date_range', $partial->code );
		$this->assertSame( 'analytics_date_range_too_large', $large->code );
	}
}
