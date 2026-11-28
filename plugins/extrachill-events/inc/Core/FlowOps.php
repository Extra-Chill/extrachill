<?php
/**
 * Qualify v2 — static flow operations.
 *
 * Called by CLI commands (via the FlowHelpers trait) and by the recheck
 * handler at runtime. Keeping the read/write logic in one place keeps
 * idempotency promises predictable: any caller that touches a flow's
 * scheduling goes through the same code path.
 *
 * All Data Machine access goes through {@see DataMachineFlowGateway}:
 * interval changes use `datamachine/update-flow` (which also syncs or
 * removes the flow's schedule), qualify bookkeeping keys use
 * `Flows::update_flow_scheduling_metadata()`, source URL repairs use
 * `datamachine/update-flow-step`, and immediate runs use
 * `datamachine/run-flow`.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/DataMachineFlowGateway.php';

/**
 * Static flow operations shared by CLI commands and the recheck handler.
 *
 * @internal
 */
class FlowOps {

	/**
	 * Qualify bookkeeping keys cleared when a flow resumes.
	 */
	private const PAUSE_KEYS = array( 'prior_interval', 'paused_reason', 'paused_at', 'recheck_action_id', 'recheck_scheduled_for', 'stale_flag' );

	/**
	 * Gateway override (tests only).
	 *
	 * @var DataMachineFlowGateway|null
	 */
	private static $gateway = null;

	/**
	 * Swap the Data Machine gateway. Pass null to restore the default.
	 *
	 * @param DataMachineFlowGateway|null $gateway Gateway instance.
	 */
	public static function set_gateway( ?DataMachineFlowGateway $gateway ): void {
		self::$gateway = $gateway;
	}

	/**
	 * Active Data Machine gateway.
	 *
	 * @return DataMachineFlowGateway
	 */
	private static function gateway(): DataMachineFlowGateway {
		if ( null === self::$gateway ) {
			self::$gateway = new DataMachineFlowGateway();
		}
		return self::$gateway;
	}

	/**
	 * Read a flow.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array|null Flow with decoded flow_config and scheduling_config.
	 */
	public static function fetch_flow_row( int $flow_id ): ?array {
		return self::gateway()->get_flow( $flow_id );
	}

	/**
	 * Apply a scheduling change: set/remove qualify keys and, when the
	 * interval changes (or $sync is true), re-sync the schedule through
	 * Data Machine.
	 *
	 * @param int         $flow_id  Flow ID.
	 * @param array       $current  Current scheduling config.
	 * @param array       $set      Keys to set.
	 * @param array       $remove   Keys to remove.
	 * @param string|null $interval New interval, or null to keep it.
	 * @return bool True on success.
	 */
	private static function apply_scheduling( int $flow_id, array $current, array $set, array $remove, ?string $interval = null ): bool {
		if ( null !== $interval ) {
			$desired = array_diff_key( array_merge( $current, $set ), array_flip( $remove ) );

			$desired['interval'] = $interval;
			if ( ! self::gateway()->update_scheduling( $flow_id, $desired ) ) {
				return false;
			}
		}

		// Data Machine skips an unchanged schedule, so the qualify keys are
		// always written as metadata too; this is a no-op when already set.
		return self::gateway()->patch_scheduling_metadata( $flow_id, $set, $remove );
	}

