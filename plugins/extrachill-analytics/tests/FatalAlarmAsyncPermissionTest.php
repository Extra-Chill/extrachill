<?php
/**
 * Dispatch permission for the fatal alarm outside cron and WP-CLI (#874).
 *
 * Action Scheduler's async runner executes the alarm's system task over
 * admin-ajax, where wp_doing_cron() is false and WP_CLI is undefined. The
 * grant must hold while the alarm task is evaluating, and only then.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-fake-system-task.php';
require_once dirname( __DIR__ ) . '/inc/core/class-fatal-rate-alarm-task.php';

use ExtraChill\Analytics\FatalRateAlarmTask;

/**
 * Fatal alarm dispatch permission in the async-runner context.
 */
final class FatalAlarmAsyncPermissionTest extends Extrachill_Analytics_TestCase {

	/**
	 * Dispatch input carrying the alarm's metadata source marker.
	 *
	 * @param string $source Metadata source.
	 * @return array
	 */
	private function input( string $source = EXTRACHILL_ANALYTICS_FATAL_ALARM_DISPATCH_SOURCE ): array {
		return array( 'metadata' => array( 'source' => $source ) );
	}

	/**
	 * Set the task's evaluation depth, as executeTask() does around evaluation.
	 *
	 * @param int $depth Depth.
	 */
	private function set_depth( int $depth ): void {
		$property = new ReflectionProperty( FatalRateAlarmTask::class, 'evaluation_depth' );
		$property->setAccessible( true );
		$property->setValue( null, $depth );
	}

	/**
	 * Reset the depth after each test.
	 */
	public function tear_down(): void {
		$this->set_depth( 0 );
		unset( $_REQUEST['action'] );
		parent::tear_down();
	}

	/**
	 * Outside cron and CLI, the grant follows the alarm task's evaluation.
	 */
	public function test_grant_holds_only_while_alarm_task_evaluates(): void {
		$this->assertFalse( wp_doing_cron() );
		$this->assertFalse( defined( 'WP_CLI' ) && WP_CLI );
		wp_set_current_user( 0 );

		// The async runner's action name alone (forgeable by any request) grants nothing.
		$_REQUEST['action'] = 'as_async_request_queue_runner';
		$this->assertFalse( extrachill_analytics_grant_fatal_alarm_dispatch_permission( false, $this->input() ) );

		$this->set_depth( 1 );
		$this->assertTrue( FatalRateAlarmTask::isEvaluating() );
		$this->assertTrue( extrachill_analytics_grant_fatal_alarm_dispatch_permission( false, $this->input() ) );
		$this->assertFalse( extrachill_analytics_grant_fatal_alarm_dispatch_permission( false, array() ), 'Unmarked dispatches stay gated.' );
		$this->assertFalse( extrachill_analytics_grant_fatal_alarm_dispatch_permission( false, $this->input( 'unrelated-task' ) ), 'Other sources stay gated.' );

		$this->set_depth( 0 );
		$this->assertFalse( extrachill_analytics_grant_fatal_alarm_dispatch_permission( false, $this->input() ) );
	}

	/**
	 * Running the task leaves no grant behind for the next job in the request.
	 */
	public function test_execute_task_restores_evaluation_state(): void {
		// The evaluator looks up agents/dispatch-message, which is not
		// registered in this plugin's test environment.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$task = new FatalRateAlarmTask();
		$task->executeTask( 1, array( 'dry_run' => true ) );

		$this->assertIsArray( $task->completed );
		$this->assertFalse( FatalRateAlarmTask::isEvaluating() );
		$this->assertFalse( extrachill_analytics_grant_fatal_alarm_dispatch_permission( false, $this->input() ) );
	}

	/**
	 * An existing allow decision is never downgraded.
	 */
	public function test_existing_permission_decision_is_preserved(): void {
		$this->assertTrue( extrachill_analytics_grant_fatal_alarm_dispatch_permission( true, array() ) );
	}
}
