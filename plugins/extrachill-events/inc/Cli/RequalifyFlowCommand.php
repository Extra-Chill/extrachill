<?php
/**
 * `wp extrachill venues requalify-flow` — re-qualify a live flow's URL.
 *
 * Looks up the universal_web_scraper handler config of an existing live flow
 * (or all of them), pulls the source_url, runs extrachill/qualify-venue
 * against it, and reports the new verdict. If the new verdict is no longer
 * qualified, the command recommends pausing — and pauses when --auto-pause is
 * set. Pausing uses scheduling_config.interval = "manual" + a paused_reason
 * field in the same JSON blob (matches the design in issue #75).
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\QualifyVerdict;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RequalifyFlowCommand {

	use FlowHelpers;

	/**
	 * Re-qualify an existing live flow (or every active universal_web_scraper flow).
	 *
	 * Use this for the cleanup pass over flows whose qualification predates
	 * qualify v2 — the new verdict surfaces what is actually happening.
	 *
	 * ## OPTIONS
	 *
	 * <flow>
	 * : Either a flow ID or the literal string "all".
	 *
	 * [--limit=<n>]
	 * : When <flow> is "all", maximum flows to walk.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--auto-pause]
	 * : Automatically pause flows whose new verdict is anything other than
	 * QUALIFIED_STRUCTURED. Without this flag the command only recommends.
	 *
	 * [--dry-run]
	 * : Show what would happen without re-running qualify or pausing.
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
	 *     wp extrachill venues requalify-flow 42
	 *     wp extrachill venues requalify-flow all --dry-run
	 *     wp extrachill venues requalify-flow all --auto-pause
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		$target  = (string) ( $args[0] ?? '' );
		$limit   = max( 1, (int) ( $assoc_args['limit'] ?? 100 ) );
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$auto    = ! empty( $assoc_args['auto-pause'] );
		$format  = $assoc_args['format'] ?? 'table';

		if ( '' === $target ) {
			\WP_CLI::error( 'Provide a flow id or "all". Example: wp extrachill venues requalify-flow 42' );
			return;
		}

		if ( 'all' === $target ) {
			$flows = array_slice( $this->load_all_web_scraper_flows(), 0, $limit );
		} else {
			$flow = $this->load_web_scraper_flow( (int) $target );
			if ( ! $flow ) {
				\WP_CLI::error( sprintf( 'Flow %s not found or has no universal_web_scraper step.', $target ) );
				return;
			}
			$flows = array( $flow );
		}

		if ( empty( $flows ) ) {
			\WP_CLI::log( 'No universal_web_scraper flows found.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Re-qualifying %d flow(s)%s%s.', count( $flows ), $dry_run ? ' (dry run)' : '', $auto ? ' with --auto-pause' : '' ) );

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/qualify-venue' ) : null;
		if ( ! $ability && ! $dry_run ) {
			\WP_CLI::error( 'extrachill/qualify-venue ability not available.' );
			return;
		}

		$results = array();
		foreach ( $flows as $flow ) {
			$row = array(
				'flow_id'       => $flow['flow_id'],
				'flow_name'     => $flow['flow_name'],
				'source_url'    => $flow['source_url'],
				'new_verdict'   => '',
				'event_count'   => 0,
				'action'        => 'none',
				'agent_guidance' => '',
			);

			if ( $dry_run ) {
				$row['action'] = 'would_requalify';
				$results[]     = $row;
				continue;
			}

			$result = $ability->execute( array( 'url' => $flow['source_url'] ) );
			if ( is_wp_error( $result ) ) {
				$row['new_verdict'] = 'error';
				$row['action']      = 'error: ' . $result->get_error_message();
				$results[]          = $row;
				continue;
			}

			$row['new_verdict']    = (string) ( $result['verdict'] ?? '' );
			$row['event_count']    = (int) ( $result['event_count'] ?? 0 );
			$row['agent_guidance'] = (string) ( $result['agent_guidance'] ?? '' );

			if ( QualifyVerdict::QUALIFIED_STRUCTURED === $row['new_verdict'] ) {
				$row['action'] = 'keep';
			} elseif ( QualifyVerdict::is_qualified( $row['new_verdict'] ) ) {
				// QUALIFIED_FOR_FLYER — still qualified but needs review;
				// recommend operator inspection rather than auto-pause.
				$row['action'] = 'review_recommended';
			} else {
				// Not qualified anymore — recommend pause, and pause if --auto-pause.
				if ( $auto ) {
					$ok = $this->pause_flow_by_verdict( $flow['flow_id'], $row['new_verdict'] );
					$row['action'] = $ok ? 'paused' : 'pause_failed';
				} else {
					$row['action'] = 'recommend_pause';
				}
			}

			$results[] = $row;
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$results,
			array( 'flow_id', 'flow_name', 'new_verdict', 'event_count', 'action' )
		);

		// Surface pauses + recommendations underneath the table.
		$paused      = array_filter( $results, fn( $r ) => 'paused' === $r['action'] );
		$recommended = array_filter( $results, fn( $r ) => 'recommend_pause' === $r['action'] );
		if ( ! empty( $paused ) ) {
			\WP_CLI::success( sprintf( 'Paused %d flow(s).', count( $paused ) ) );
		}
		if ( ! empty( $recommended ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( sprintf( '%d flow(s) recommend pausing. Re-run with --auto-pause to apply.', count( $recommended ) ) );
		}
	}
}
