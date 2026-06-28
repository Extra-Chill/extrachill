<?php
/**
 * Fatal-Rate Alarm System Task.
 *
 * Wraps the near-real-time PHP fatal-rate evaluator
 * (`extrachill_analytics_evaluate_fatal_alarm()`) as a Data Machine system task
 * so it is scheduled, run, and observed through the standard DM orchestration
 * surface instead of a hand-rolled `cron_schedules` + `wp_schedule_event`
 * pair:
 *
 *   wp datamachine system run fatal_rate_alarm
 *   wp datamachine jobs list --task=fatal_rate_alarm
 *
 * This is a pure, agent-less operational sensor: it reads the live debug.log
 * tail, computes per-signature fatal rates, and beacons an alert when a new or
 * spiking fatal appears. It performs no AI work and mutates no WordPress
 * content, so it opts out of the SystemTask agent-context gate.
 *
 * The 5-minute cadence (near-real-time detection is the entire point of
 * issue #48) is declared as a recurring schedule in `php-fatal-alarm.php` via
 * the `datamachine_recurring_schedules` filter using DM's built-in
 * `every_5_minutes` interval. This class only owns the execution contract; the
 * schedule binding owns the cadence.
 *
 * @package ExtraChill\Analytics
 * @since 0.24.0
 * @see https://github.com/Extra-Chill/extrachill-analytics/issues/98
 */

namespace ExtraChill\Analytics;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

defined( 'ABSPATH' ) || exit;

class FatalRateAlarmTask extends SystemTask {

	/**
	 * Task type identifier (registered via the `datamachine_tasks` filter).
	 */
	public const TASK_TYPE = 'fatal_rate_alarm';

	/**
	 * PluginSettings key that gates both manual and scheduled runs.
	 *
	 * Default-enabled: this is a safety alarm, not a destructive maintenance
	 * task, so it should run out of the box. Operators can disable it via
	 * `wp datamachine settings set fatal_rate_alarm_enabled false`.
	 */
	public const SETTING_KEY = 'fatal_rate_alarm_enabled';

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	/**
	 * Pure operational sensor — runs without agent ownership context.
	 *
	 * The task reads debug.log and may dispatch an alert via the
	 * `agents/dispatch-message` / `datamachine/post-message-discord`
	 * abilities, but it never acts AS an agent or invokes an agent-scoped
	 * mutation. It is registered as an agent-less recurring schedule, so it
	 * must opt out of the SystemTask agent-context gate or
	 * TaskScheduler::schedule() rejects it before it runs.
	 *
	 * @return bool
	 */
	public function requiresAgentContext(): bool {
		return false;
	}

	/**
	 * Task metadata for the Data Machine system surface.
	 *
	 * `setting_key` wires the task into DM's settings plumbing: the React
	 * admin UI renders an enable/disable toggle, the TaskRegistry resolves
	 * live state from `PluginSettings::get()`, and the recurring schedule
	 * registration reads the same key to schedule/unschedule its tick.
	 *
	 * The `trigger` / `trigger_type` fields are intentionally omitted — DM
	 * core resolves them from the bound schedule in RecurringScheduleRegistry.
	 *
	 * @return array
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'PHP Fatal-Rate Alarm',
			'description'     => 'Reads the live debug.log tail every 5 minutes and beacons an alert when a new or spiking PHP fatal signature appears. Near-real-time fatal detection (issue #48).',
			'setting_key'     => self::SETTING_KEY,
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	/**
	 * Execute one alarm evaluation.
	 *
	 * Delegates to the existing procedural evaluator, which reads the live
	 * debug.log tail, aggregates fatal-class signatures, decides which (if
	 * any) should alarm, and beacons them. The full evaluation result is
	 * recorded into the Jobs table so each tick has a first-class audit
	 * record — which is exactly what was missing when a worktree-origin
	 * false-positive paged the bot with no run history (issue #98).
	 *
	 * Params (optional):
	 *   - `dry_run` (bool) — compute candidates without beaconing or mutating
	 *                        dedupe state. Default: false.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function executeTask( int $jobId, array $params ): void {
		// Gate on the same PluginSetting the React UI toggles and the
		// recurring schedule reads. Scheduled runs already wouldn't fire when
		// the setting is off, but manual runs via `wp datamachine system run
		// fatal_rate_alarm` bypass the schedule layer, so self-police here too.
		$enabled = true;
		if ( class_exists( '\\DataMachine\\Core\\PluginSettings' ) ) {
			$enabled = (bool) \DataMachine\Core\PluginSettings::get( self::SETTING_KEY, true );
		}
		if ( ! $enabled ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => sprintf( 'Fatal-rate alarm disabled (PluginSettings: %s=false).', self::SETTING_KEY ),
				)
			);
			return;
		}

		if ( ! function_exists( 'extrachill_analytics_evaluate_fatal_alarm' ) ) {
			$this->failJob( $jobId, 'Fatal-rate evaluator unavailable.' );
			return;
		}

		$dry_run = ! empty( $params['dry_run'] );

		// The evaluator always returns a fully-populated result array
		// (window_seconds, threshold, candidates, alarms, beacon), so the
		// fields below are read directly without defensive ?? / isset.
		$result = extrachill_analytics_evaluate_fatal_alarm( $dry_run );

		$this->completeJob(
			$jobId,
			array(
				'dry_run'         => $dry_run,
				'window_seconds'  => $result['window_seconds'],
				'threshold'       => $result['threshold'],
				'candidate_count' => count( $result['candidates'] ),
				'alarm_count'     => count( $result['alarms'] ),
				'alarms'          => $result['alarms'],
				'beacon'          => $result['beacon'],
			)
		);
	}
}
