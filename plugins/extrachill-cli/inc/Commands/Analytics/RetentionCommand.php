<?php
/**
 * Analytics Retention CLI Command
 *
 * Surfaces deterministic, bot-filtered visitor-retention metrics from the
 * extrachill-analytics plugin via the extrachill/get-retention-stats ability.
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

class RetentionCommand {

	use NetworkAwareTrait;

	/**
	 * Show deterministic visitor-retention stats.
	 *
	 * Computes return rate, weekly cohort retention, cross-site return, and
	 * session depth from the first-party pageview events (anonymous ec_vid
	 * visitor_id). All metrics are bot-filtered by construction.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back for the window.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--cohort-weeks=<weeks>]
	 * : Number of weekly cohorts for the retention curve.
	 * ---
	 * default: 8
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
	 *     wp extrachill analytics retention
	 *     wp extrachill analytics retention --days=90
	 *     wp extrachill analytics retention --cohort-weeks=12 --format=json
	 *     wp extrachill analytics retention --site=all
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
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-retention-stats' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-retention-stats ability not found. Is extrachill-analytics active?' );
		}

		$blog_id      = $this->get_site_filter( $assoc_args );
		$days         = (int) ( $assoc_args['days'] ?? 28 );
		$cohort_weeks = (int) ( $assoc_args['cohort-weeks'] ?? 8 );
		$format       = $assoc_args['format'] ?? 'table';

		$input = array(
			'days'         => $days,
			'cohort_weeks' => $cohort_weeks,
		);

		if ( $blog_id > 0 ) {
			$input['blog_id'] = $blog_id;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
			return;
		}

		if ( 'csv' === $format ) {
			Utils\format_items(
				$format,
				array( $this->csv_row( $result ) ),
				array_map( 'strval', array_keys( $result ) )
			);
			return;
		}

		$period_label = $days > 0 ? "Last {$days} days" : 'All time';
		$site_label   = $this->format_site_label();
		WP_CLI::log( sprintf( 'Visitor Retention — %s (%s) — %s', $period_label, $result['period'] ?? '', $site_label ) );
		if ( ! empty( $result['since'] ) ) {
			WP_CLI::log( sprintf( 'Window (UTC): created_at >= %s  (as of %s)', $result['since'], $result['as_of'] ?? '' ) );
		}
		WP_CLI::log( str_repeat( '─', 60 ) );

		$rr = $result['return_rate'];
		WP_CLI::log(
			sprintf(
				'Return rate:       %s%%  (%s of %s visitors active on >= 2 days)',
				number_format( (float) $rr['rate'] * 100, 1 ),
				number_format( (int) $rr['returning_visitors'] ),
				number_format( (int) $rr['total_visitors'] )
			)
		);

		$xs = $result['cross_site_return'];
		WP_CLI::log(
			sprintf(
				'Cross-site return: %s%%  (%s of %s visitors hit >= 2 sites on >= 2 days)',
				number_format( (float) $xs['rate'] * 100, 1 ),
				number_format( (int) $xs['cross_site_visitors'] ),
				number_format( (int) $xs['total_visitors'] )
			)
		);

		$sd = $result['session_depth'];
		WP_CLI::log(
			sprintf(
				'Session depth:     %s avg pageviews/visitor/day (max %s)',
				$sd['avg_pageviews_per_visitor_day'],
				number_format( (int) $sd['max_pageviews_per_visitor_day'] )
			)
		);

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Cohort retention (last %d weekly cohorts):', (int) $result['cohort_retention']['weeks'] ) );

		$rows = $this->cohort_rows( $result );
		if ( empty( $rows ) ) {
			WP_CLI::log( '  (no cohorts in window)' );
		} else {
			Utils\format_items( 'table', $rows, array( 'cohort_week', 'cohort_size', 'retention_w1', 'retention_w2' ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Referrer-host landings:' );
		$referrer_rows = $this->referrer_rows( $result );
		if ( empty( $referrer_rows ) ) {
			WP_CLI::log( '  (no referrer hosts in window)' );
			return;
		}

		Utils\format_items( 'table', $referrer_rows, array( 'referrer_host', 'landings' ) );
	}

	/**
	 * Preserve the complete response envelope in a deterministic CSV row.
	 *
	 * Nested values are JSON-encoded because CSV cells cannot represent arrays.
	 *
	 * @param array $result Ability result.
	 * @return array<string, mixed>
	 */
	private function csv_row( array $result ) {
		foreach ( $result as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$result[ $key ] = wp_json_encode( $value );
			}
		}

		return $result;
	}

	/**
	 * Flatten the cohort retention list into displayable rows.
	 *
	 * @param array $result Ability result.
	 * @return array<int, array<string, mixed>>
	 */
	private function cohort_rows( array $result ) {
		$cohorts = isset( $result['cohort_retention']['cohorts'] ) ? $result['cohort_retention']['cohorts'] : array();
		$rows    = array();

		foreach ( $cohorts as $c ) {
			$rows[] = array(
				'cohort_week'  => $c['cohort_week'],
				'cohort_size'  => number_format( (int) $c['cohort_size'] ),
				'retention_w1' => null === $c['retention_w1'] ? 'n/a' : number_format( (float) $c['retention_w1'] * 100, 1 ) . '%',
				'retention_w2' => null === $c['retention_w2'] ? 'n/a' : number_format( (float) $c['retention_w2'] * 100, 1 ) . '%',
			);
		}

		return $rows;
	}

	/**
	 * Build display rows for referrer-host landings.
	 *
	 * @param array $result Ability result.
	 * @return array<int, array<string, string>>
	 */
	private function referrer_rows( array $result ) {
		$hosts = isset( $result['by_referrer_host']['hosts'] ) ? $result['by_referrer_host']['hosts'] : array();
		$rows  = array();

		foreach ( $hosts as $host ) {
			$rows[] = array(
				'referrer_host' => (string) $host['referrer_host'],
				'landings'      => number_format( (int) $host['landings'] ),
			);
		}

		return $rows;
	}
}
