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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SummaryCommand {

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

		$days       = (int) ( $assoc_args['days'] ?? 28 );
		$event_type = $assoc_args['type'] ?? '';
		$format     = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'days'       => $days,
				'event_type' => $event_type,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
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
			WP_CLI::log( sprintf( 'Analytics Summary — %s (%s)', $period_label, $result['period'] ) );
			WP_CLI::log( str_repeat( '─', 55 ) );
		}

		Utils\format_items( $format, $rows, array( 'event_type', 'count', 'daily_avg' ) );

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Total: %s events', number_format( $result['total'] ) ) );
		}
	}
}
