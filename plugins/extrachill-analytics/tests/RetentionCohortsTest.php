<?php
/**
 * Tests for mature exact-week retention cohort responses.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify cohort output does not conflate late activity or censored horizons.
 */
final class RetentionCohortsTest extends TestCase {
	/**
	 * Exact W1 activity is retained while a no-return cohort remains zero.
	 */
	public function test_exact_w1_and_no_return_cohorts(): void {
		$cohorts = $this->build( array( $this->row( '2026-01-05', 2, 1, 0 ) ) );

		$this->assertSame( 1, $cohorts[0]['returned_w1'] );
		$this->assertSame( 0, $cohorts[0]['returned_w2'] );
		$this->assertSame( 0.5, $cohorts[0]['retention_w1'] );
		$this->assertSame( 0.0, $cohorts[0]['retention_w2'] );
	}

	/**
	 * Exact W2 activity is separate from W1 activity.
	 */
	public function test_exact_w2_activity_is_reported_independently(): void {
		$cohorts = $this->build( array( $this->row( '2026-01-05', 1, 0, 1 ) ) );

		$this->assertSame( 0, $cohorts[0]['returned_w1'] );
		$this->assertSame( 1, $cohorts[0]['returned_w2'] );
		$this->assertSame( 0.0, $cohorts[0]['retention_w1'] );
		$this->assertSame( 1.0, $cohorts[0]['retention_w2'] );
	}

	/**
	 * A later-only return must not be promoted into either exact-week result.
	 */
	public function test_late_only_return_is_not_counted_as_w1_or_w2(): void {
		$cohorts = $this->build( array( $this->row( '2026-01-05', 1, 0, 0 ) ) );

		$this->assertSame( 0, $cohorts[0]['returned_w1'] );
		$this->assertSame( 0, $cohorts[0]['returned_w2'] );

		$source = $this->get_retention_source();
		$this->assertStringContainsString( 'activity.created_at >= DATE_ADD(acquisition.cohort_week_start, INTERVAL 7 DAY)', $source );
		$this->assertStringContainsString( 'activity.created_at >= DATE_ADD(acquisition.cohort_week_start, INTERVAL 14 DAY)', $source );
		$this->assertStringNotContainsString( 'max_week >= first_week', $source );
	}

	/**
	 * Acquisition is selected from all history before cohort lookback filtering.
	 */
	public function test_pre_window_history_is_used_for_acquisition(): void {
		$source = $this->get_retention_source();

		$this->assertStringContainsString( 'WHERE {$cohort_where_clause}', $source );
		$this->assertStringContainsString( 'HAVING MIN(created_at) >= %s', $source );
		$this->assertStringContainsString( 'DATE_SUB( DATE( MIN(created_at) )', $source );
		$this->assertStringContainsString( '$cohort_values = array( $event_type, $now_utc );', $source );
		$this->assertStringNotContainsString( '$cohort_values = array( $event_type, $cohort_since );', $source );
	}

	/**
	 * Recent cohorts expose censoring rather than artificial zero retention.
	 */
	public function test_immature_cohorts_are_explicitly_censored(): void {
		$cohorts = $this->build( array( $this->row( '2026-01-19', 1, 0, 0 ) ), '2026-01-30 00:00:00' );

		$this->assertFalse( $cohorts[0]['w1_mature'] );
		$this->assertFalse( $cohorts[0]['w2_mature'] );
		$this->assertNull( $cohorts[0]['returned_w1'] );
		$this->assertNull( $cohorts[0]['returned_w2'] );
		$this->assertNull( $cohorts[0]['retention_w1'] );
		$this->assertNull( $cohorts[0]['retention_w2'] );
		$this->assertSame( '2026-01-19', $cohorts[0]['cohort_week_start'] );
		$this->assertSame( '202604', $cohorts[0]['cohort_week'] );
	}

	/**
	 * Build a cohort response against a fixed observation cutoff.
	 *
	 * @param array<object> $rows Rows returned by the aggregate query.
	 * @param string        $as_of UTC observation cutoff.
	 * @return array<int,array<string,int|float|string|bool|null>>
	 */
	private function build( $rows, $as_of = '2026-02-01 00:00:00' ) {
		return extrachill_analytics_build_cohort_retention( $rows, $as_of );
	}

	/**
	 * Create an aggregate-query row fixture.
	 *
	 * @param string $week_start ISO week start.
	 * @param int    $size Cohort size.
	 * @param int    $w1 Exact W1 returns.
	 * @param int    $w2 Exact W2 returns.
	 * @return object
	 */
	private function row( $week_start, $size, $w1, $w2 ) {
		return (object) array(
			'cohort_week_start' => $week_start,
			'cohort_size'       => $size,
			'returned_w1'       => $w1,
			'returned_w2'       => $w2,
		);
	}

	/**
	 * Read the production ability source for query-contract assertions.
	 *
	 * @return string
	 */
	private function get_retention_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-retention-stats.php' );

		$this->assertNotFalse( $source );

		return $source;
	}
}
