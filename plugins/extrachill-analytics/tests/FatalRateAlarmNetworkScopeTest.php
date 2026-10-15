<?php
/**
 * Network-scope contract tests for the PHP fatal-rate alarm (issue #308).
 *
 * The alarm's signal is network-wide (one shared debug.log, one network-wide
 * dedupe site option), but the recurring schedule used to be registered on
 * every network site, so one real fatal produced 11 evaluations per tick and
 * failed delivery on the 10 sites without a dispatch transport. These tests
 * pin the replacement contract: the schedule is `network_only` (DM then
 * schedules it on the main site only, unschedules subsite chains, and rejects
 * stale subsite ticks) and the plugin-side ownership guard matches DM's
 * semantics.
 *
 * @package ExtraChill\Analytics
 */

/**
 * Fatal alarm schedule scope and site ownership.
 */
final class FatalRateAlarmNetworkScopeTest extends Extrachill_Analytics_TestCase {

	/**
	 * The recurring schedule is network_only so exactly one site owns the tick.
	 */
	public function test_schedule_definition_is_network_only(): void {
		$definition = extrachill_analytics_fatal_alarm_schedule_definition();

		$this->assertTrue( $definition['network_only'] );
		$this->assertSame( 'fatal_rate_alarm', $definition['task_type'] );
		$this->assertSame( 'every_5_minutes', $definition['interval'] );
		$this->assertSame( 'fatal_rate_alarm_enabled', $definition['enabled_setting'] );
		$this->assertTrue( $definition['default_enabled'] );
	}

	/**
	 * The main site owns the alarm; a subsite never does.
	 */
	public function test_only_the_main_site_owns_the_alarm(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$this->assertTrue( is_main_site() );
		$this->assertTrue( extrachill_analytics_fatal_alarm_owned_by_current_site() );

		$subsite = self::factory()->blog->create();
		switch_to_blog( $subsite );
		try {
			$this->assertFalse( is_main_site() );
			$this->assertFalse( extrachill_analytics_fatal_alarm_owned_by_current_site() );
		} finally {
			restore_current_blog();
		}

		$this->assertTrue( extrachill_analytics_fatal_alarm_owned_by_current_site() );
	}
}
