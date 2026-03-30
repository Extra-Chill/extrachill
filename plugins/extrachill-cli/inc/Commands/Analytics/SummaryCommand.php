<?php
/**
 * Analytics Summary CLI Command
 *
 * Provides event count summaries from the extrachill-analytics plugin
 * via the extrachill/get-analytics-summary ability.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;
use ExtraChill\CLI\Traits\NetworkAwareTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SummaryCommand {

	use NetworkAwareTrait;

	/**
	 * Show analytics event summary by type.
	 *
	 * Displays event counts grouped by type with daily averages.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back. 0 for all time.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--type=<type>]
	 * : Filter to a specific event type.
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
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
	 *     wp extrachill analytics summary
	 *     wp extrachill analytics summary --days=7
	 *     wp extrachill analytics summary --type=user_registration
	 *     wp extrachill analytics summary --days=0
	 *     wp extrachill analytics summary --site=7
	 *     wp extrachill analytics summary --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-analytics-summary' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-analytics-summary ability not found. Is extrachill-analytics active?' );
		}

		$blog_id    = $this->get_site_filter( $assoc_args );
		$days       = (int) ( $assoc_args['days'] ?? 28 );
		$event_type = $assoc_args['type'] ?? '';
		$format     = $assoc_args['format'] ?? 'table';

		$input = array(
			'days'       => $days,
			'event_type' => $event_type,
		);

		if ( $blog_id > 0 ) {
			$input['blog_id'] = $blog_id;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		// Warn if site filtering was requested but the ability doesn't support it yet.
		if ( $blog_id > 0 && ! empty( $result['event_types'] ) ) {
			// Check if the ability actually filtered — if input_schema lacks blog_id, it was ignored.
			$schema = $ability->get_input_schema();
			if ( empty( $schema['properties']['blog_id'] ) ) {
				WP_CLI::warning( 'The extrachill/get-analytics-summary ability does not support blog_id filtering yet. Showing network-wide data.' );
			}
		}

		if ( empty( $result['event_types'] ) ) {
			$period = $days > 0 ? "the last {$days} days" : 'all time';
			WP_CLI::success( "No analytics events found for {$period}." );
			return;
		}

		$rows = array();
		foreach ( $result['event_types'] as $item ) {
			$rows[] = array(
				'event_type' => $item['event_type'],
				'count'      => number_format( $item['count'] ),
				'daily_avg'  => $item['daily_avg'],
			);
		}

		if ( 'table' === $format ) {
			$period_label = $days > 0 ? "Last {$days} days" : 'All time';
			$site_label   = $this->format_site_label();
			WP_CLI::log( sprintf( 'Analytics Summary — %s (%s) — %s', $period_label, $result['period'], $site_label ) );
			WP_CLI::log( str_repeat( '─', 55 ) );
		}

		Utils\format_items( $format, $rows, array( 'event_type', 'count', 'daily_avg' ) );

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Total: %s events', number_format( $result['total'] ) ) );
		}
	}
}
