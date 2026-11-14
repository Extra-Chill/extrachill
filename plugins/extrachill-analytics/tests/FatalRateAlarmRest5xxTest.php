<?php
/**
 * Sustained REST 5xx alarms through the fatal alarm delivery path (#1380).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once __DIR__ . '/class-fake-dispatch-message-ability.php';
require_once dirname( __DIR__ ) . '/inc/core/rest-5xx-counter.php';

// The class writes a temp debug.log fixture directly.
// phpcs:disable WordPress.WP.AlternativeFunctions

/** REST 5xx alarms page on a sustained rate, dedupe, and never on a single 500. */
final class FatalRateAlarmRest5xxTest extends Extrachill_Analytics_TestCase {

	/**
	 * Empty temp debug.log so only REST candidates exist.
	 *
	 * @var string
	 */
	private $log_path = '';

	/**
	 * Fake dispatcher.
	 *
	 * @var Extrachill_Analytics_Fake_Dispatch_Message_Ability
	 */
	private $dispatcher;

	/** Reset alarm config, state, and the REST counter. */
	public function set_up(): void {
		parent::set_up();
		$this->log_path = tempnam( sys_get_temp_dir(), 'ec_rest_alarm_' );
		ini_set( 'error_log', $this->log_path ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->dispatcher = new Extrachill_Analytics_Fake_Dispatch_Message_Ability();
		foreach ( array( 'channel', 'dispatch_channel', 'notify_channel', 'mention', 'state' ) as $suffix ) {
			delete_site_option( 'extrachill_analytics_fatal_alarm_' . $suffix );
		}
		delete_site_option( EC_ANALYTICS_REST_5XX_OPTION );
		update_site_option( 'extrachill_analytics_fatal_alarm_channel', 'alerts-recipient' );
		update_site_option( 'extrachill_analytics_fatal_alarm_dispatch_channel', 'agent' );
		update_site_option( 'extrachill_analytics_fatal_alarm_notify_channel', 'notify' );
		$this->dispatcher->sent_channels = array( 'agent', 'notify' );
	}

	/** Remove fixtures. */
	public function tear_down(): void {
		if ( '' !== $this->log_path && file_exists( $this->log_path ) ) {
			unlink( $this->log_path );
		}
		ini_restore( 'error_log' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_ini_restore -- restores the fixture override.
		delete_site_option( EC_ANALYTICS_REST_5XX_OPTION );
		parent::tear_down();
	}

	/**
	 * Store counts for the previous full hour and the current hour.
	 *
	 * @param int $previous Previous-hour count.
	 * @param int $current  Current-hour count.
	 * @param int $now      Unix time.
	 */
	private function seed( int $previous, int $current, int $now ): void {
		$hour = (int) ( floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
		$key  = '/wp-abilities/v1/abilities/(?P<name>[a-zA-Z0-9\-\/]+?)/run [extrachill/resolve-experiment-assignment]|ability_invalid_output';
		$data = array();
		if ( $previous > 0 ) {
			$data[ $hour - HOUR_IN_SECONDS ] = array( $key => $previous );
		}
		if ( $current > 0 ) {
			$data[ $hour ] = array( $key => $current );
		}
		update_site_option( EC_ANALYTICS_REST_5XX_OPTION, $data );
	}

	/** The #1352 shape pages once, with a REST-specific prompt, then dedupes. */
	public function test_sustained_rate_pages_then_dedupes(): void {
		$this->seed( 900, 40, time() );

		$first = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$this->assertCount( 1, $first['alarms'] );
		$this->assertSame( 'rest_5xx', $first['alarms'][0]['kind'] );
		$this->assertSame( 900, $first['alarms'][0]['rate_per_hr'] );
		$this->assertSame( 'delivered', $first['beacon']['outcome'] );
		$this->assertStringContainsString( 'REST 5xx alarm', $this->dispatcher->requests[0]['message'] );
		$this->assertStringContainsString( 'ability_invalid_output', $this->dispatcher->requests[0]['message'] );
		$this->assertStringNotContainsString( 'PHP fatal', $this->dispatcher->requests[0]['message'] );
		$this->assertArrayHasKey( $first['alarms'][0]['signature'], (array) get_site_option( 'extrachill_analytics_fatal_alarm_state', array() ) );

		$second = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );
		$this->assertCount( 0, $second['alarms'] );
		$this->assertCount( 1, $second['candidates'] );
	}

	/** Below threshold, or stopped this hour, never pages. */
	public function test_below_threshold_or_stopped_does_not_page(): void {
		$this->seed( 49, 10, time() );
		$this->assertCount( 0, extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher )['candidates'] );

		$this->seed( 900, 0, time() );
		$stopped = extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher );
		$this->assertCount( 0, $stopped['alarms'] );
		$this->assertSame( array( 'resolved_not_firing' ), $stopped['candidates'][0]['reasons'] );

		$this->seed( 0, 500, time() );
		$this->assertCount( 0, extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher )['candidates'], 'A partial current hour alone is not sustained yet.' );
	}

	/** The threshold is filterable. */
	public function test_threshold_filter(): void {
		$this->seed( 10, 1, time() );
		$raise = static fn() => 5;
		add_filter( 'extrachill_analytics_rest_5xx_alarm_threshold', $raise );
		$this->assertCount( 1, extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher )['alarms'] );
		remove_filter( 'extrachill_analytics_rest_5xx_alarm_threshold', $raise );
	}

	/** Undelivered REST alarms keep no state and retry next tick. */
	public function test_undelivered_rest_alarm_retries(): void {
		$this->seed( 900, 40, time() );
		$this->dispatcher->sent_channels = array();

		$first = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );
		$this->assertSame( 'delivery_failed', $first['beacon']['outcome'] );
		$this->assertSame( array(), (array) get_site_option( 'extrachill_analytics_fatal_alarm_state', array() ) );
		$this->assertCount( 1, extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher )['alarms'] );
	}
}
