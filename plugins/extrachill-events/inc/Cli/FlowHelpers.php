<?php
/**
 * Shared flow-access helpers for qualify v2 CLI commands.
 *
 * Lives in extrachill-events even though it reads tables owned by
 * data-machine because qualify v2 is the only consumer in this plugin and the
 * helpers do narrow, well-bounded queries (source_url lookup, run counts,
 * pause via scheduling_config patch).
 *
 * The pause mechanism deliberately uses `scheduling_config.interval = "manual"`
 * + a `paused_reason` field in the same JSON blob, matching the design in
 * issue #75. The canonical `datamachine/pause-flow` ability sets enabled=false
 * instead; that mechanism remains available for other callers, but the
 * verdict-driven pause path uses the interval-manual convention so future
 * SchedulingTiers work (data-machine-events#260) can resume by restoring the
 * tier-appropriate interval without coupling to the enabled flag.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\QualifyVerdict;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait FlowHelpers {

	/**
	 * Fetch the source_url, handler_slug, scheduling_config, and flow_name
	 * for a single flow. Returns null when the flow does not exist or has
	 * no universal_web_scraper step.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array{flow_id:int,flow_name:string,source_url:string,handler_slug:string,scheduling_config:array}|null
	 */
	protected function load_web_scraper_flow( int $flow_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT flow_id, flow_name, flow_config, scheduling_config FROM {$table} WHERE flow_id = %d", $flow_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		return self::extract_web_scraper_meta( $row );
	}

	/**
	 * Find every active flow that uses the universal_web_scraper handler on
	 * its event_import step.
	 *
	 * @return array<int,array{flow_id:int,flow_name:string,source_url:string,handler_slug:string,scheduling_config:array}>
	 */
	protected function load_all_web_scraper_flows(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT flow_id, flow_name, flow_config, scheduling_config
			FROM {$table}
			WHERE flow_config LIKE '%universal_web_scraper%'",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$meta = self::extract_web_scraper_meta( $row );
			if ( null !== $meta ) {
				$out[] = $meta;
			}
		}
		return $out;
	}

	/**
	 * Pull the universal_web_scraper source_url + handler slug out of a flow
	 * row's flow_config JSON.
	 *
	 * @param array $row { flow_id, flow_name, flow_config, scheduling_config }
	 * @return array|null
	 */
	private static function extract_web_scraper_meta( array $row ): ?array {
		$config = json_decode( (string) ( $row['flow_config'] ?? '' ), true );
		if ( ! is_array( $config ) ) {
			return null;
		}

		$source_url   = '';
		$handler_slug = '';

		foreach ( $config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			if ( ( $step['step_type'] ?? '' ) !== 'event_import' ) {
				continue;
			}

			// Two shapes exist in the wild:
			//   1. handler_slug + handler_config  (newer)
			//   2. handler_slugs[] + handler_configs{slug: config}  (older)
			if ( isset( $step['handler_slug'] )
				&& 'universal_web_scraper' === $step['handler_slug']
				&& isset( $step['handler_config']['source_url'] ) ) {
				$source_url   = (string) $step['handler_config']['source_url'];
				$handler_slug = 'universal_web_scraper';
				break;
			}
			if ( isset( $step['handler_configs']['universal_web_scraper']['source_url'] ) ) {
				$source_url   = (string) $step['handler_configs']['universal_web_scraper']['source_url'];
				$handler_slug = 'universal_web_scraper';
				break;
			}
		}

		if ( '' === $source_url ) {
			return null;
		}

		$scheduling = json_decode( (string) ( $row['scheduling_config'] ?? '' ), true );

		return array(
			'flow_id'           => (int) $row['flow_id'],
			'flow_name'         => (string) ( $row['flow_name'] ?? '' ),
			'source_url'        => $source_url,
			'handler_slug'      => $handler_slug,
			'scheduling_config' => is_array( $scheduling ) ? $scheduling : array(),
		);
	}

	/**
	 * Count completed-no-items jobs for a flow in the last `$lookback` runs.
	 *
	 * Used by unqualifiable-flows to identify zero-yield flows.
	 *
	 * @param int $flow_id  Flow ID.
	 * @param int $lookback How many recent rows to consider.
	 * @return array{recent:int,zero_yield:int,any_completed:int}
	 */
	protected function count_recent_runs( int $flow_id, int $lookback = 25 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE flow_id = %s ORDER BY created_at DESC LIMIT %d",
				(string) $flow_id,
				$lookback
			)
		);

		$recent        = is_array( $rows ) ? count( $rows ) : 0;
		$zero_yield    = 0;
		$any_completed = 0;
		foreach ( (array) $rows as $status ) {
			$status = (string) $status;
			if ( 'completed_no_items' === $status ) {
				++$zero_yield;
			}
			// Any "completed" status — including completed_no_items — counts
			// as a successful execution from the scheduler's perspective.
			if ( 0 === strpos( $status, 'completed' ) ) {
				++$any_completed;
			}
		}

		return array(
			'recent'        => $recent,
			'zero_yield'    => $zero_yield,
			'any_completed' => $any_completed,
		);
	}

	/**
	 * Pause a flow by setting scheduling_config.interval = "manual" and
	 * stashing the verdict that triggered the pause in paused_reason.
	 *
	 * Idempotent — re-pausing an already-paused flow is a no-op except for
	 * refreshing paused_reason.
	 *
	 * When the network option `dme_qualify_recheck_enabled` is true (default)
	 * AND the verdict has a non-null recheck interval, an Action Scheduler
	 * job is queued for the next recheck. The action ID is stashed inside
	 * scheduling_config as `recheck_action_id` so a future pause/unpause/
	 * delete can find and cancel the scheduled job.
	 *
	 * @param int    $flow_id    Flow ID.
	 * @param string $verdict    Verdict that triggered the pause.
	 * @param string $source_url Optional source URL — required when
	 *                           recheck scheduling is enabled so the
	 *                           rechecker can re-qualify the URL.
	 * @return bool True on success.
	 */
	protected function pause_flow_by_verdict( int $flow_id, string $verdict, string $source_url = '' ): bool {
		return FlowOps::pause_flow_by_verdict( $flow_id, $verdict, $source_url );
	}
}
