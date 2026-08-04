<?php
/**
 * Tests for shared inclusive analytics date windows.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/** Verify validation, boundaries, and per-report contracts. */
final class DateRangeContractTest extends TestCase {
	/** Exact ranges expose an exclusive UTC SQL boundary. */
	public function test_resolves_inclusive_dates_to_utc_boundaries(): void {
		$window = extrachill_analytics_resolve_date_range(
			array(
				'start_date' => '2026-02-27',
				'end_date'   => '2026-03-02',
			)
		);

		$this->assertIsArray( $window );
		$this->assertSame( 4, $window['days'] );
		$this->assertSame( '2026-02-27 00:00:00', $window['start_at'] );
		$this->assertSame( '2026-03-03 00:00:00', $window['end_exclusive'] );
	}

	/** The comparison window is adjacent and exactly equal in length. */
	public function test_previous_window_is_immediately_adjacent(): void {
		$window   = extrachill_analytics_resolve_date_range(
			array(
				'start_date' => '2026-07-08',
				'end_date'   => '2026-07-14',
			)
		);
		$previous = extrachill_analytics_previous_date_range( $window );

		$this->assertSame( '2026-07-01', $previous['start_date'] );
		$this->assertSame( '2026-07-07', $previous['end_date'] );
		$this->assertSame( $window['start_at'], $previous['end_exclusive'] );
	}

	/** Invalid, partial, reversed, and oversized selections fail before caching. */
	public function test_rejects_invalid_and_oversized_ranges(): void {
		$this->assertTrue( is_wp_error( extrachill_analytics_resolve_date_range( array( 'start_date' => '2026-01-01' ) ) ) );
		$this->assertTrue(
			is_wp_error(
				extrachill_analytics_resolve_date_range(
					array(
						'start_date' => '2026-02-30',
						'end_date'   => '2026-03-01',
					)
				)
			)
		);
		$this->assertTrue(
			is_wp_error(
				extrachill_analytics_resolve_date_range(
					array(
						'start_date' => '2026-03-02',
						'end_date'   => '2026-03-01',
					)
				)
			)
		);
		$this->assertTrue(
			is_wp_error(
				extrachill_analytics_resolve_date_range(
					array(
						'start_date' => '2025-01-01',
						'end_date'   => '2026-01-01',
					)
				)
			)
		);
	}

	/** Each report preserves its honest historical cutoff semantics. */
	public function test_report_sources_use_canonical_exact_bounds(): void {
		$retention  = $this->source( 'get-retention-stats.php' );
		$growth     = $this->source( 'get-surface-growth.php' );
		$conversion = $this->source( 'get-conversion-map.php' );

		$this->assertStringContainsString( '$date_range[\'end_exclusive\']', $retention );
		$this->assertStringContainsString( 'strtotime( "-{$cohort_weeks} weeks", (int) strtotime( $now_utc ) )', $retention );
		$this->assertStringContainsString( 'extrachill_analytics_previous_date_range( $date_range )', $growth );
		$this->assertStringContainsString( 'post_date_gmt >= %s AND post_date_gmt < %s', $growth );
		$this->assertStringNotContainsString( 'get_blog_option( $blog_id, \'gmt_offset\'', $growth );
		$this->assertStringContainsString( "'created_at < %s'", $conversion );
		$this->assertStringContainsString( 'AND e.created_at < %s', $conversion );
		$this->assertStringContainsString( '$window_end_ts - ( $return_observation_days * DAY_IN_SECONDS )', $conversion );
	}

	/**
	 * Read one report source fixture.
	 *
	 * @param string $file Ability filename.
	 * @return string Source.
	 */
	private function source( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/' . $file );
		$this->assertNotFalse( $source );
		return $source;
	}
}
