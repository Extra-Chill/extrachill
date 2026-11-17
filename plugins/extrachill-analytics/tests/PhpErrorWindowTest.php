<?php
/** Exact live windows and honest daily persistence semantics (#1655). */
use PHPUnit\Framework\TestCase;

final class PhpErrorWindowTest extends TestCase {
	public function test_live_window_filters_old_entries_and_normalizes_fractional_days(): void {
		global $wpdb;
		$original_wpdb = $wpdb;
		$wpdb          = new class {
			public $queries = array();
			public function prepare( $sql, $values ) {
				foreach ( $values as $value ) {
					$sql = preg_replace( '/%s/', "'" . $value . "'", $sql, 1 );
				}
				return $sql;
			}
			public function get_results( $sql ) {
				$this->queries[] = $sql;
				return array();
			}
		};
		$path = tempnam( sys_get_temp_dir(), 'ec_window_' );
		$previous = ini_get( 'error_log' );
		ini_set( 'error_log', $path );
		$now = time();
		file_put_contents( $path,
			'[' . gmdate( 'd-M-Y H:i:s', $now - 1800 ) . " UTC] PHP Fatal error: Old in /tmp/old.php on line 1\n" .
			'[' . gmdate( 'd-M-Y H:i:s', $now - 60 ) . " UTC] PHP Fatal error: Fresh in /tmp/new.php on line 1\n"
		);
		try {
			$result = extrachill_analytics_ability_get_php_error_summary( array( 'days' => 7, 'seconds' => 900 ) );
			$this->assertSame( 1, $result['total'] );
			$this->assertSame( 96.0, $result['rows'][0]['per_day'] );
			$this->assertSame( 900, $result['window_seconds'] );
			$this->assertStringContainsString( 'last_seen >=', $wpdb->queries[0] );
			$this->assertStringContainsString( gmdate( 'Y-m-d H:i', $now - 900 ), $wpdb->queries[0] );
			$this->assertStringContainsString( 'exact sub-day counts are unavailable', $result['window_note'] );
			$wpdb->queries = array();
			$legacy = extrachill_analytics_ability_get_php_error_summary( array( 'days' => 7 ) );
			$this->assertSame( 2, $legacy['total'] );
			$this->assertStringNotContainsString( 'last_seen >=', $wpdb->queries[0] );
			$this->assertSame( '', $legacy['window_note'] );
		} finally {
			$wpdb = $original_wpdb;
			ini_set( 'error_log', $previous );
			unlink( $path );
		}
	}
}
