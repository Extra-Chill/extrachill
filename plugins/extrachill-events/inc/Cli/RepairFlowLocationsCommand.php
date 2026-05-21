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
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use DataMachine\Core\Database\Flows\Flows;
use ExtraChillEvents\Core\FlowLocationGuard;

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
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		if ( ! class_exists( '\\DataMachine\\Core\\Database\\Flows\\Flows' ) ) {
			\WP_CLI::error( 'Data Machine Flows repository is not available on this site.' );
			return;
		}

		$commit  = ! empty( $assoc_args['commit'] );
		$flow_id = isset( $assoc_args['flow-id'] ) ? (int) $assoc_args['flow-id'] : 0;
		$format  = (string) ( $assoc_args['format'] ?? 'table' );

		global $wpdb;
		$flows_table     = $wpdb->prefix . 'datamachine_flows';
		$pipelines_table = $wpdb->prefix . 'datamachine_pipelines';

		// Pull every flow whose upsert step references upsert_event. We
		// intentionally do NOT pre-filter on "ai_decides" — a stale
		// string value (e.g. a pipeline name that no longer matches a
		// real term) is also broken and we want the report to catch it.
		$where  = "f.flow_config LIKE %s";
		$params = array( '%upsert_event%' );

		if ( $flow_id > 0 ) {
			$where   .= ' AND f.flow_id = %d';
			$params[] = $flow_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.flow_id, f.flow_name, f.pipeline_id, p.pipeline_name
				FROM {$flows_table} f
				LEFT JOIN {$pipelines_table} p ON p.pipeline_id = f.pipeline_id
				WHERE {$where}
				ORDER BY f.pipeline_id, f.flow_id",
				...$params
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			\WP_CLI::log( 'No flows with upsert_event found.' );
			return;
		}

		$db_flows = new Flows();

		$report      = array();
		$totals      = array(
			'scanned'           => 0,
			'needs_repair'      => 0,
			'repaired'          => 0,
			'skipped_clean'     => 0,
			'skipped_unresolved'=> 0,
			'failed'            => 0,
		);

		foreach ( $rows as $row ) {
			$totals['scanned']++;

			$fid           = (int) $row['flow_id'];
			$flow_name     = (string) $row['flow_name'];
			$pipeline_id   = (int) $row['pipeline_id'];
			$pipeline_name = (string) ( $row['pipeline_name'] ?? '' );

			$flow_record = $db_flows->get_flow( $fid );
			if ( ! $flow_record || empty( $flow_record['flow_config'] ) || ! is_array( $flow_record['flow_config'] ) ) {
				$report[] = array(
					'flow_id'       => $fid,
					'flow_name'     => $flow_name,
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $pipeline_name,
					'current'       => '',
					'proposed'      => '',
					'status'        => 'skipped',
					'reason'        => 'flow_config_missing',
				);
				continue;
			}

			$current = $this->extractCurrentLocation( $flow_record['flow_config'] );

			// Resolve pipeline's market term.
			$proposed = FlowLocationGuard::resolvePipelineLocationTermId( $pipeline_name );

			// Determine whether this flow actually needs repair.
			$needs_repair = false;
			if ( '' === $current ) {
				// Flow has an upsert_event step but no location key at
				// all — treat as needs_repair only if we have a value
				// to stamp in. Otherwise the upsert handler likely
				// doesn't expose the field.
				$needs_repair = ( '' !== $proposed );
			} elseif ( FlowLocationGuard::isRejectedValue( $current ) ) {
				$needs_repair = true;
			} elseif ( is_numeric( $current ) ) {
				// Numeric — confirm the term still exists.
				$term = get_term( (int) $current, 'location' );
				if ( ! $term || is_wp_error( $term ) ) {
					$needs_repair = true;
				}
			} else {
				// String name — confirm it still resolves to a real
				// term. If not, mark for repair so a stale name gets
				// rewritten to a term ID.
				$term = get_term_by( 'slug', $current, 'location' );
				if ( ! $term ) {
					$term = get_term_by( 'name', $current, 'location' );
				}
				if ( ! $term ) {
					$needs_repair = true;
				}
			}

			if ( ! $needs_repair ) {
				$totals['skipped_clean']++;
				continue;
			}

			$totals['needs_repair']++;

			if ( '' === $proposed ) {
				$totals['skipped_unresolved']++;
				$report[] = array(
					'flow_id'       => $fid,
					'flow_name'     => $flow_name,
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $pipeline_name,
					'current'       => $current,
					'proposed'      => '',
					'status'        => 'skipped',
					'reason'        => 'pipeline_market_unresolved',
				);
				continue;
			}

			if ( ! $commit ) {
				$report[] = array(
					'flow_id'       => $fid,
					'flow_name'     => $flow_name,
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $pipeline_name,
					'current'       => $current,
					'proposed'      => $proposed,
					'status'        => 'would_repair',
					'reason'        => 'dry_run',
				);
				continue;
			}

			// COMMIT path — coerce + write via Flows::update_flow().
			$coerce = FlowLocationGuard::coerceUpsertEventLocation( $flow_record['flow_config'], $proposed );

			if ( empty( $coerce['coerced'] ) ) {
				$totals['skipped_clean']++;
				$report[] = array(
					'flow_id'       => $fid,
					'flow_name'     => $flow_name,
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $pipeline_name,
					'current'       => $current,
					'proposed'      => $proposed,
					'status'        => 'skipped',
					'reason'        => 'no_change_after_coerce',
				);
				continue;
			}

			$ok = $db_flows->update_flow( $fid, array( 'flow_config' => $coerce['config'] ) );

			if ( $ok ) {
				$totals['repaired']++;
				$report[] = array(
					'flow_id'       => $fid,
					'flow_name'     => $flow_name,
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $pipeline_name,
					'current'       => $current,
					'proposed'      => $proposed,
					'status'        => 'repaired',
					'reason'        => 'flow_config_written',
				);
			} else {
				$totals['failed']++;
				$report[] = array(
					'flow_id'       => $fid,
					'flow_name'     => $flow_name,
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $pipeline_name,
					'current'       => $current,
					'proposed'      => $proposed,
					'status'        => 'failed',
					'reason'        => 'update_flow_returned_false',
				);
			}
		}

		// Always include rows that need attention (would_repair, skipped
		// unresolved, failed); skip already-clean rows from the report
		// unless --flow-id was used.
		$visible = array_values(
			array_filter(
				$report,
				static function ( $r ) {
					return 'skipped' !== $r['status'] || 'pipeline_market_unresolved' === $r['reason'];
				}
			)
		);

		if ( $flow_id > 0 ) {
			// Single-flow mode — show everything including clean rows.
			$visible = $report;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$visible,
			array( 'flow_id', 'flow_name', 'pipeline_id', 'pipeline_name', 'current', 'proposed', 'status', 'reason' )
		);

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf(
			'Summary: scanned=%d needs_repair=%d repaired=%d skipped_clean=%d skipped_unresolved=%d failed=%d',
			$totals['scanned'],
			$totals['needs_repair'],
			$totals['repaired'],
			$totals['skipped_clean'],
			$totals['skipped_unresolved'],
			$totals['failed']
		) );

		if ( ! $commit ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run — no changes written. Re-run with --commit to apply.' );
		}
	}

	/**
	 * Extract the current taxonomy_location_selection value from a
	 * decoded flow_config. Returns '' when no upsert_event step exists
	 * or the field is missing entirely.
	 */
	private function extractCurrentLocation( array $flow_config ): string {
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$handler_configs = $step['handler_configs'] ?? array();
			if ( ! is_array( $handler_configs ) || empty( $handler_configs['upsert_event'] ) ) {
				continue;
			}
			$upsert = $handler_configs['upsert_event'];
			if ( ! is_array( $upsert ) ) {
				continue;
			}
			if ( array_key_exists( 'taxonomy_location_selection', $upsert ) ) {
				$val = $upsert['taxonomy_location_selection'];
				return is_scalar( $val ) ? (string) $val : '';
			}
		}
		return '';
	}
}
