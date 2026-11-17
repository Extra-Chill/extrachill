<?php
/**
 * Exact live windows and honest daily persistence semantics (#1655).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

// The test writes a temp debug.log fixture directly.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * A `seconds` window filters live entries by exact timestamp, normalizes
 * per-day rates to the fractional window, and leaves `days`-only callers alone.
 */
final class PhpErrorWindowTest extends TestCase {

	/**
	 * Find the summary row whose sample contains a marker.
	 *
	 * @param array  $result Summary result.
	 * @param string $marker Marker text.
	 * @return array|null
	 */
	private function find_row( array $result, $marker ) {
		foreach ( $result['rows'] as $row ) {
			if ( false !== strpos( (string) $row['sample'], $marker ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Live entries outside a 15-minute window are excluded; legacy callers still see them.
	 */
	public function test_seconds_window_filters_live_entries_and_keeps_days_callers_unchanged(): void {
		$path     = tempnam( sys_get_temp_dir(), 'ec_window_' );
		$previous = ini_get( 'error_log' );
		$now      = time();
		$old      = 'Ec1655OldMarker';
		$fresh    = 'Ec1655FreshMarker';
		file_put_contents(
			$path,
			'[' . gmdate( 'd-M-Y H:i:s', $now - 1800 ) . " UTC] PHP Fatal error:  {$old} in /tmp/ec1655-old.php on line 1\n" .
			'[' . gmdate( 'd-M-Y H:i:s', $now - 60 ) . " UTC] PHP Fatal error:  {$fresh} in /tmp/ec1655-new.php on line 1\n"
		);
		ini_set( 'error_log', $path ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$queries = array();
		$capture = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $capture );

		try {
			$result = extrachill_analytics_ability_get_php_error_summary(
				array(
					'days'    => 7,
					'seconds' => 900,
				)
			);
			$this->assertNull( $this->find_row( $result, $old ), 'Entries older than the window are excluded.' );
			$row = $this->find_row( $result, $fresh );
			$this->assertNotNull( $row, 'Entries inside the window are counted.' );
			$this->assertSame( 1, (int) $row['count'] );
			$this->assertEqualsWithDelta( 96.0, (float) $row['per_day'], 0.01, 'Rates normalize to the fractional window.' );
			$this->assertSame( 900, $result['window_seconds'] );
			$this->assertStringContainsString( 'exact sub-day counts are unavailable', $result['window_note'] );
			$this->assertNotEmpty(
				array_filter(
					$queries,
					static function ( $sql ) {
						return false !== strpos( $sql, 'last_seen >=' ) && false !== strpos( $sql, 'snapshot_day >=' );
					}
				),
				'Persisted daily rows are bounded by last_seen inside the window.'
			);

			$legacy = extrachill_analytics_ability_get_php_error_summary( array( 'days' => 7 ) );
			$this->assertNotNull( $this->find_row( $legacy, $old ), 'days-only callers keep the full window.' );
			$this->assertNotNull( $this->find_row( $legacy, $fresh ) );
			$this->assertSame( '', $legacy['window_note'] );
		} finally {
			remove_filter( 'query', $capture );
			ini_set( 'error_log', $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			unlink( $path );
		}
	}
}