	/**
	 * Pause a flow by setting scheduling_config.interval = "manual",
	 * stashing the verdict in paused_reason, and (optionally) queuing the
	 * next recheck Action Scheduler job.
	 *
	 * The manual interval is applied through Data Machine, which also
	 * removes the flow's scheduled runs.
	 *
	 * @param int    $flow_id    Flow ID.
	 * @param string $verdict    Verdict that triggered the pause.
	 * @param string $source_url Source URL — required for recheck scheduling.
	 * @return bool True on success.
	 */
	public static function pause_flow_by_verdict( int $flow_id, string $verdict, string $source_url = '' ): bool {
		$row = self::fetch_flow_row( $flow_id );
		if ( ! $row ) {
			return false;
		}

		$scheduling = $row['scheduling_config'];
		$set        = array(
			'paused_reason' => $verdict,
			'paused_at'     => current_time( 'mysql' ),
		);
		$remove     = array( 'recheck_action_id', 'recheck_scheduled_for', 'stale_flag' );

		$prior_interval = (string) ( $scheduling['interval'] ?? '' );
		if ( 'manual' !== $prior_interval ) {
			$set['prior_interval'] = $prior_interval;
		}

		// Cancel any in-flight recheck before scheduling a new one so a
		// repeat pause never leaves a stale action behind.
		$prior_action_id = (int) ( $scheduling['recheck_action_id'] ?? 0 );
		// Cancel by ID: unscheduling by hook alone would cancel whichever
		// flow's recheck is next in the group, not this flow's.
		if ( $prior_action_id > 0 && class_exists( '\ActionScheduler' ) ) {
			try {
				\ActionScheduler::store()->cancel_action( $prior_action_id );
			} catch ( \Throwable $e ) {
				unset( $e ); // Already ran or was removed; nothing to cancel.
			}
		}

		// Schedule the next recheck when enabled and the verdict has a
		// non-null cadence. Source URL is required — without it the
		// rechecker has nothing to qualify against.
		$recheck_enabled = (bool) get_site_option( 'dme_qualify_recheck_enabled', true );
		$interval        = QualifyVerdict::recheck_interval_for( $verdict );
		if ( $recheck_enabled && null !== $interval && '' !== $source_url && function_exists( 'as_schedule_single_action' ) ) {
			$ts        = time() + (int) $interval;
			$action_id = as_schedule_single_action(
				$ts,
				'dme/qualify_recheck',
				array(
					array(
						'flow_id'              => $flow_id,
						'url'                  => $source_url,
						'verdict'              => $verdict,
						'consecutive_failures' => 0,
					),
				),
				'dme_qualify'
			);
			if ( $action_id ) {
				$set['recheck_action_id']     = (int) $action_id;
				$set['recheck_scheduled_for'] = gmdate( 'Y-m-d H:i:s', $ts );
				$remove                       = array( 'stale_flag' );
			}
		}

		// Always sync: re-pausing a manual flow still clears stale schedules.
		return self::apply_scheduling( $flow_id, $scheduling, $set, $remove, 'manual' );
	}

