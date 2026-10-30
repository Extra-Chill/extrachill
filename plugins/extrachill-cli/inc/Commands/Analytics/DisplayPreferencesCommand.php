<?php
/**
 * Display Preferences CLI Command
 *
 * Read-only adapter over the extrachill-analytics display-preferences ability.
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

class DisplayPreferencesCommand {


	use NetworkAwareTrait;

	/**
	 * Show dark, light, and unknown display-preference counts and shares.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back when explicit dates are omitted.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--end-days-ago=<days>]
	 * : End the rolling window this many days ago.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--start-date=<date>]
	 * : Inclusive UTC start date (Y-m-d). Must be paired with --end-date.
	 *
	 * [--end-date=<date>]
	 * : Inclusive UTC end date (Y-m-d). Explicit dates override the rolling window.
	 *
	 * [--site=<site>]
	 * : Filter by blog ID or 'all'. Defaults to current site (all on the main site).
	 *
	 * [--format=<format>]
	 * : Output format. Table shares are percentages; CSV shares are fractions.
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
	 *     wp extrachill analytics display-preferences
	 *     wp extrachill analytics display-preferences --site=all --days=90
	 *     wp extrachill analytics display-preferences --days=28 --end-days-ago=7
	 *     wp extrachill analytics display-preferences --start-date=2026-05-01 --end-date=2026-05-31 --format=json
	 *
	 * ## NOTES
	 *
	 * This is a network/site read and takes NO acting-user context. Do not pass
	 * the global `--user` flag — it is unused here and on installs where a
	 * user_login collides with a numeric user ID (e.g. a login of "1" alongside
	 * user ID 1) WP-CLI emits a harmless but noisy "Ambiguous user match
	 * detected" warning before the output. Omit `--user` entirely.
	 *
	 * @subcommand __default
	 * @when       after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-display-preferences' ) : null;

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-display-preferences ability not found. Is extrachill-analytics active and up to date?' );
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( 'Invalid format. Use table, json, or csv.' );
		}

		$input = array(
			'days'         => (int) ( $assoc_args['days'] ?? 28 ),
			'end_days_ago' => (int) ( $assoc_args['end-days-ago'] ?? 0 ),
			'blog_id'      => $this->get_site_filter( $assoc_args ),
		);

		foreach ( array(
			'start-date' => 'start_date',
			'end-date'   => 'end_date',
		) as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = $assoc_args[ $flag ];
			}
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}

		$rows   = $this->scope_rows( $result, 'table' === $format );
		$fields = array_keys( $rows[0] );
		if ( 'table' === $format ) {
			WP_CLI::log(
				sprintf(
					'Display Preferences — Window (UTC): %s (inclusive) to %s (exclusive) — %s',
					$result['since'],
					$result['until'],
					$this->format_site_label()
				)
			);
			$fields = array_values( array_diff( $fields, array( 'since', 'until' ) ) );
		}

		Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Flatten overall and per-blog metrics, preserving fractional shares in CSV.
	 *
	 * @param  array $result      Ability result.
	 * @param  bool  $percentages Whether to format shares as percentages.
	 * @return array<int, array<string, mixed>>
	 */
	private function scope_rows( array $result, $percentages ) {
		$scopes = array_merge(
			array( array( 'overall', $result['blog_id'], $result['overall'] ) ),
			array_map(
				static function ( $blog_id, $metrics ) {
					return array( 'blog', $blog_id, $metrics );
				},
				array_keys( $result['by_blog'] ),
				array_values( $result['by_blog'] )
			)
		);
		$rows   = array();
		foreach ( $scopes as list( $scope, $blog_id, $metrics ) ) {
			$row = array(
				'scope'   => $scope,
				'blog_id' => $blog_id,
				'since'   => $result['since'],
				'until'   => $result['until'],
			);
			foreach ( array( 'pageviews', 'unique_visitors' ) as $metric ) {
				$row[ $metric . '_total' ] = $metrics[ $metric ]['total'];
				foreach ( array( 'dark', 'light', 'unknown' ) as $scheme ) {
					$row[ $metric . '_' . $scheme . '_count' ] = $metrics[ $metric ]['counts'][ $scheme ];
					$share                                     = $metrics[ $metric ]['shares'][ $scheme ];
					$row[ $metric . '_' . $scheme . '_share' ] = $percentages ? number_format( $share * 100, 2 ) . '%' : $share;
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}
}
