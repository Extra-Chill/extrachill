<?php
/**
 * Events Location CLI Commands
 *
 * Wraps event-location alignment abilities from extrachill-events.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LocationCommand {

	/**
	 * Audit event location assignments against venue city.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Optional comma-separated event post IDs.
	 *
	 * [--limit=<limit>]
	 * : Maximum events to scan. Use 0 for all.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Offset for batched audits.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--include-matches]
	 * : Include already-correct events in the output.
	 *
	 * [--format=<format>]
	 * : Output format.
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
	 *     wp extrachill events audit-locations
	 *     wp extrachill events audit-locations --limit=0 --format=json
	 *     wp extrachill events audit-locations --ids=9936,9919,9842
	 *
	 * @subcommand audit-locations
	 * @when after_wp_load
	 */
	public function audit_locations( $args, $assoc_args ) {
		$result = $this->run_alignment_ability( $assoc_args, false );
		$this->render_result( $result, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Fix event location assignments to match venue city.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Optional comma-separated event post IDs.
	 *
	 * [--limit=<limit>]
	 * : Maximum events to scan. Use 0 for all.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Offset for batched repairs.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--include-matches]
	 * : Include already-correct events in the output.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events fix-locations --ids=9936,9919,9842 --yes
	 *     wp extrachill events fix-locations --limit=0 --yes --format=json
	 *
	 * @subcommand fix-locations
	 * @when after_wp_load
	 */
	public function fix_locations( $args, $assoc_args ) {
		if ( empty( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( 'This will update event location terms to match venue city. Continue?' );
		}

		$result = $this->run_alignment_ability( $assoc_args, true );
		$this->render_result( $result, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Run the alignment ability.
	 *
	 * @param array $assoc_args CLI args.
	 * @param bool  $apply      Whether to apply fixes.
	 * @return array
	 */
	private function run_alignment_ability( array $assoc_args, bool $apply ): array {
		$ability = wp_get_ability( 'extrachill/reconcile-event-locations' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/reconcile-event-locations ability not available. Is extrachill-events active?' );
		}

		$post_ids = array();
		if ( ! empty( $assoc_args['ids'] ) ) {
			$post_ids = array_values(
				array_filter(
					array_map( 'absint', explode( ',', (string) $assoc_args['ids'] ) )
				)
			);
		}

		$result = $ability->execute(
			array(
				'apply'           => $apply,
				'post_ids'        => $post_ids,
				'limit'           => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 500,
				'offset'          => isset( $assoc_args['offset'] ) ? (int) $assoc_args['offset'] : 0,
				'include_matches' => ! empty( $assoc_args['include-matches'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * Render ability result.
	 *
	 * @param array  $result Ability result.
	 * @param string $format Output format.
	 * @return void
	 */
	private function render_result( array $result, string $format ): void {
		$rows = array();
		foreach ( $result['results'] as $item ) {
			$rows[] = array(
				'post_id'              => $item['post_id'],
				'title'                => $item['title'],
				'venue'                => $item['venue'],
				'venue_city'           => $item['venue_city'],
				'assigned_location'    => $item['assigned_location'],
				'expected_location'    => $item['expected_location'],
				'flow_id'              => $item['flow_id'],
				'flow_config_location' => $item['flow_config_location'],
				'status'               => $item['status'],
				'reason'               => $item['reason'],
			);
		}

		if ( 'table' === $format ) {
			WP_CLI::log( $result['message'] );
			WP_CLI::log( str_repeat( '─', 100 ) );
		}

		if ( ! empty( $rows ) ) {
			Utils\format_items(
				$format,
				$rows,
				array(
					'post_id',
					'title',
					'venue_city',
					'assigned_location',
					'expected_location',
					'flow_id',
					'flow_config_location',
					'status',
					'reason',
				)
			);
		} elseif ( 'table' === $format ) {
			WP_CLI::success( 'No affected events found for the selected scope.' );
		}

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Checked: %d', (int) $result['checked_count'] ) );
			WP_CLI::log( sprintf( 'Mismatches: %d', (int) $result['mismatch_count'] ) );
			WP_CLI::log( sprintf( 'Fixed: %d', (int) $result['fixed_count'] ) );
			WP_CLI::log( sprintf( 'Unresolved: %d', (int) $result['unresolved_count'] ) );
		}
	}
}
