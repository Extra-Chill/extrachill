<?php
/**
 * Source-string contract tests for the PHP error summary (issue #128).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Lock down the counting semantics so the #128 regression cannot silently
 * return: active volume must come from in-window occurrences (active_count),
 * the persisted/live merge must apply the snapshot byte watermark, and the old
 * "add the full-window count when last_seen is recent" line must stay gone.
 */
final class PhpErrorSummaryContractTest extends TestCase {

	/**
	 * Read the production ability source.
	 *
	 * @return string
	 */
	private function get_ability_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-php-error-summary.php' );
		$this->assertNotFalse( $source );

		return $source;
	}

	/**
	 * The exact buggy line from #128 must be gone: accumulating a row's full
	 * 'count' into active_total just because it is flagged active.
	 */
	public function test_buggy_full_count_active_accumulation_is_removed(): void {
		$source = $this->get_ability_source();

		$this->assertStringNotContainsString( "\$active_total += \$row['count'];", $source );
	}

	/**
	 * Active volume must be sourced from active_count, accumulated by the pure
	 * helper — not from each row's full-window count.
	 */
	public function test_active_total_uses_active_count_via_helper(): void {
		$source = $this->get_ability_source();

		$this->assertStringContainsString( 'function extrachill_analytics_compute_php_error_active_totals', $source );
		$this->assertStringContainsString( "\$active_total += \$row['active_count'];", $source );
	}

	/**
	 * The persisted active count must be resolved at day-granularity from rows
	 * whose last_seen falls inside the active cutoff — not from the collapsed
	 * full-window sum.
	 */
	public function test_active_persisted_query_uses_last_seen_cutoff(): void {
		$source = $this->get_ability_source();

		$this->assertStringContainsString( 'last_seen >= %s', $source );
		$this->assertStringContainsString( 'SUM(count) AS active_count', $source );
	}

	/**
	 * The persisted/live merge must apply the snapshot byte watermark so live
	 * entries are byte-disjoint from persisted entries (no double counting).
	 */
	public function test_live_read_applies_snapshot_byte_watermark(): void {
		$source = $this->get_ability_source();

		$this->assertStringContainsString( 'extrachill_analytics_php_error_log_snapshot_offset', $source );
		$this->assertStringContainsString( '$watermark', $source );
	}

	/**
	 * The active_per_day rate must normalize to the active-window length, not the
	 * full-window denominator, matching the platform-health consumer.
	 */
	public function test_active_per_day_normalizes_to_active_window(): void {
		$source = $this->get_ability_source();

		// The old, incorrect normalization divided by the full-window denominator.
		$this->assertStringNotContainsString( '$active_per_day = round( $active_total / $denominator, 1 );', $source );
		// The new normalization uses the active-window length in days, guarded
		// against a sub-1-day divisor.
		$this->assertStringContainsString( '$active_window_days = max( 1.0, (float) $active_window_hours / 24.0 );', $source );
		$this->assertStringContainsString( '$active_total / $active_window_days', $source );
	}
}
