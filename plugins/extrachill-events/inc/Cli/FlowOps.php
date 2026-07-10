<?php
/**
 * Qualify v2 — static flow operations.
 *
 * Called by both CLI commands (via the FlowHelpers trait) and the recheck
 * handler (which has no `$this` context). Keeping the read/write logic in
 * one place keeps idempotency promises predictable: any caller that
 * touches scheduling_config goes through the same code path.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\QualifyVerdict;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static flow operations called by both CLI commands (via the FlowHelpers
 * trait) and the recheck handler (which has no `$this` context).
 *
 * Keeping the read/write logic in one place keeps idempotency promises
 * predictable: any caller that touches scheduling_config goes through the
 * same code path.
 *
 * @internal
 */
class FlowOps {

	/**
	 * Read a flow row.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array|null Associative row with decoded scheduling_config.
	 */
	public static function fetch_flow_row( int $flow_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT flow_id, flow_name, scheduling_config FROM {$table} WHERE flow_id = %d", $flow_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->prefix.
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$scheduling               = json_decode( (string) ( $row['scheduling_config'] ?? '' ), true );
		$row['scheduling_config'] = is_array( $scheduling ) ? $scheduling : array();
		return $row;
	}

	/**
	 * Pause a flow by setting scheduling_config.interval = "manual",
	 * stashing the verdict in paused_reason, and (optionally) queuing the
	 * next recheck Action Scheduler job.
	 *
	 * @param int    $flow_id    Flow ID.
	 * @param string $verdict    Verdict that triggered the pause.
	 * @param string $source_url Source URL — required for recheck scheduling.
	 * @return bool True on success.
	 */
	public static function pause_flow_by_verdict( int $flow_id, string $verdict, string $source_url = '' ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		$row = self::fetch_flow_row( $flow_id );
		if ( ! $row ) {
			return false;
		}

		$scheduling = is_array( $row['scheduling_config'] ?? null ) ? $row['scheduling_config'] : array();

		$prior_interval = (string) ( $scheduling['interval'] ?? '' );
		if ( 'manual' !== $prior_interval ) {
			$scheduling['prior_interval'] = $prior_interval;
		}
		$scheduling['interval']      = 'manual';
		$scheduling['paused_reason'] = $verdict;
		$scheduling['paused_at']     = current_time( 'mysql' );

		// Cancel any in-flight recheck before scheduling a new one so a
		// repeat pause never leaves a stale action behind.
		$prior_action_id = (int) ( $scheduling['recheck_action_id'] ?? 0 );
		if ( $prior_action_id > 0 && function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( 'dme/qualify_recheck', null, 'dme_qualify' );
		}
		unset( $scheduling['recheck_action_id'] );
		unset( $scheduling['recheck_scheduled_for'] );
		unset( $scheduling['stale_flag'] );

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
				$scheduling['recheck_action_id']     = (int) $action_id;
				$scheduling['recheck_scheduled_for'] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$table,
			array( 'scheduling_config' => wp_json_encode( $scheduling ) ),
			array( 'flow_id' => $flow_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Unschedule Action Scheduler hooks so paused flows do not fire.
		if ( $ok && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
		}

		return false !== $ok;
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
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		$row = self::fetch_flow_row( $flow_id );
		if ( ! $row ) {
			return false;
		}
		$scheduling                  = is_array( $row['scheduling_config'] ?? null ) ? $row['scheduling_config'] : array();
		$scheduling['paused_reason'] = $verdict;
		$scheduling['paused_at']     = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$table,
			array( 'scheduling_config' => wp_json_encode( $scheduling ) ),
			array( 'flow_id' => $flow_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $ok;
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
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		$row = self::fetch_flow_row( $flow_id );
		if ( ! $row ) {
			return false;
		}
		$scheduling                          = is_array( $row['scheduling_config'] ?? null ) ? $row['scheduling_config'] : array();
		$scheduling['recheck_action_id']     = $action_id;
		$scheduling['recheck_scheduled_for'] = gmdate( 'Y-m-d H:i:s', $next_run_ts );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$table,
			array( 'scheduling_config' => wp_json_encode( $scheduling ) ),
			array( 'flow_id' => $flow_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $ok;
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
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		$row = self::fetch_flow_row( $flow_id );
		if ( ! $row ) {
			return false;
		}
		$scheduling = is_array( $row['scheduling_config'] ?? null ) ? $row['scheduling_config'] : array();

		$scheduling['paused_reason'] = $verdict;
		$scheduling['stale_flag']    = array(
			'flagged_at'           => current_time( 'mysql' ),
			'consecutive_failures' => $consecutive_failures,
			'last_verdict'         => $verdict,
		);
		// Cancel any further recheck — operator review required.
		unset( $scheduling['recheck_action_id'] );
		unset( $scheduling['recheck_scheduled_for'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$table,
			array( 'scheduling_config' => wp_json_encode( $scheduling ) ),
			array( 'flow_id' => $flow_id ),
			array( '%s' ),
			array( '%d' )
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

		return false !== $ok;
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
	 *    current flow source_url AND is on the same host, walk
	 *    flow_config and patch every universal_web_scraper step whose
	 *    source_url matches the OLD value. Cross-host changes are
	 *    skipped (operator review required for venue rebrands).
	 *  - Queue an immediate run via datamachine_run_flow_now so the
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
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		// Re-read the row directly here because we also need flow_config
		// for the events_url propagation path. fetch_flow_row() returns
		// scheduling_config only, and we keep its narrow contract intact
		// so the recheck handler's other callers remain unchanged.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT flow_id, scheduling_config, flow_config FROM {$table} WHERE flow_id = %d", $flow_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->prefix.
			ARRAY_A
		);
		if ( ! $row ) {
			return false;
		}

		$scheduling = json_decode( (string) ( $row['scheduling_config'] ?? '' ), true );
		$scheduling = is_array( $scheduling ) ? $scheduling : array();

		$flow_config = json_decode( (string) ( $row['flow_config'] ?? '' ), true );
		$flow_config = is_array( $flow_config ) ? $flow_config : array();

		$prior_interval = (string) ( $scheduling['prior_interval'] ?? '' );
		if ( '' === $prior_interval || 'manual' === $prior_interval ) {
			$prior_interval = 'daily';
		}

		$scheduling['interval'] = $prior_interval;
		unset(
			$scheduling['prior_interval'],
			$scheduling['paused_reason'],
			$scheduling['paused_at'],
			$scheduling['recheck_action_id'],
			$scheduling['recheck_scheduled_for'],
			$scheduling['stale_flag']
		);
		$scheduling['resumed_at']         = current_time( 'mysql' );
		$scheduling['resumed_by_qualify'] = true;

		// Decide whether to propagate a discovered events_url onto the
		// flow's source_url. Same-host only — cross-host changes are
		// operator review by design.
		$update_data    = array( 'scheduling_config' => wp_json_encode( $scheduling ) );
		$update_formats = array( '%s' );

		$events_url        = isset( $result['events_url'] ) ? (string) $result['events_url'] : '';
		$current_source    = self::extract_source_url_from_flow_config( $flow_config );
		$source_url_change = null;

		if ( '' !== $events_url && '' !== $current_source && $events_url !== $current_source ) {
			if ( self::same_host( $events_url, $current_source ) ) {
				$patched = self::patch_source_url_in_flow_config( $flow_config, $current_source, $events_url );
				if ( $patched['changed'] > 0 ) {
					$flow_config                = $patched['config'];
					$update_data['flow_config'] = wp_json_encode( $flow_config );
					$update_formats[]           = '%s';
					$source_url_change          = array(
						'old'           => $current_source,
						'new'           => $events_url,
						'steps_patched' => $patched['changed'],
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$table,
			$update_data,
			array( 'flow_id' => $flow_id ),
			$update_formats,
			array( '%d' )
		);

		// Queue an immediate run so the operator sees a real result on the
		// next tick rather than waiting for the next scheduled fire.
		if ( $ok && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
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

		return false !== $ok;
	}

	/**
	 * Extract the universal_web_scraper source_url from a decoded
	 * flow_config array. Mirrors the parsing in FlowHelpers::extract_web_scraper_meta
	 * but kept local here so FlowOps does not depend on the trait.
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
	 * Walk flow_config and replace every universal_web_scraper
	 * source_url that matches $old with $new.
	 *
	 * Only patches steps whose CURRENT source_url equals $old —
	 * operator-set or already-divergent values are left untouched.
	 *
	 * @param array  $flow_config Decoded flow_config.
	 * @param string $old_url     Pre-change source URL to match.
	 * @param string $new_url     Replacement URL.
	 * @return array{config:array,changed:int}
	 */
	private static function patch_source_url_in_flow_config( array $flow_config, string $old_url, string $new_url ): array {
		$changed = 0;
		foreach ( $flow_config as $step_key => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			if ( ( $step['step_type'] ?? '' ) !== 'event_import' ) {
				continue;
			}

			// Newer shape: handler_slug + handler_config.
			if ( isset( $step['handler_slug'] )
				&& 'universal_web_scraper' === $step['handler_slug']
				&& isset( $step['handler_config']['source_url'] )
				&& (string) $step['handler_config']['source_url'] === $old_url ) {
				$flow_config[ $step_key ]['handler_config']['source_url'] = $new_url;
				++$changed;
			}

			// Older shape: handler_configs{slug: config}.
			if ( isset( $step['handler_configs']['universal_web_scraper']['source_url'] )
				&& (string) $step['handler_configs']['universal_web_scraper']['source_url'] === $old_url ) {
				$flow_config[ $step_key ]['handler_configs']['universal_web_scraper']['source_url'] = $new_url;
				++$changed;
			}
		}

		return array(
			'config'  => $flow_config,
			'changed' => $changed,
		);
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
