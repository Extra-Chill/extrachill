<?php
/**
 * Web server access log 5xx counter and gateway error alarm (#1435).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once __DIR__ . '/class-fake-dispatch-message-ability.php';

// The class writes temp access log fixtures directly.
// phpcs:disable WordPress.WP.AlternativeFunctions

/** Tail, rotation, bounds, summary and alarm for gateway errors. */
final class Http5xxCounterTest extends Extrachill_Analytics_TestCase {

	/**
	 * Temp directory holding the fake access log.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * Fake access log path.
	 *
	 * @var string
	 */
	private $log = '';

	/** Point the counter at a temp access log. */
	public function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/ec_http5xx_' . wp_generate_password( 8, false );
		mkdir( $this->dir );
		$this->log = $this->dir . '/access.log';
		file_put_contents( $this->log, $this->line( 200, '/' ) );
		add_filter( 'extrachill_analytics_http_5xx_log_path', fn() => $this->log, 20 );
	}

	/** Remove fixtures. */
	public function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * One combined-format line.
	 *
	 * @param int      $status Status.
	 * @param string   $path   Request path.
	 * @param int|null $time   Unix time.
	 * @return string Line with newline.
	 */
	private function line( $status, $path, $time = null ) {
		return sprintf(
			'45.84.92.13 - - [%s] "GET %s HTTP/2.0" %d 568 "-" "Mozilla/5.0"' . "\n",
			gmdate( 'd/M/Y:H:i:s +0000', null === $time ? time() : $time ),
			$path,
			$status
		);
	}

	/**
	 * Append lines.
	 *
	 * @param int    $count  Lines.
	 * @param int    $status Status.
	 * @param string $path   Path.
	 * @param int    $time   Unix time.
	 */
	private function append( $count, $status, $path, $time ) {
		file_put_contents( $this->log, str_repeat( $this->line( $status, $path, $time ), $count ), FILE_APPEND );
	}

	/** Lines parse into status, hour and a bounded path class. */
	public function test_parse_line_and_path_class(): void {
		$hit = extrachill_analytics_http_5xx_parse_line( rtrim( $this->line( 504, '/wp-json/wp/v2/posts?x=1', 1794700000 ) ) );
		$this->assertSame( 504, $hit['status'] );
		$this->assertSame( 1794700000, $hit['time'] );
		$this->assertSame( 'wp-json', $hit['class'] );
		$this->assertSame( 'page', extrachill_analytics_http_5xx_path_class( '/events/waterparks-night-3' ) );
		$this->assertNull( extrachill_analytics_http_5xx_parse_line( rtrim( $this->line( 200, '/' ) ) ) );
		$this->assertNull( extrachill_analytics_http_5xx_parse_line( rtrim( $this->line( 404, '/" 500 x' ) ) ) );
	}

	/** First tick initializes at end of file (no backfill), later ticks count only new lines. */
	public function test_tail_initializes_then_counts_new_lines(): void {
		$this->append( 5, 502, '/', time() );
		$this->assertSame( 'initialized', extrachill_analytics_http_5xx_tail()['status'] );
		$this->assertSame( array(), extrachill_analytics_http_5xx_buckets() );

		$this->append( 3, 504, '/wp-json/x', time() );
		$this->append( 2, 200, '/', time() );
		$partial = $this->line( 502, '/late', time() );
		file_put_contents( $this->log, substr( $partial, 0, 30 ), FILE_APPEND );
		$this->assertSame( 'ok', extrachill_analytics_http_5xx_tail()['status'] );

		$summary = extrachill_analytics_http_5xx_summarize( 24, time(), 0 );
		$this->assertSame( 3, $summary['total'] );
		$this->assertSame( 'wp-json', $summary['rows'][0]['route'] );
		$this->assertSame( '504', $summary['rows'][0]['code'] );

		// The partial line is counted once it completes, never twice.
		file_put_contents( $this->log, substr( $partial, 30 ), FILE_APPEND );
		extrachill_analytics_http_5xx_tail();
		extrachill_analytics_http_5xx_tail();
		$this->assertSame( 4, extrachill_analytics_http_5xx_summarize( 24, time(), 0 )['total'] );
	}

	/** Rotation finishes the old file from the ".1" sibling, then reads the new one. */
	public function test_tail_follows_rotation(): void {
		extrachill_analytics_http_5xx_tail();
		$this->append( 2, 502, '/', time() );
		rename( $this->log, $this->log . '.1' );
		file_put_contents( $this->log, str_repeat( $this->line( 504, '/', time() ), 3 ) );
		extrachill_analytics_http_5xx_tail();
		$this->assertSame( 5, extrachill_analytics_http_5xx_summarize( 24, time(), 0 )['total'] );
	}

	/** A backlog larger than the per-tick budget skips to the end instead of stalling. */
	public function test_backlog_beyond_budget_skips(): void {
		extrachill_analytics_http_5xx_tail();
		$this->append( 50, 502, '/', time() );
		$this->assertSame( 'skipped_backlog', extrachill_analytics_http_5xx_tail( 100 )['status'] );
		$this->assertSame( 0, extrachill_analytics_http_5xx_summarize( 24, time(), 0 )['total'] );
		$this->append( 1, 502, '/', time() );
		$this->assertSame( 'ok', extrachill_analytics_http_5xx_tail( 1000 )['status'] );
		$this->assertSame( 1, extrachill_analytics_http_5xx_summarize( 24, time(), 0 )['total'] );
	}

	/** Sustained 504s page through the fatal alarm with gateway text; 503 and 500 never do. */
	public function test_sustained_gateway_errors_page_once(): void {
		foreach ( array( 'channel', 'dispatch_channel', 'notify_channel', 'mention', 'state' ) as $suffix ) {
			delete_site_option( 'extrachill_analytics_fatal_alarm_' . $suffix );
		}
		update_site_option( 'extrachill_analytics_fatal_alarm_channel', 'alerts-recipient' );
		update_site_option( 'extrachill_analytics_fatal_alarm_dispatch_channel', 'agent' );
		update_site_option( 'extrachill_analytics_fatal_alarm_notify_channel', 'notify' );
		$debug = tempnam( sys_get_temp_dir(), 'ec_http_alarm_log_' );
		ini_set( 'error_log', $debug ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$dispatcher                = new Extrachill_Analytics_Fake_Dispatch_Message_Ability();
		$dispatcher->sent_channels = array( 'agent', 'notify' );

		$now      = time();
		$previous = (int) ( floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS ) - HOUR_IN_SECONDS + 60;
		extrachill_analytics_http_5xx_tail();
		$this->append( 25, 504, '/', $previous );
		$this->append( 10, 504, '/wp-json/x', $previous );
		$this->append( 200, 503, '/wp-json/x', $previous );
		$this->append( 200, 500, '/', $previous );
		$this->append( 1, 504, '/', $now );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $dispatcher );
		$http   = array_values( array_filter( $result['alarms'], fn( $a ) => 'http_5xx' === ( $a['kind'] ?? '' ) ) );
		$this->assertCount( 1, $http, 'Only the gateway status pages.' );
		$this->assertSame( 'http_5xx:504', $http[0]['signature'] );
		$this->assertSame( 35, $http[0]['rate_per_hr'] );
		$this->assertStringContainsString( 'page 25, wp-json 10', $http[0]['sample'] );
		$this->assertSame( 'delivered', $result['beacon']['outcome'] );
		$this->assertStringContainsString( 'Gateway error alarm', $dispatcher->requests[0]['message'] );

		$again = extrachill_analytics_evaluate_fatal_alarm( false, false, $dispatcher );
		$this->assertCount( 0, array_filter( $again['alarms'], fn( $a ) => 'http_5xx' === ( $a['kind'] ?? '' ) ), 'Dedupes while unchanged.' );
		unlink( $debug );
		ini_restore( 'error_log' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_ini_restore -- restores the fixture override.
	}

	/** Below threshold or no longer firing does not page. */
	public function test_below_threshold_or_resolved_does_not_page(): void {
		$now      = time();
		$previous = (int) ( floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS ) - HOUR_IN_SECONDS + 60;
		extrachill_analytics_http_5xx_tail();
		$this->append( 29, 502, '/', $previous );
		$this->append( 1, 502, '/', $now );
		extrachill_analytics_http_5xx_tail();
		$this->assertCount( 0, extrachill_analytics_http_5xx_alarm_candidates( array(), $now, HOUR_IN_SECONDS ) );

		$this->append( 40, 504, '/', $previous );
		extrachill_analytics_http_5xx_tail();
		$rows = extrachill_analytics_http_5xx_alarm_candidates( array(), $now, HOUR_IN_SECONDS );
		$this->assertCount( 1, $rows );
		$this->assertFalse( $rows[0]['would_alarm'], 'No 504 in the current hour: resolved, not paged.' );
	}
}