	/**
	 * Update only the paused_reason (used when a recheck returns a
	 * different non-qualifying verdict — e.g. unreachable → bot_blocked).
	 *
	 * @param int    $flow_id Flow ID.
	 * @param string $verdict New verdict.
	 * @return bool True on success.
	 */
	public static function update_paused_reason( int $flow_id, string $verdict ): bool {
		return self::gateway()->patch_scheduling_metadata(
			$flow_id,
			array(
				'paused_reason' => $verdict,
				'paused_at'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Set the recheck-rescheduled metadata on a paused flow.
	 *
	 * Called by the handler after queuing the next recheck so the digest
	 * can show when the next attempt will fire.
	 *
	 * @param int $flow_id     Flow ID.
	 * @param int $action_id   Action Scheduler action ID.
	 * @param int $next_run_ts Unix timestamp.
	 * @return bool True on success.
	 */
	public static function set_recheck_metadata( int $flow_id, int $action_id, int $next_run_ts ): bool {
		return self::gateway()->patch_scheduling_metadata(
			$flow_id,
			array(
				'recheck_action_id'     => $action_id,
				'recheck_scheduled_for' => gmdate( 'Y-m-d H:i:s', $next_run_ts ),
			)
		);
	}

	/**
	 * Flag a paused flow as stale after N consecutive failed rechecks.
	 *
	 * The digest reads `stale_flag` from scheduling_config and surfaces
	 * these flows under a "manual review recommended" section.
	 *
	 * @param int    $flow_id              Flow ID.
	 * @param string $verdict              The verdict of the most recent failed recheck.
	 * @param int    $consecutive_failures How many rechecks failed in a row.
	 * @return bool True on success.
	 */
	public static function flag_stale_paused( int $flow_id, string $verdict, int $consecutive_failures ): bool {
		// Cancel any further recheck — operator review required.
		$ok = self::gateway()->patch_scheduling_metadata(
			$flow_id,
			array(
				'paused_reason' => $verdict,
				'stale_flag'    => array(
					'flagged_at'           => current_time( 'mysql' ),
					'consecutive_failures' => $consecutive_failures,
					'last_verdict'         => $verdict,
				),
			),
			array( 'recheck_action_id', 'recheck_scheduled_for' )
		);

		do_action(
			'datamachine_log',
			'warning',
			sprintf( 'QualifyRecheck: flow %d flagged stale after %d consecutive failures (verdict=%s)', $flow_id, $consecutive_failures, $verdict ),
			array(
				'flow_id'              => $flow_id,
				'verdict'              => $verdict,
				'consecutive_failures' => $consecutive_failures,
			)
		);

		return $ok;
	}

	/**
	 * Resume a paused flow because qualify returned QUALIFIED_STRUCTURED.
	 *
	 * Steps:
	 *  - Restore scheduling_config.interval to its pre-pause value
	 *    (from scheduling_config.prior_interval; defaults to 'daily').
	 *  - Clear paused_reason / paused_at / prior_interval / recheck_*
	 *    fields and any stale_flag.
	 *  - If qualify discovered an `events_url` that differs from the
	 *    current flow source_url AND is on the same host, patch every
	 *    universal_web_scraper step whose source_url matches the OLD
	 *    value. Cross-host changes are skipped (operator review required
	 *    for venue rebrands).
	 *  - Start an immediate run via `datamachine/run-flow` so the
	 *    operator sees a real run on the next tick.
	 *
	 * Safe by design: only callers with a positive qualify result should
	 * invoke this. There is intentionally no force-resume path here.
	 *
	 * @param int   $flow_id Flow ID.
	 * @param array $result  Qualify result for logging context.
	 *                       Optional `events_url` triggers source_url
	 *                       propagation (see issue #81).
	 * @return bool True on success.
	 */
	public static function resume_flow_from_qualified( int $flow_id, array $result ): bool {
		$row = self::fetch_flow_row( $flow_id );
		if ( ! $row ) {
			return false;
		}

		$scheduling  = $row['scheduling_config'];
		$flow_config = $row['flow_config'];

		$prior_interval = (string) ( $scheduling['prior_interval'] ?? '' );
		if ( '' === $prior_interval || 'manual' === $prior_interval ) {
			$prior_interval = 'daily';
		}

		// Decide whether to propagate a discovered events_url onto the
		// flow's source_url. Same-host only — cross-host changes are
		// operator review by design.
		$events_url        = isset( $result['events_url'] ) ? (string) $result['events_url'] : '';
		$current_source    = self::extract_source_url_from_flow_config( $flow_config );
		$source_url_change = null;

		if ( '' !== $events_url && '' !== $current_source && $events_url !== $current_source ) {
			if ( self::same_host( $events_url, $current_source ) ) {
				$changed = self::patch_source_url( $flow_config, $current_source, $events_url );
				if ( $changed > 0 ) {
					$source_url_change = array(
						'old'           => $current_source,
						'new'           => $events_url,
						'steps_patched' => $changed,
					);
				}
			} else {
				do_action(
					'datamachine_log',
					'warning',
					sprintf(
						'QualifyRecheck: flow %d events_url host differs from current source_url host — skipping propagation (old=%s, new=%s)',
						$flow_id,
						$current_source,
						$events_url
					),
					array(
						'flow_id'        => $flow_id,
						'action'         => 'flow_source_url_cross_host_skip',
						'old_source_url' => $current_source,
						'new_events_url' => $events_url,
					)
				);
			}
		}

		$ok = self::apply_scheduling(
			$flow_id,
			$scheduling,
			array(
				'resumed_at'         => current_time( 'mysql' ),
				'resumed_by_qualify' => true,
			),
			self::PAUSE_KEYS,
			$prior_interval
		);

		// Start a run now so the operator sees a real result on the next
		// tick rather than waiting for the next scheduled fire.
		if ( $ok ) {
			self::gateway()->run_flow( $flow_id );
		}

		if ( null !== $source_url_change ) {
			do_action(
				'datamachine_log',
				'info',
				sprintf(
					'QualifyRecheck: flow %d source_url updated by qualify (%s → %s, %d step%s patched)',
					$flow_id,
					$source_url_change['old'],
					$source_url_change['new'],
					$source_url_change['steps_patched'],
					1 === $source_url_change['steps_patched'] ? '' : 's'
				),
				array(
					'flow_id'        => $flow_id,
					'action'         => 'flow_source_url_updated_by_qualify',
					'old_source_url' => $source_url_change['old'],
					'new_source_url' => $source_url_change['new'],
					'steps_patched'  => $source_url_change['steps_patched'],
				)
			);
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'QualifyRecheck: auto-resumed flow %d (verdict=qualified_structured, restored interval=%s)', $flow_id, $prior_interval ),
			array(
				'flow_id'     => $flow_id,
				'interval'    => $prior_interval,
				'event_count' => (int) ( $result['event_count'] ?? 0 ),
			)
		);

		return $ok;
	}

	/**
	 * Apply an explicitly confirmed, same-host source URL repair only.
	 *
	 * Unlike resume_flow_from_qualified(), this does not alter scheduling or
	 * start a run. The caller must separately obtain operator confirmation.
	 *
	 * @param int    $flow_id      Flow ID.
	 * @param string $current_url  Source URL observed during diagnosis.
	 * @param string $proposed_url Same-host replacement URL.
	 * @return bool True when at least one matching scraper step was updated.
	 */
	public static function repair_flow_source_url( int $flow_id, string $current_url, string $proposed_url ): bool {
		if ( '' === $current_url || '' === $proposed_url || ! self::same_host( $current_url, $proposed_url ) ) {
			return false;
		}

		$row = self::fetch_flow_row( $flow_id );
		if ( ! $row || self::extract_source_url_from_flow_config( $row['flow_config'] ) !== $current_url ) {
			return false;
		}

		return self::patch_source_url( $row['flow_config'], $current_url, $proposed_url ) > 0;
	}

	/**
	 * Extract the universal_web_scraper source_url from a decoded
	 * flow_config array. Mirrors the parsing in
	 * \ExtraChillEvents\Core\FlowHelpers::extract_web_scraper_meta() but kept
	 * local here so runtime code does not depend on the trait.
	 *
	 * Returns the first non-empty source_url found on an event_import
	 * step whose handler is universal_web_scraper. Handles both the
	 * newer handler_slug/handler_config shape and the older
	 * handler_slugs[]/handler_configs{} shape.
	 *
	 * @param array $flow_config Decoded flow_config.
	 * @return string Source URL or empty string when not found.
	 */
	private static function extract_source_url_from_flow_config( array $flow_config ): string {
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			if ( ( $step['step_type'] ?? '' ) !== 'event_import' ) {
				continue;
			}
			if ( isset( $step['handler_slug'] )
				&& 'universal_web_scraper' === $step['handler_slug']
				&& isset( $step['handler_config']['source_url'] ) ) {
				$url = (string) $step['handler_config']['source_url'];
				if ( '' !== $url ) {
					return $url;
				}
			}
			if ( isset( $step['handler_configs']['universal_web_scraper']['source_url'] ) ) {
				$url = (string) $step['handler_configs']['universal_web_scraper']['source_url'];
				if ( '' !== $url ) {
					return $url;
				}
			}
		}
		return '';
	}

	/**
	 * Point every universal_web_scraper step whose source_url matches
	 * $old_url at $new_url, through Data Machine.
	 *
	 * Only patches steps whose CURRENT source_url equals $old_url —
	 * operator-set or already-divergent values are left untouched.
	 *
	 * @param array  $flow_config Decoded flow_config.
	 * @param string $old_url     Pre-change source URL to match.
	 * @param string $new_url     Replacement URL.
	 * @return int Number of steps updated.
	 */
	private static function patch_source_url( array $flow_config, string $old_url, string $new_url ): int {
		$changed = 0;
		foreach ( $flow_config as $step_key => $step ) {
			if ( ! is_array( $step ) || ( $step['step_type'] ?? '' ) !== 'event_import' ) {
				continue;
			}

			// Newer shape: handler_slug + handler_config. Older shape:
			// handler_configs{slug: config}.
			$matches = ( 'universal_web_scraper' === ( $step['handler_slug'] ?? '' )
					&& (string) ( $step['handler_config']['source_url'] ?? '' ) === $old_url )
				|| ( isset( $step['handler_configs']['universal_web_scraper']['source_url'] )
					&& (string) $step['handler_configs']['universal_web_scraper']['source_url'] === $old_url );
			if ( ! $matches ) {
				continue;
			}

			$flow_step_id = (string) ( $step['flow_step_id'] ?? $step_key );
			if ( self::gateway()->update_step_source_url( $flow_step_id, $new_url ) ) {
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * True when both URLs share the same host (case-insensitive).
	 * Missing/unparseable hosts return false — we err on the side of
	 * skipping propagation rather than silently rewriting.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	private static function same_host( string $a, string $b ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Defensive fallback only; wp_parse_url() is preferred and used whenever available.
		$ha = function_exists( 'wp_parse_url' ) ? wp_parse_url( $a, PHP_URL_HOST ) : parse_url( $a, PHP_URL_HOST );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Defensive fallback only; wp_parse_url() is preferred and used whenever available.
		$hb = function_exists( 'wp_parse_url' ) ? wp_parse_url( $b, PHP_URL_HOST ) : parse_url( $b, PHP_URL_HOST );
		if ( ! is_string( $ha ) || ! is_string( $hb ) || '' === $ha || '' === $hb ) {
			return false;
		}
		return strtolower( $ha ) === strtolower( $hb );
	}
}
