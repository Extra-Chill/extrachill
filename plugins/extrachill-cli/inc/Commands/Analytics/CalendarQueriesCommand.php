<?php
/**
 * Calendar usage breakdown.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use ExtraChill\CLI\Traits\NetworkAwareTrait;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CalendarQueriesCommand {

	use NetworkAwareTrait;

	/**
	 * Show human calendar query counts, top filters, and zero-result queries.
	 *
	 * Unique visitors count distinct first-party visitor IDs per trigger.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : UTC days to report, including today (1–90).
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum rows per top-N breakdown.
	 * ---
	 * default: 15
	 * ---
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID or 'all' for network-wide.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		if ( ! function_exists( 'extrachill_analytics_calendar_query_report' ) ) {
			WP_CLI::error( 'Calendar query reporting is unavailable. Is extrachill-analytics active and up to date?' );
		}

		$result = extrachill_analytics_calendar_query_report(
			(int) $assoc_args['days'],
			$this->get_site_filter( $assoc_args ),
			(int) $assoc_args['limit']
		);
		if ( 'json' === $assoc_args['format'] ) {
			$json = wp_json_encode( $result );
			if ( false === $json ) {
				WP_CLI::error( 'Could not encode the calendar query report as JSON.' );
				return;
			}
			WP_CLI::line( $json );
			return;
		}

		WP_CLI::log( sprintf( '%s — %d zero-result queries', $this->format_site_label(), $result['zero_results'] ) );
		if ( ! $result['triggers'] ) {
			WP_CLI::log( 'No human calendar queries found.' );
			return;
		}
		Utils\format_items( 'table', $result['triggers'], array( 'trigger', 'queries', 'unique_human_visitors' ) );
		foreach ( array(
			'scopes'       => 'Scopes',
			'taxonomies'   => 'Taxonomy terms',
			'search_terms' => 'Search terms',
		) as $key => $label ) {
			if ( $result[ $key ] ) {
				WP_CLI::log( $label );
				Utils\format_items( 'table', $result[ $key ], array( 'value', 'queries' ) );
			}
		}
	}
}
