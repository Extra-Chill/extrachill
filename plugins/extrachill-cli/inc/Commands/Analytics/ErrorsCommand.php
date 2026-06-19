<?php
/**
 * Analytics PHP Errors CLI Command
 *
 * Buckets WordPress PHP error-log (debug.log) entries by normalized signature
 * with stable per-day rates that survive log rotation. Wraps the
 * extrachill/get-php-error-summary ability.
 *
 * This is NOT the Data Machine job logger (`wp datamachine logs`) — it reads the
 * raw PHP error log file written by WP_DEBUG_LOG.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ErrorsCommand {

	/**
	 * Show PHP error-log signatures with count and stable per-day rate.
	 *
	 * Reads wp-content/debug.log, normalizes each entry to an error signature
	 * (stripping timestamps, line numbers, IDs, and request-specific values),
	 * and reports the top signatures by volume. A daily snapshot persists
	 * per-signature counts so the per-day rate stays trustworthy across log
	 * rotation instead of being distorted by an eyeballed time span.
	 *
	 * ## OPTIONS
	 *
	 * [--since=<window>]
	 * : Look-back window. Accepts "7d", "24h", "30d", or a bare integer (days).
	 *   Use 0 or "all" for all available history.
	 * ---
	 * default: 7d
	 * ---
	 *
	 * [--severity=<severity>]
	 * : Filter by severity.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - fatal
	 *   - warning
	 *   - notice
	 *   - deprecated
	 *   - strict
	 *   - parse
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum signatures to show. 0 for unlimited.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--snapshot]
	 * : Capture the current debug.log tail into the durable table before reporting.
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
	 *     # Top PHP error signatures over the last 7 days (default).
	 *     wp extrachill analytics errors
	 *
	 *     # Last 24 hours, fatals only.
	 *     wp extrachill analytics errors --since=24h --severity=fatal
	 *
	 *     # All available history, every signature, JSON for piping into jq.
	 *     wp extrachill analytics errors --since=all --limit=0 --format=json
	 *
	 *     # Force a snapshot of the current log tail, then report.
	 *     wp extrachill analytics errors --snapshot
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
		$ability = wp_get_ability( 'extrachill/get-php-error-summary' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-php-error-summary ability not found. Is extrachill-analytics active and >= 0.8.0?' );
		}

		$days     = $this->parse_window( $assoc_args['since'] ?? '7d' );
		$severity = $assoc_args['severity'] ?? 'all';
		$limit    = (int) ( $assoc_args['limit'] ?? 25 );
		$snapshot = isset( $assoc_args['snapshot'] );
		$format   = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'days'     => $days,
				'severity' => $severity,
				'limit'    => $limit,
				'snapshot' => $snapshot,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['rows'] ) ) {
			$period = $days > 0 ? "the last {$days} days" : 'all available history';
			WP_CLI::success( "No PHP errors found for {$period}." );
			return;
		}

		// Raw integers for json/csv; formatted strings for table.
		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = array(
				'severity' => $row['severity'],
				'file'     => $row['file'],
				'count'    => 'table' === $format ? number_format( $row['count'] ) : (int) $row['count'],
				'per_day'  => 'table' === $format ? number_format( $row['per_day'], 1 ) : (float) $row['per_day'],
				'message'  => $row['sample'],
			);
		}

		if ( 'table' === $format ) {
			$period_label = $days > 0 ? "Last {$days} days" : 'All history';
			WP_CLI::log( sprintf(
				'PHP Error Signatures — %s (%s) — source: %s',
				$period_label,
				$result['period'],
				$result['source']
			) );
			WP_CLI::log( sprintf( 'Log: %s', $result['log_path'] ) );
			WP_CLI::log( str_repeat( '─', 70 ) );
		}

		Utils\format_items( $format, $rows, array( 'severity', 'file', 'count', 'per_day', 'message' ) );

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf(
				'Total: %s errors across %s distinct signature%s over %d day%s covered%s',
				number_format( $result['total'] ),
				number_format( $result['distinct_signatures'] ),
				1 === $result['distinct_signatures'] ? '' : 's',
				$result['days_covered'],
				1 === $result['days_covered'] ? '' : 's',
				$result['truncated'] ? sprintf( ' (showing top %d)', $limit ) : ''
			) );
		}
	}

	/**
	 * Parse a --since window into a day count.
	 *
	 * Accepts "7d", "24h", "30d", a bare integer (days), or "0"/"all" for
	 * all available history.
	 *
	 * @param string $window Window string.
	 * @return int Number of days (0 = all history).
	 */
	private function parse_window( $window ) {
		$window = strtolower( trim( (string) $window ) );

		if ( '' === $window || 'all' === $window || '0' === $window ) {
			return 0;
		}

		if ( preg_match( '/^(\d+)\s*([dh]?)$/', $window, $m ) ) {
			$value = (int) $m[1];
			$unit  = $m[2];

			if ( 'h' === $unit ) {
				// Round hours up to whole days; minimum 1 day so the rate denominator is sane.
				return max( 1, (int) ceil( $value / 24 ) );
			}

			return $value; // Days (or bare integer).
		}

		WP_CLI::warning( sprintf( 'Could not parse --since="%s"; defaulting to 7 days.', $window ) );
		return 7;
	}
}
