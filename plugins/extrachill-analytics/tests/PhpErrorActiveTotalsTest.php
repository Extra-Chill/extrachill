<?php
/**
 * Tests for the PHP error active-count contract (issue #128).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify active_total counts only recent occurrences, not a signature's
 * full-window count, and that full-window totals/rates are preserved.
 */
final class PhpErrorActiveTotalsTest extends TestCase {

	/**
	 * The #128 regression: a large historical spike that is resolved, plus one
	 * recent occurrence, must yield an active_total of 1 — NOT the spike's
	 * full-window count.
	 */
	public function test_resolved_spike_plus_one_recent_occurrence_does_not_inflate_active_total(): void {
		$rows = array(
			array(
				'signature'    => 'oldspike',
				'count'        => 1000, // Large historical spike.
				'active_count' => 0,    // Resolved — nothing inside the active window.
				'last_seen'    => gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ),
			),
			array(
				'signature'    => 'fresh',
				'count'        => 1,    // One recent occurrence.
				'active_count' => 1,    // That single occurrence is inside the window.
				'last_seen'    => gmdate( 'Y-m-d H:i:s', time() - ( 1 * HOUR_IN_SECONDS ) ),
			),
		);

		$result = extrachill_analytics_compute_php_error_active_totals( $rows, 28, 24 );

		// Full-window total is preserved unchanged.
		$this->assertSame( 1001, $result['total'] );

		// Active volume is the single recent occurrence, not 1001.
		$this->assertSame( 1, $result['active_total'] );
		$this->assertSame( 1, $result['active_signatures'] );

		// Last-24h active volume expressed per day = 1 / (24/24) = 1.0.
		$this->assertSame( 1.0, $result['active_per_day'] );

		// The resolved spike row is marked inactive; the fresh row is active.
		$by_sig = array_column( $result['rows'], null, 'signature' );
		$this->assertFalse( $by_sig['oldspike']['active'] );
		$this->assertTrue( $by_sig['fresh']['active'] );
	}

	/**
	 * Before #128, a signature whose last_seen was recent contributed its entire
	 * count. With active_count semantics, only the in-window portion counts even
	 * when the row stays "active".
	 */
	public function test_active_total_uses_active_count_not_full_count(): void {
		$rows = array(
			array(
				'signature'    => 'partial',
				'count'        => 50,   // 50 across the full window.
				'active_count' => 3,    // Only 3 inside the active window.
			),
		);

		$result = extrachill_analytics_compute_php_error_active_totals( $rows, 28, 24 );

		$this->assertSame( 50, $result['total'] );
		$this->assertSame( 3, $result['active_total'] );
		$this->assertSame( 3.0, $result['active_per_day'] );
		// Full-window per-day trend is preserved.
		$this->assertSame( round( 50 / 28, 1 ), $result['rows'][0]['per_day'] );
	}

	/**
	 * When every signature is resolved, active volume is zero but the full-window
	 * total remains intact for trend continuity.
	 */
	public function test_all_resolved_yields_zero_active_but_preserves_total(): void {
		$rows = array(
			array(
				'signature'    => 'a',
				'count'        => 500,
				'active_count' => 0,
			),
			array(
				'signature'    => 'b',
				'count'        => 40,
				'active_count' => 0,
			),
		);

		$result = extrachill_analytics_compute_php_error_active_totals( $rows, 28, 24 );

		$this->assertSame( 540, $result['total'] );
		$this->assertSame( 0, $result['active_total'] );
		$this->assertSame( 0, $result['active_signatures'] );
		$this->assertSame( 0.0, $result['active_per_day'] );
	}

	/**
	 * The active_per_day rate normalizes to the active-window length, not the
	 * full window. A 48-hour window with 6 active occurrences => 3.0/day.
	 */
	public function test_active_per_day_normalizes_to_active_window_length(): void {
		$rows = array(
			array(
				'signature'    => 's',
				'count'        => 6,
				'active_count' => 6,
			),
		);

		$result = extrachill_analytics_compute_php_error_active_totals( $rows, 28, 48 );

		$this->assertSame( 6, $result['active_total'] );
		$this->assertSame( 3.0, $result['active_per_day'] ); // 6 / (48/24).
	}

	/**
	 * Missing active_count defaults to 0 (defensive — a row must never count as
	 * active without explicit in-window occurrences).
	 */
	public function test_missing_active_count_defaults_to_zero(): void {
		$rows = array(
			array(
				'signature' => 's',
				'count'     => 9,
			),
		);

		$result = extrachill_analytics_compute_php_error_active_totals( $rows, 7, 24 );

		$this->assertSame( 9, $result['total'] );
		$this->assertSame( 0, $result['active_total'] );
		$this->assertFalse( $result['rows'][0]['active'] );
	}
}
