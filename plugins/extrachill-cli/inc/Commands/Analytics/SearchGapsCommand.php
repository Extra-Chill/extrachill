<?php
/**
 * Analytics Search Gaps CLI Command
 *
 * Surfaces zero-result and low-result on-site searches — a bot-filtered
 * content-demand report — captured by extrachill-analytics. Wraps the
 * extrachill/get-search-gaps ability.
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

class SearchGapsCommand {

	use NetworkAwareTrait;

	/**
	 * Show zero-result (and optionally low-result) on-site search terms.
	 *
	 * A zero-result search is the highest-signal "what content do users want
	 * that we don't have" datapoint on the platform — the user literally typed
	 * their demand into the search box. This reads existing `search` analytics
	 * (which already store result_count) and reports the top terms that returned
	 * nothing, with scanner / injection spam filtered out.
	 *
	 * Defaults to zero-result only. Raise --max-results to include low-result
	 * near-misses (terms that returned a few weak matches).
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back. 0 for all time.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum terms to show per bucket. 0 for unlimited.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--max-results=<max>]
	 * : Result-count ceiling that defines a gap. 0 shows only zero-result
	 * searches; higher values add low-result near-misses (e.g. 3 = terms whose
	 * best match returned between 1 and 3 results).
	 * ---
	 * default: 0
	 * ---
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
	 *     # Top zero-result searches over the last 28 days (default) — the content-gap list.
	 *     wp extrachill analytics search-gaps
	 *
	 *     # Include low-result near-misses (best match returned 1-3 results).
	 *     wp extrachill analytics search-gaps --max-results=3
	 *
	 *     # Last 7 days, top 50 gaps, network-wide.
	 *     wp extrachill analytics search-gaps --days=7 --limit=50 --site=all
	 *
	 *     # JSON output for piping into jq / feeding content ops.
	 *     wp extrachill analytics search-gaps --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-search-gaps' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-search-gaps ability not found. Is extrachill-analytics active and >= 0.7.0?' );
		}

		$blog_id     = $this->get_site_filter( $assoc_args );
		$days        = (int) ( $assoc_args['days'] ?? 28 );
		$limit       = (int) ( $assoc_args['limit'] ?? 25 );
		$max_results = (int) ( $assoc_args['max-results'] ?? 0 );
		$format      = $assoc_args['format'] ?? 'table';

		$input = array(
			'days'        => $days,
			'limit'       => $limit,
			'max_results' => $max_results,
		);

		if ( $blog_id > 0 ) {
			$input['blog_id'] = $blog_id;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$zero_rows = $result['zero_result'] ?? array();
		$low_rows  = $result['low_result'] ?? array();

		if ( empty( $zero_rows ) && empty( $low_rows ) ) {
			$period = $days > 0 ? "the last {$days} days" : 'all time';
			WP_CLI::success( "No search gaps found for {$period}." );
			return;
		}

		if ( 'table' === $format ) {
			$period_label = $days > 0 ? "Last {$days} days" : 'All time';
			$site_label   = $this->format_site_label();
			WP_CLI::log(
				sprintf(
					'Search Gaps — %s (%s) — %s',
					$period_label,
					$result['period'],
					$site_label
				)
			);
			WP_CLI::log( str_repeat( '─', 70 ) );
			WP_CLI::log(
				sprintf(
					'%s searches · %s zero-result (%s%% rate) · %s bot terms excluded',
					number_format( (int) ( $result['total_searches'] ?? 0 ) ),
					number_format( (int) ( $result['zero_result_total'] ?? 0 ) ),
					$result['zero_result_rate'] ?? 0,
					number_format( (int) ( $result['excluded_bot'] ?? 0 ) )
				)
			);
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Zero-result terms (top %s):', empty( $zero_rows ) ? 0 : number_format( count( $zero_rows ) ) ) );
		}

		$zero_out = array();
		foreach ( $zero_rows as $row ) {
			$zero_out[] = array(
				'search_term' => $row['term'],
				'searches'    => 'table' === $format ? number_format( $row['count'] ) : (int) $row['count'],
			);
		}

		if ( ! empty( $zero_out ) ) {
			Utils\format_items( $format, $zero_out, array( 'search_term', 'searches' ) );
		} elseif ( 'table' === $format ) {
			WP_CLI::log( '(none)' );
		}

		// Low-result bucket only surfaces when --max-results > 0.
		if ( $max_results > 0 ) {
			if ( 'table' === $format ) {
				WP_CLI::log( '' );
				WP_CLI::log( sprintf( 'Low-result terms (1-%d results, top %s):', $max_results, number_format( count( $low_rows ) ) ) );
			}

			$low_out = array();
			foreach ( $low_rows as $row ) {
				$low_out[] = array(
					'search_term' => $row['term'],
					'best_match'  => (int) ( $row['min_results'] ?? 0 ),
					'searches'    => 'table' === $format ? number_format( $row['count'] ) : (int) $row['count'],
				);
			}

			if ( ! empty( $low_out ) ) {
				Utils\format_items( $format, $low_out, array( 'search_term', 'best_match', 'searches' ) );
			} elseif ( 'table' === $format ) {
				WP_CLI::log( '(none)' );
			}
		}

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log(
				sprintf(
					'%s distinct zero-result terms%s in window.',
					number_format( (int) ( $result['zero_result_distinct'] ?? 0 ) ),
					isset( $result['low_result_distinct'] )
					? sprintf( ', %s distinct low-result terms', number_format( (int) $result['low_result_distinct'] ) )
					: ''
				)
			);
		}
	}
}
