<?php
/** Recurring schedule declarations: fixed wall-clock slots are cron expressions. */

namespace ExtraChillEvents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/digest-stubs.php';
require_once __DIR__ . '/Stubs/system-task-base-stub.php';
if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_DIR' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 3 ) . '/' );
}
require_once dirname( __DIR__, 3 ) . '/inc/Providers/IngestionProvider.php';

class RecurringSchedulesTest extends TestCase {
	/**
	 * Data Machine's routine adapter ignores first_run_* (Extra-Chill/data-machine#3618):
	 * a named interval fires at a staggered offset from whenever it was enabled.
	 * Schedules promising a weekday/time must declare that slot as a cron expression.
	 */
	public function test_schedules_declare_their_promised_utc_slot_as_cron(): void {
		$schedules = \ExtraChillEvents\Providers\IngestionProvider::register_recurring_schedules( array() );

		$this->assertSame( '0 15 * * 4', $schedules['extrachill_local_scene_digest']['interval'], 'Local Scene digest: Thursdays 15:00 UTC.' );
		$this->assertSame( '0 9 * * 1', $schedules['extrachill_qualify_digest']['interval'], 'Qualify digest: Mondays 09:00 UTC.' );
		$this->assertSame( '0 9 * * *', $schedules['extrachill_pending_event_submissions']['interval'], 'Pending submissions: daily 09:00 UTC.' );

		foreach ( $schedules as $id => $schedule ) {
			$this->assertArrayNotHasKey( 'first_run_callback', $schedule, $id . ' must not rely on an ignored anchor.' );
			$this->assertArrayNotHasKey( 'first_run_arg', $schedule, $id . ' must not rely on an ignored anchor.' );
			$this->assertCount( 5, preg_split( '/\s+/', trim( $schedule['interval'] ) ), $id . ' interval is a 5-field cron expression.' );
		}
		$this->assertFalse( $schedules['extrachill_local_scene_digest']['default_enabled'], 'Subscriber email stays opt-in to enable.' );
	}
}
