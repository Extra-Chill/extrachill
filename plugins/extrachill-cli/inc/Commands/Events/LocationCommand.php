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
	 * Generate a market overview report for event calendar locations.
	 *
	 * Combines event/venue counts, flow breakdown (venue scrapers / TM / Dice),
	 * GA4 traffic, and GSC search data into a single view. Use --sort=opportunity
	 * to find cities where adding venue scrapers would have the biggest impact.
	 *
	 * ## OPTIONS
	 *
	 * [--location=<slug>]
	 * : Filter to a single location by slug.
	 *
	 * [--days=<days>]
	 * : Days of analytics data to include.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Max locations to show.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--sort=<field>]
	 * : Sort by: opportunity, events, venues, sessions, impressions, scrapers.
	 * ---
	 * default: opportunity
	 * ---
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
	 *     wp extrachill events market-report --url=events.extrachill.com
	 *     wp extrachill events market-report --location=nashville --url=events.extrachill.com
	 *     wp extrachill events market-report --sort=sessions --days=14 --url=events.extrachill.com
	 *     wp extrachill events market-report --sort=scrapers --url=events.extrachill.com
	 *     wp extrachill events market-report --format=json --url=events.extrachill.com
	 *
	 * @subcommand market-report
	 * @when after_wp_load
	 */
	public function market_report( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/market-report' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/market-report ability not available. Is extrachill-events active on this site?' );
		}

		$input = array(
			'days'  => (int) ( $assoc_args['days'] ?? 7 ),
			'limit' => (int) ( $assoc_args['limit'] ?? 30 ),
			'sort'  => $assoc_args['sort'] ?? 'opportunity',
		);

		if ( ! empty( $assoc_args['location'] ) ) {
			$input['location'] = $assoc_args['location'];
		}

		WP_CLI::log( 'Generating market report...' );

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to generate report.' );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		// Build table rows.
		$rows = array();
		foreach ( $result['locations'] as $loc ) {
			$rows[] = array(
				'location'    => $loc['name'],
				'events'      => $loc['events'],
				'upcoming'    => $loc['upcoming_events'],
				'venues'      => $loc['venues'],
				'scrapers'    => $loc['flows']['venue_scrapers'],
				'tm'          => $loc['flows']['ticketmaster'],
				'dice'        => $loc['flows']['dice'],
				'ga_sessions' => $loc['ga']['sessions'],
				'gsc_impr'    => $loc['gsc']['impressions'],
				'gsc_clicks'  => $loc['gsc']['clicks'],
				'opportunity' => $loc['opportunity_score'],
			);
		}

		if ( 'table' === $format ) {
			$summary = $result['summary'];
			WP_CLI::log( sprintf(
				'Market Report — %d locations, %s events, %s venues, %d flows (%d days analytics)',
				$summary['total_locations'],
				number_format( $summary['total_events'] ),
				number_format( $summary['total_venues'] ),
				$summary['total_flows'],
				$input['days']
			) );
			WP_CLI::log( sprintf( 'Sorted by: %s', $input['sort'] ) );
			WP_CLI::log( str_repeat( '─', 110 ) );
		}

		Utils\format_items(
			$format,
			$rows,
			array( 'location', 'events', 'upcoming', 'venues', 'scrapers', 'tm', 'dice', 'ga_sessions', 'gsc_impr', 'gsc_clicks', 'opportunity' )
		);

		if ( 'table' === $format && ! empty( $result['locations'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Legend: scrapers=venue website scrapers | tm=Ticketmaster | dice=Dice.fm' );
			WP_CLI::log( 'Opportunity = (sessions×5 + impressions×0.5 + events×0.1) × (10 / (scrapers+1))' );
		}
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
