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
	 * [--source=<source>]
	 * : Error source. "php" is debug.log; "rest" is REST responses with status
	 *   >= 500, which never reach debug.log (max 7 days). Table output defaults
	 *   to both; json/csv default to php.
	 * ---
	 * options:
	 *   - php
	 *   - rest
	 *   - all
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
	 *     # REST 5xx responses by route and error code over the last 24 hours.
	 *     wp extrachill analytics errors --source=rest --since=24h
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
		$format = $assoc_args['format'] ?? 'table';
		$source = $assoc_args['source'] ?? ( 'table' === $format ? 'all' : 'php' );
		if ( 'all' === $source && 'table' !== $format ) {
			WP_CLI::error( '--source=all is table-only; pick --source=php or --source=rest for json/csv.' );
		}
		if ( 'rest' !== $source ) {
			$this->report_php( $assoc_args );
		}
		if ( 'php' !== $source ) {
			if ( 'all' === $source ) {
				WP_CLI::log( '' );
			}
			$this->report_rest( $assoc_args );
		}
	}

	/**
	 * Report REST responses with status >= 500 (extrachill/get-rest-5xx-summary).
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	private function report_rest( $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-rest-5xx-summary' );
		if ( ! $ability ) {
			WP_CLI::warning( 'extrachill/get-rest-5xx-summary ability not found; REST 5xx responses are not instrumented.' );
			return;
		}
		$format = $assoc_args['format'] ?? 'table';
		$days   = $this->parse_window( $assoc_args['since'] ?? '7d' );
		$hours  = min( 168, max( 1, 0 === $days ? 168 : $days * 24 ) );
		if ( preg_match( '/^(\d+)\s*h$/i', trim( (string) ( $assoc_args['since'] ?? '' ) ), $m ) ) {
			$hours = min( 168, max( 1, (int) $m[1] ) );
		}
		$result = $ability->execute(
			array(
				'hours' => $hours,
				'limit' => (int) ( $assoc_args['limit'] ?? 25 ),
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( empty( $result['rows'] ) ) {
			WP_CLI::success( sprintf( 'No REST 5xx responses in the last %d hours.', (int) $result['window_hours'] ) );
			return;
		}
		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = array(
				'route'         => $row['route'],
				'code'          => $row['code'],
				'count'         => 'table' === $format ? number_format( $row['count'] ) : (int) $row['count'],
				'per_day'       => 'table' === $format ? number_format( $row['per_day'], 1 ) : (float) $row['per_day'],
				'peak_per_hour' => (int) $row['peak_per_hour'],
			);
		}
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'REST 5xx Responses — last %d hours — source: %s', (int) $result['window_hours'], $result['source'] ) );
			WP_CLI::log( str_repeat( '─', 70 ) );
		}
		Utils\format_items( $format, $rows, array( 'route', 'code', 'count', 'per_day', 'peak_per_hour' ) );
		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Total: %s REST 5xx responses (%s/day) across %d signature%s. %s', number_format( $result['total'] ), number_format( $result['per_day'], 1 ), (int) $result['distinct_signatures'], 1 === (int) $result['distinct_signatures'] ? '' : 's', $result['note'] ) );
		}
	}

	/**
	 * Report PHP debug.log signatures (extrachill/get-php-error-summary).
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	private function report_php( $assoc_args ) {
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
