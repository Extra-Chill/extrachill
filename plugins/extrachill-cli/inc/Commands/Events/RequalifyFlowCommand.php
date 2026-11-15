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
 * Thin adapter over VenueRequalifier (extrachill-events).
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\VenueRequalifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RequalifyFlowCommand {

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
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$target  = (string) ( $args[0] ?? '' );
		$limit   = max( 1, (int) ( $assoc_args['limit'] ?? 100 ) );
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$auto    = ! empty( $assoc_args['auto-pause'] );
		$format  = $assoc_args['format'] ?? 'table';

		if ( '' === $target ) {
			\WP_CLI::error( 'Provide a flow id or "all". Example: wp extrachill venues requalify-flow 42' );
			return;
		}

		OwnerSiteAbility::require_service( 'events', 'Events', VenueRequalifier::class );

		$requalifier = new VenueRequalifier();
		$flows       = $requalifier->target_flows( $target, $limit );
		if ( null === $flows ) {
			\WP_CLI::error( sprintf( 'Flow %s not found or has no universal_web_scraper step.', $target ) );
			return;
		}

		if ( empty( $flows ) ) {
			\WP_CLI::log( 'No universal_web_scraper flows found.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Re-qualifying %d flow(s)%s%s.', count( $flows ), $dry_run ? ' (dry run)' : '', $auto ? ' with --auto-pause' : '' ) );

		$results = $requalifier->requalify_flows( $flows, $dry_run, $auto );
		if ( is_wp_error( $results ) ) {
			\WP_CLI::error( $results->get_error_message() );
			return;
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( (string) wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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
