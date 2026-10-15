<?php
/**
 * Delivery contract tests for the PHP fatal-rate alarm (issue #304).
 *
 * The alarm used to default to a dispatch channel that no longer existed, fall
 * back to a Discord path with no credentials, and write dedupe state whether or
 * not delivery succeeded, so every alarm was silently dropped. These tests pin
 * the replacement contract: configured channels only, fallback through the same
 * dispatch ability, dedupe only for delivered alarms, and a loud failure
 * otherwise.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-fake-dispatch-message-ability.php';

// The class writes temp debug.log fixtures directly.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Fatal alarm delivery, dedupe, and task outcome.
 */
final class FatalRateAlarmDeliveryTest extends Extrachill_Analytics_TestCase {

	/**
	 * Temp debug.log fixture path.
	 *
	 * @var string
	 */
	private $log_path = '';

	/**
	 * Fake dispatch-message ability.
	 *
	 * @var Extrachill_Analytics_Fake_Dispatch_Message_Ability
	 */
	private $dispatcher;

	/**
	 * Point the alarm at a temp log and reset its config and state.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->log_path = tempnam( sys_get_temp_dir(), 'ec_fatal_alarm_' );
		ini_set( 'error_log', $this->log_path ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$this->dispatcher = new Extrachill_Analytics_Fake_Dispatch_Message_Ability();

		foreach ( array( 'channel', 'dispatch_channel', 'notify_channel', 'mention', 'state' ) as $suffix ) {
			delete_site_option( 'extrachill_analytics_fatal_alarm_' . $suffix );
		}
	}

	/**
	 * Remove the temp log.
	 */
	public function tear_down(): void {
		if ( '' !== $this->log_path && file_exists( $this->log_path ) ) {
			unlink( $this->log_path );
		}
		ini_restore( 'error_log' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_ini_restore -- restores the fixture override.
		parent::tear_down();
	}

	/**
	 * Write live fatal entries (inside the recency horizon) for each message.
	 *
	 * @param string[] $messages Distinct fatal messages, one signature each.
	 */
	private function write_live_fatals( array $messages ): void {
		$stamp = gmdate( 'd-M-Y H:i:s', time() - 30 );
		$file  = rtrim( WP_CONTENT_DIR, '/' ) . '/plugins/example/broken.php';
		$lines = array();
		foreach ( $messages as $i => $message ) {
			$lines[] = sprintf( '[%s UTC] PHP Fatal error:  %s in %s on line %d', $stamp, $message, $file, $i + 1 );
		}
		file_put_contents( $this->log_path, implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Configure the recipient and channels.
	 *
	 * @param string $dispatch Agent dispatch channel ('' for none).
	 * @param string $notify   Notify-only channel ('' for none).
	 */
	private function configure( string $dispatch, string $notify ): void {
		update_site_option( 'extrachill_analytics_fatal_alarm_channel', 'alerts-recipient' );
		update_site_option( 'extrachill_analytics_fatal_alarm_dispatch_channel', $dispatch );
		update_site_option( 'extrachill_analytics_fatal_alarm_notify_channel', $notify );
	}

	/**
	 * Current dedupe state.
	 *
	 * @return array
	 */
	private function state(): array {
		return (array) get_site_option( 'extrachill_analytics_fatal_alarm_state', array() );
	}

	/**
	 * No vendor channel or recipient literal is assumed when unconfigured.
	 */
	public function test_unconfigured_defaults_are_empty(): void {
		$this->assertSame( '', extrachill_analytics_fatal_alarm_channel() );
		$this->assertSame( '', extrachill_analytics_fatal_alarm_dispatch_channel() );
		$this->assertSame( '', extrachill_analytics_fatal_alarm_notify_channel() );
		$this->assertFalse( extrachill_analytics_fatal_alarm_delivery_configured() );
	}

	/**
	 * Unconfigured + alarm: nothing dispatched, nothing deduped, reported loudly.
	 */
	public function test_unconfigured_alarm_is_not_deduped_and_reports_unconfigured(): void {
		$this->write_live_fatals( array( 'Unconfigured fatal' ) );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$this->assertCount( 1, $result['alarms'] );
		$this->assertSame( 'unconfigured', $result['beacon']['outcome'] );
		$this->assertSame( 0, $result['beacon']['sent'] );
		$this->assertSame( array(), $this->dispatcher->requests );
		$this->assertSame( array(), $this->state() );
	}

	/**
	 * Agent dispatch succeeds: delivered on the primary path, deduped.
	 */
	public function test_successful_dispatch_is_deduped(): void {
		$this->configure( 'agent', 'notify' );
		$this->dispatcher->sent_channels = array( 'agent' );
		$this->write_live_fatals( array( 'Primary path fatal' ) );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$this->assertSame( 'delivered', $result['beacon']['outcome'] );
		$this->assertSame( array( 'agent' ), $this->dispatcher->channels() );
		$this->assertSame( 'alerts-recipient', $this->dispatcher->requests[0]['recipient'] );
		$this->assertSame( 'extrachill-analytics-fatal-alarm', $this->dispatcher->requests[0]['metadata']['source'] );
		$this->assertTrue( $result['beacon']['deliveries'][0]['dispatch'] );
		$this->assertArrayHasKey( $result['alarms'][0]['signature'], $this->state() );
	}

	/**
	 * Agent dispatch fails, notify-only succeeds: delivered via fallback, deduped.
	 */
	public function test_notify_fallback_delivers_when_dispatch_fails(): void {
		$this->configure( 'agent', 'notify' );
		$this->dispatcher->error_channels = array( 'agent' );
		$this->dispatcher->sent_channels  = array( 'notify' );
		$this->write_live_fatals( array( 'Fallback path fatal' ) );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$this->assertSame( 'delivered', $result['beacon']['outcome'] );
		$this->assertSame( array( 'agent', 'notify' ), $this->dispatcher->channels() );
		$this->assertFalse( $result['beacon']['deliveries'][0]['dispatch'] );
		$this->assertTrue( $result['beacon']['deliveries'][0]['notify'] );
		$this->assertStringContainsString( 'PHP fatal alarm', $this->dispatcher->requests[1]['message'] );
		$this->assertArrayHasKey( $result['alarms'][0]['signature'], $this->state() );
	}

	/**
	 * A WP_Error from the dispatch ability is recorded on the failed path
	 * (issue #308: the error used to be swallowed, leaving only dispatch:false).
	 */
	public function test_dispatch_wp_error_is_recorded_in_delivery(): void {
		$this->configure( 'agent', 'notify' );
		$this->dispatcher->error_channels = array( 'agent' );
		$this->write_live_fatals( array( 'Erroring dispatch fatal' ) );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$delivery = $result['beacon']['deliveries'][0];
		$this->assertFalse( $delivery['dispatch'] );
		$this->assertSame( 'dispatch_failed', $delivery['dispatch_error']['code'] );
		$this->assertSame( 'Channel unavailable.', $delivery['dispatch_error']['message'] );
		// Notify was attempted and reported not sent, so its path explains itself too.
		$this->assertFalse( $delivery['notify'] );
		$this->assertSame( 'not_sent', $delivery['notify_error']['code'] );
		$this->assertSame( 'delivery_failed', $result['beacon']['outcome'] );
	}

	/**
	 * A response without `sent` records a `not_sent` reason on the path.
	 */
	public function test_not_sent_response_is_recorded_in_delivery(): void {
		$this->configure( 'agent', '' );
		$this->write_live_fatals( array( 'Silently unsent fatal' ) );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$delivery = $result['beacon']['deliveries'][0];
		$this->assertFalse( $delivery['dispatch'] );
		$this->assertSame( 'not_sent', $delivery['dispatch_error']['code'] );
		// The notify path was never configured, and the record says so.
		$this->assertSame( 'channel_unconfigured', $delivery['notify_error']['code'] );
		$this->assertSame( 'delivery_failed', $result['beacon']['outcome'] );
	}

	/**
	 * A successful dispatch records no error keys — they only appear on failure.
	 */
	public function test_successful_dispatch_records_no_error_detail(): void {
		$this->configure( 'agent', 'notify' );
		$this->dispatcher->sent_channels = array( 'agent' );
		$this->write_live_fatals( array( 'Clean dispatch fatal' ) );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$delivery = $result['beacon']['deliveries'][0];
		$this->assertTrue( $delivery['delivered'] );
		$this->assertArrayNotHasKey( 'dispatch_error', $delivery );
		$this->assertArrayNotHasKey( 'notify_error', $delivery );
	}

	/**
	 * Unconfigured delivery still explains both paths in each delivery record.
	 */
	public function test_unconfigured_delivery_records_reason_per_path(): void {
		$this->write_live_fatals( array( 'Unconfigured but explained fatal' ) );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$delivery = $result['beacon']['deliveries'][0];
		$this->assertSame( 'delivery_unconfigured', $delivery['dispatch_error']['code'] );
		$this->assertSame( 'delivery_unconfigured', $delivery['notify_error']['code'] );
	}

	/**
	 * Every path fails: no dedupe write, reported as delivery_failed, retried next tick.
	 */
	public function test_all_paths_failing_is_not_deduped_and_retries(): void {
		$this->configure( 'agent', 'notify' );
		$this->write_live_fatals( array( 'Undeliverable fatal' ) );

		$first = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$this->assertSame( 'delivery_failed', $first['beacon']['outcome'] );
		$this->assertSame( 1, $first['beacon']['failed'] );
		$this->assertSame( array(), $this->state() );

		// The next tick alarms on the same signature again because nothing was deduped.
		$this->dispatcher->sent_channels = array( 'agent' );
		$second                          = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$this->assertCount( 1, $second['alarms'] );
		$this->assertSame( 'delivered', $second['beacon']['outcome'] );
	}

	/**
	 * Some alarms delivered, others not: only the delivered ones are deduped.
	 */
	public function test_partial_delivery_dedupes_only_delivered_alarms(): void {
		$this->configure( 'agent', '' );
		$this->dispatcher->sent_channels = array( 'agent' );
		$this->write_live_fatals( array( 'Deliverable fatal', 'Stuck fatal' ) );

		$probe = extrachill_analytics_evaluate_fatal_alarm( true, false, $this->dispatcher );
		$this->assertCount( 2, $probe['alarms'] );
		$stuck = $probe['alarms'][1]['signature'];

		$this->dispatcher->undeliverable_signatures = array( $stuck );
		$result                                     = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$this->assertSame( 'partial', $result['beacon']['outcome'] );
		$this->assertSame( 1, $result['beacon']['sent'] );
		$this->assertSame( 1, $result['beacon']['failed'] );
		$this->assertCount( 2, $result['beacon']['deliveries'] );
		$state = $this->state();
		$this->assertArrayHasKey( $probe['alarms'][0]['signature'], $state );
		$this->assertArrayNotHasKey( $stuck, $state );
	}

	/**
	 * Test delivery sends a labelled synthetic alarm, even over dry_run, with no dedupe.
	 */
	public function test_test_delivery_sends_synthetic_alarm_without_dedupe(): void {
		$this->configure( 'agent', '' );
		$this->dispatcher->sent_channels = array( 'agent' );

		$result = extrachill_analytics_evaluate_fatal_alarm( true, true, $this->dispatcher );

		$this->assertTrue( $result['test_delivery'] );
		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( 'delivered', $result['beacon']['outcome'] );
		$this->assertCount( 1, $this->dispatcher->requests );
		$this->assertStringContainsString( 'delivery test', $this->dispatcher->requests[0]['message'] );
		$this->assertSame( 'test-delivery', $this->dispatcher->requests[0]['metadata']['signature'] );
		$this->assertSame( array(), $this->state() );
	}

	/**
	 * No alarms: nothing attempted, outcome none.
	 */
	public function test_quiet_tick_attempts_nothing(): void {
		$this->configure( 'agent', 'notify' );

		$result = extrachill_analytics_evaluate_fatal_alarm( false, false, $this->dispatcher );

		$this->assertSame( array(), $result['alarms'] );
		$this->assertSame( 'none', $result['beacon']['outcome'] );
		$this->assertFalse( $result['beacon']['attempted'] );
		$this->assertSame( array(), $this->dispatcher->requests );
	}

	/**
	 * Every undelivered outcome fails the run; delivered and quiet ticks succeed.
	 */
	public function test_failure_reason_maps_outcomes(): void {
		$this->assertSame( 'unconfigured', extrachill_analytics_fatal_alarm_failure_reason( 'unconfigured' ) );
		$this->assertSame( 'delivery_failed', extrachill_analytics_fatal_alarm_failure_reason( 'delivery_failed' ) );
		$this->assertSame( 'partial', extrachill_analytics_fatal_alarm_failure_reason( 'partial' ) );
		$this->assertNull( extrachill_analytics_fatal_alarm_failure_reason( 'delivered' ) );
		$this->assertNull( extrachill_analytics_fatal_alarm_failure_reason( 'none' ) );
	}
}
