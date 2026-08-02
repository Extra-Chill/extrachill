<?php
/**
 * Tests for bounded analytics report-result caching.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/report-result-cache.php';

/**
 * Verify cache identity, freshness, warm reads, and stale behavior.
 */
final class ReportResultCacheTest extends TestCase {
	/**
	 * Reset cache fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['extrachill_analytics_test_site_transients']  = array();
		$GLOBALS['extrachill_analytics_test_transient_ttls']   = array();
		$GLOBALS['extrachill_analytics_test_cache']            = array();
		$GLOBALS['extrachill_analytics_test_ext_object_cache'] = true;
	}

	/**
	 * Equivalent normalized inputs share keys; behavior changes do not.
	 */
	public function test_cache_key_normalizes_inputs_and_distinguishes_scope(): void {
		$first = extrachill_analytics_report_cache_key(
			'retention_stats',
			array(
				'days'    => 28,
				'blog_id' => 0,
			)
		);
		$same  = extrachill_analytics_report_cache_key(
			'retention_stats',
			array(
				'blog_id' => 0,
				'days'    => 28,
			)
		);

		$this->assertSame( $first, $same );
		$this->assertNotSame(
			$first,
			extrachill_analytics_report_cache_key(
				'retention_stats',
				array(
					'days'    => 28,
					'blog_id' => 2,
				)
			)
		);
		$this->assertNotSame(
			$first,
			extrachill_analytics_report_cache_key(
				'retention_stats',
				array(
					'days'    => 56,
					'blog_id' => 0,
				)
			)
		);
	}

	/**
	 * A warm read returns the original measurement without recomputation.
	 */
	public function test_warm_read_preserves_as_of_and_exposes_freshness(): void {
		$calls   = 0;
		$compute = static function () use ( &$calls ) {
			++$calls;
			return array(
				'value' => 42,
				'as_of' => '2026-08-02 22:00:00',
			);
		};

		$cold = extrachill_analytics_report_cache_remember( 'conversion_map', array( 'days' => 28 ), $compute );
		$warm = extrachill_analytics_report_cache_remember( 'conversion_map', array( 'days' => 28 ), $compute );

		$this->assertSame( 1, $calls );
		$this->assertSame( 'miss', $cold['freshness']['cache_status'] );
		$this->assertSame( 'hit', $warm['freshness']['cache_status'] );
		$this->assertSame( $cold['as_of'], $warm['as_of'] );
		$this->assertSame( $warm['as_of'], $warm['freshness']['as_of'] );
		$this->assertSame( 300, $warm['freshness']['max_age_seconds'] );

		$key = extrachill_analytics_report_cache_key( 'conversion_map', array( 'days' => 28 ) );
		$this->assertSame( 600, $GLOBALS['extrachill_analytics_test_transient_ttls'][ $key ] );
	}

	/**
	 * A lock loser receives bounded stale data instead of repeating the query.
	 */
	public function test_concurrent_refresh_serves_bounded_stale_result(): void {
		$key       = extrachill_analytics_report_cache_key( 'surface_growth', array( 'weeks' => 4 ) );
		$lock_key  = $key . '_lock';
		$generated = time() - 301;
		$GLOBALS['extrachill_analytics_test_site_transients'][ $key ] = array(
			'generated_at' => $generated,
			'result'       => array(
				'as_of' => '2026-08-02 21:00:00',
				'value' => 7,
			),
		);
		wp_cache_add( $lock_key, 1, 'extrachill_analytics_reports', 30 );

		$result = extrachill_analytics_report_cache_remember(
			'surface_growth',
			array( 'weeks' => 4 ),
			static function () {
				throw new RuntimeException( 'Stale lock loser must not compute.' );
			}
		);

		$this->assertSame( 7, $result['value'] );
		$this->assertSame( 'stale', $result['freshness']['cache_status'] );
		$this->assertTrue( $result['freshness']['is_stale'] );
	}

	/**
	 * Data older than the stale ceiling is recomputed.
	 */
	public function test_expired_stale_payload_is_recomputed(): void {
		$key = extrachill_analytics_report_cache_key( 'retention_stats', array( 'days' => 28 ) );
		$GLOBALS['extrachill_analytics_test_site_transients'][ $key ] = array(
			'generated_at' => time() - 601,
			'result'       => array( 'value' => 1 ),
		);

		$result = extrachill_analytics_report_cache_remember(
			'retention_stats',
			array( 'days' => 28 ),
			static function () {
				return array( 'value' => 2 );
			}
		);

		$this->assertSame( 2, $result['value'] );
		$this->assertSame( 'miss', $result['freshness']['cache_status'] );
	}

	/**
	 * Each ability includes every behavior-affecting parameter in its cache input.
	 */
	public function test_report_wrappers_key_all_behavior_affecting_inputs(): void {
		$root       = dirname( __DIR__ ) . '/inc/core/abilities/';
		$surface    = file_get_contents( $root . 'get-surface-growth.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$retention  = file_get_contents( $root . 'get-retention-stats.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$conversion = file_get_contents( $root . 'get-conversion-map.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.

		$this->assertStringContainsString( "array( 'weeks' => \$weeks )", $surface );
		foreach ( array( 'days', 'end_days_ago', 'blog_id', 'cohort_weeks' ) as $input ) {
			$this->assertStringContainsString( "'{$input}'", $retention );
		}
		foreach ( array( 'days', 'session_gap_mins', 'top_articles', 'min_entry_sessions', 'return_observation_days' ) as $input ) {
			$this->assertStringContainsString( "'{$input}'", $conversion );
		}
	}
}
