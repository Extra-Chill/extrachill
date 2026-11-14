<?php
/**
 * Disk pressure alarms through the fatal alarm delivery path (#1418).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once __DIR__ . '/class-fake-dispatch-message-ability.php';

// The class writes a temp debug.log fixture directly.
// phpcs:disable WordPress.WP.AlternativeFunctions

/** Disk pressure pages once, re-arms after 24h or on halving, and never when healthy. */
final class FatalRateAlarmDiskTest extends Extrachill_Analytics_TestCase {

	/**
	 * Empty temp debug.log.
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

	/**
	 * Injected free bytes, out of 150 GB.
	 *
	 * @var int
	 */
	private $free = 0;

	/** Reset alarm config and state; inject the disk reading. */
	public function set_up(): void {
		parent::set_up();
		$this->log_path = tempnam( sys_get_temp_dir(), 'ec_disk_alarm_' );
		ini_set( 'error_log', $this->log_path ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->dispatcher = new Extrachill_Analytics_Fake_Dispatch_Message_Ability();
		foreach ( array( 'channel', 'dispatch_channel', 'notify_channel', 'mention', 'state' ) as $suffix ) {
			delete_site_option( 'extrachill_analytics_fatal_alarm_' . $suffix );
		}
		update_site_option( 'extrachill_analytics_fatal_alarm_channel', 'alerts-recipient' );
		update_site_option( 'extrachill_analytics_fatal_alarm_dispatch_channel', 'agent' );
		update_site_option( 'extrachill_analytics_fatal_alarm_notify_channel', 'notify' );
		$this->dispatcher->sent_channels = array( 'agent', 'notify' );
		$this->free                      = 37 * GB_IN_BYTES;
		add_filter(
			'extrachill_analytics_disk_alarm_reading',
			function () {
				return array(
					'free'  => $this->free,
					'total' => 150 * GB_IN_BYTES,
				);
			},
			20
		);
	}

	/** Remove fixtures. */
	public function tear_down(): void {
		if ( '' !== $this->log_path && file_exists( $this->log_path ) ) {
			unlink( $this->log_path );
		}
		ini_restore( 'error_log' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_ini_restore -- restores the fixture override.
		parent::tear_down();
	}

	/**
	 * Current dedupe state.
	 *
	 * @return array
	 */
	private function state(): array {
		return (array) get_site_option( 'extrachill_analytics_fatal_alarm_state', array() );
	}

	/** Today's root (75% used, 37 GB free) never pages. */
	public function test_healthy_disk_does_not_page(): void {
		$result = extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher );
		$this->assertCount( 0, $result['candidates'] );
	}

	/** Low free space pages once with disk text, then dedupes. */
	public function test_low_disk_pages_then_dedupes(): void {
		$this->free = 8 * GB_IN_BYTES;

		$first = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );
		$this->assertCount( 1, $first['alarms'] );
		$this->assertSame( 'disk', $first['alarms'][0]['kind'] );
		$this->assertSame( 95, $first['alarms'][0]['rate_per_hr'] );
		$this->assertSame( 'delivered', $first['beacon']['outcome'] );
		$this->assertStringContainsString( 'Disk pressure alarm', $this->dispatcher->requests[0]['message'] );
		$this->assertStringNotContainsString( 'PHP fatal', $this->dispatcher->requests[0]['message'] );
		$sig = $first['alarms'][0]['signature'];
		$this->assertSame( 8 * GB_IN_BYTES, $this->state()[ $sig ]['last_alerted_free'] );

		$this->assertCount( 0, extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher )['alarms'] );
	}

	/** Free space halving since the last page re-pages immediately; 24h quiet re-arms. */
	public function test_escalation_and_daily_rearm(): void {
		$this->free = 8 * GB_IN_BYTES;
		$first      = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );
		$sig        = $first['alarms'][0]['signature'];

		$this->free = 3 * GB_IN_BYTES;
		$escalated  = extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher );
		$this->assertContains( 'escalated', $escalated['alarms'][0]['reasons'] );

		$this->free                         = 7 * GB_IN_BYTES;
		$state                              = $this->state();
		$state[ $sig ]['last_alerted_at']   = time() - DAY_IN_SECONDS - 60;
		$state[ $sig ]['last_alerted_free'] = 8 * GB_IN_BYTES;
		update_site_option( 'extrachill_analytics_fatal_alarm_state', $state );
		$rearmed = extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher );
		$this->assertContains( 'still_low_after_24h', $rearmed['alarms'][0]['reasons'] );
	}

	/** The percentage threshold alone triggers, and thresholds are filterable. */
	public function test_percentage_threshold_and_filter(): void {
		$this->free = 14 * GB_IN_BYTES; // 9.3% free of 150 GB, above 10 GB.
		$this->assertCount( 1, extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher )['alarms'] );

		$relax = static fn() => array(
			'min_free_pct'   => 0.05,
			'min_free_bytes' => GB_IN_BYTES,
		);
		add_filter( 'extrachill_analytics_disk_alarm_thresholds', $relax );
		$this->assertCount( 0, extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher )['candidates'] );
		remove_filter( 'extrachill_analytics_disk_alarm_thresholds', $relax );
	}

	/** Undelivered disk pages keep no state and retry. */
	public function test_undelivered_disk_alarm_retries(): void {
		$this->free                      = 5 * GB_IN_BYTES;
		$this->dispatcher->sent_channels = array();
		$first                           = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );
		$this->assertSame( 'delivery_failed', $first['beacon']['outcome'] );
		$this->assertSame( array(), $this->state() );
		$this->assertCount( 1, extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher )['alarms'] );
	}
}
