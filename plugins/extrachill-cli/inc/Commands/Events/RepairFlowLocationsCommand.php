<?php
/**
 * `wp extrachill events flows repair-locations` — repair
 * `taxonomy_location_selection` on `upsert_event` flows so they point at
 * the pipeline's market term instead of `ai_decides` / empty / a stale
 * string.
 *
 * Bleeding-source fix for Extra-Chill/extrachill-events#98. Companion to
 * `wp extrachill events fix-locations` (post-level repair via the
 * extrachill/reconcile-event-locations ability).
 *
 * Reads the events subsite (blog_id=7, prefix `c8c_7_`) — must be invoked
 * with `--url=events.extrachill.com` so $wpdb->prefix resolves correctly.
 *
 * Thin adapter over FlowLocationRepairer (extrachill-events).
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\FlowLocationRepairer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RepairFlowLocationsCommand {

	/**
	 * Repair `taxonomy_location_selection` on every `upsert_event` flow
	 * whose current value would defeat the location-from-venue rule
	 * (`ai_decides`, empty, `skip`, or a stale string that no longer
	 * resolves to a real `location` term).
	 *
	 * Strategy: resolve each flow's pipeline name to a `location` term
	 * ID, then rewrite the upsert step. Writes go through
	 * `Flows::update_flow()` so JSON encoding stays the repository's
	 * responsibility (MEMORY.md load-bearing rule).
	 *
	 * Dry-run is the default. Pass `--commit` to write.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Actually write the repairs. Default is dry-run (no DB writes).
	 *
	 * [--flow-id=<id>]
	 * : Limit to a single flow_id. Useful for spot-fix work.
	 *
	 * [--format=<format>]
	 * : Output format for the per-flow report.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events flows repair-locations --url=events.extrachill.com
	 *     wp extrachill events flows repair-locations --url=events.extrachill.com --commit
	 *     wp extrachill events flows repair-locations --url=events.extrachill.com --flow-id=727
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		OwnerSiteAbility::require_service( 'events', 'Events', FlowLocationRepairer::class );

		if ( ! class_exists( '\\DataMachine\\Core\\Database\\Flows\\Flows' ) ) {
			\WP_CLI::error( 'Data Machine Flows repository is not available on this site.' );
			return;
		}

		$commit  = ! empty( $assoc_args['commit'] );
		$flow_id = isset( $assoc_args['flow-id'] ) ? (int) $assoc_args['flow-id'] : 0;
		$format  = (string) ( $assoc_args['format'] ?? 'table' );

		$result = ( new FlowLocationRepairer() )->repair( $commit, $flow_id );
		if ( null === $result ) {
			\WP_CLI::log( 'No flows with upsert_event found.' );
			return;
		}

		$totals = $result['totals'];

		\WP_CLI\Utils\format_items(
			$format,
			$result['rows'],
			array( 'flow_id', 'flow_name', 'pipeline_id', 'pipeline_name', 'current', 'proposed', 'status', 'reason' )
		);

		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'Summary: scanned=%d needs_repair=%d repaired=%d skipped_clean=%d skipped_unresolved=%d failed=%d',
				$totals['scanned'],
				$totals['needs_repair'],
				$totals['repaired'],
				$totals['skipped_clean'],
				$totals['skipped_unresolved'],
				$totals['failed']
			)
		);

		if ( ! $commit ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run — no changes written. Re-run with --commit to apply.' );
		}
	}
}
