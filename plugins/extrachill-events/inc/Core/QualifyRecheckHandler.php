<?php
/**
 * Qualify Recheck Handler — Action Scheduler callback for paused flows.
 *
 * Every flow paused by qualify v2 gets exactly one `dme/qualify_recheck`
 * action queued at pause time with a per-verdict cadence. When the action
 * fires, this handler:
 *
 *  - Calls `extrachill/qualify-venue` against the paused flow's source_url.
 *  - On QUALIFIED_STRUCTURED → auto-resumes the flow + queues an immediate
 *    run.
 *  - On a different non-qualifying verdict → updates paused_reason +
 *    reschedules with the new verdict's cadence.
 *  - On 6+ consecutive failed rechecks → flags the flow as stale (the
 *    digest surfaces it as "manual review recommended") and stops
 *    rescheduling.
 *  - On permanent verdicts (RESERVATION_ONLY / COVERED_ELSEWHERE) → just
 *    updates paused_reason and stops (no further rechecks).
 *
 * Auto-resume is safe by design: only a positive QUALIFIED_STRUCTURED
 * outcome resumes the flow. There is no force-resume path.
 *
 * @package ExtraChillEvents\Core
 * @since   0.21.0
 */

namespace ExtraChillEvents\Core;

use ExtraChillEvents\Cli\FlowOps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QualifyRecheckHandler {

	/**
	 * Maximum consecutive failed rechecks before a flow is flagged stale.
	 *
	 * Filterable via `dme_qualify_recheck_max_failures`.
	 */
	public const DEFAULT_MAX_FAILURES = 6;

	/**
	 * Action Scheduler hook.
	 */
	public const HOOK = 'dme/qualify_recheck';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'dme_qualify';

	/**
	 * Register the Action Scheduler hook.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'handle' ), 10, 1 );
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param array $args { flow_id, url, verdict, consecutive_failures }
	 */
	public static function handle( $args ): void {
		// Action Scheduler hands us the first positional argument from the
		// args array we registered — that's our associative payload.
		if ( ! is_array( $args ) ) {
			return;
		}

		$flow_id              = (int) ( $args['flow_id'] ?? 0 );
		$url                  = (string) ( $args['url'] ?? '' );
		$consecutive_failures = (int) ( $args['consecutive_failures'] ?? 0 );

		if ( $flow_id <= 0 || '' === $url ) {
			return;
		}

		// Sanity: the flow must still exist and still be paused. A manual
		// unpause cancels the recheck implicitly — we just no-op.
		$flow = FlowOps::fetch_flow_row( $flow_id );
		if ( ! $flow ) {
			return;
		}
		$current_interval = (string) ( $flow['scheduling_config']['interval'] ?? '' );
		if ( 'manual' !== $current_interval ) {
			do_action(
				'datamachine_log',
				'info',
				sprintf( 'QualifyRecheck: flow %d already unpaused — no-op', $flow_id ),
				array(
					'flow_id'  => $flow_id,
					'interval' => $current_interval,
				)
			);
			return;
		}

		// Run qualify.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return;
		}
		$ability = wp_get_ability( 'extrachill/qualify-venue' );
		if ( ! $ability ) {
			return;
		}

		$result = $ability->execute( array( 'url' => $url ) );
		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'warning',
				sprintf( 'QualifyRecheck: qualify ability errored for flow %d: %s', $flow_id, $result->get_error_message() ),
				array(
					'flow_id' => $flow_id,
					'url'     => $url,
					'error'   => $result->get_error_message(),
				)
			);
			// Treat ability error as a failed recheck and reschedule on the
			// original verdict's cadence (or escalate to stale on threshold).
			self::reschedule_or_flag( $flow_id, $url, (string) ( $args['verdict'] ?? '' ), $consecutive_failures + 1 );
			return;
		}

		$new_verdict = (string) ( $result['verdict'] ?? '' );

		if ( QualifyVerdict::QUALIFIED_STRUCTURED === $new_verdict ) {
			FlowOps::resume_flow_from_qualified( $flow_id, is_array( $result ) ? $result : array() );
			return;
		}

		$next_interval = QualifyVerdict::recheck_interval_for( $new_verdict );
		if ( null === $next_interval ) {
			// Permanent disqualification — update paused_reason and stop.
			FlowOps::update_paused_reason( $flow_id, $new_verdict );
			do_action(
				'datamachine_log',
				'info',
				sprintf( 'QualifyRecheck: flow %d permanently disqualified (verdict=%s)', $flow_id, $new_verdict ),
				array(
					'flow_id' => $flow_id,
					'verdict' => $new_verdict,
				)
			);
			return;
		}

		self::reschedule_or_flag( $flow_id, $url, $new_verdict, $consecutive_failures + 1 );
	}

	/**
	 * Reschedule the next recheck OR flag the flow stale once consecutive
	 * failures cross the threshold.
	 *
	 * @param int    $flow_id              Flow ID.
	 * @param string $url                  Source URL.
	 * @param string $verdict              Verdict to reschedule against.
	 * @param int    $consecutive_failures Failure count including this run.
	 */
	private static function reschedule_or_flag( int $flow_id, string $url, string $verdict, int $consecutive_failures ): void {
		/**
		 * Filter the consecutive-failure threshold before a paused flow
		 * is flagged stale and surfaced in the weekly digest.
		 *
		 * @param int    $max_failures Default 6.
		 * @param int    $flow_id      Flow ID being rechecked.
		 * @param string $verdict      Current verdict.
		 */
		$max_failures = (int) apply_filters(
			'dme_qualify_recheck_max_failures',
			self::DEFAULT_MAX_FAILURES,
			$flow_id,
			$verdict
		);

		if ( $consecutive_failures >= $max_failures ) {
			FlowOps::flag_stale_paused( $flow_id, $verdict, $consecutive_failures );
			return;
		}

		$interval = QualifyVerdict::recheck_interval_for( $verdict );
		if ( null === $interval ) {
			FlowOps::update_paused_reason( $flow_id, $verdict );
			return;
		}

		// Reflect the new (possibly different) verdict on the flow before
		// queuing the next attempt.
		FlowOps::update_paused_reason( $flow_id, $verdict );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$ts        = time() + (int) $interval;
		$action_id = as_schedule_single_action(
			$ts,
			self::HOOK,
			array(
				array(
					'flow_id'              => $flow_id,
					'url'                  => $url,
					'verdict'              => $verdict,
					'consecutive_failures' => $consecutive_failures,
				),
			),
			self::GROUP
		);

		if ( $action_id ) {
			FlowOps::set_recheck_metadata( $flow_id, (int) $action_id, $ts );
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'QualifyRecheck: flow %d rescheduled (verdict=%s, consecutive_failures=%d, next_run=%s)',
				$flow_id,
				$verdict,
				$consecutive_failures,
				gmdate( 'Y-m-d H:i:s', $ts )
			),
			array(
				'flow_id'              => $flow_id,
				'verdict'              => $verdict,
				'consecutive_failures' => $consecutive_failures,
				'next_run'             => $ts,
			)
		);
	}
}
