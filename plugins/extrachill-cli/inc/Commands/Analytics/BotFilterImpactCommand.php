<?php
/**
 * Analytics Bot-Filter Impact CLI Command
 *
 * Standing guardrail that counts how much LOGGED-IN human activity the canonical
 * visitor classifier is filtering out as is_bot. Wraps the
 * extrachill/get-bot-filter-impact ability.
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

class BotFilterImpactCommand {

	use NetworkAwareTrait;

	/**
	 * Show analytics events stamped is_bot:true that carry a logged-in user_id.
	 *
	 * The canonical visitor classifier false-flags authenticated, logged-in
	 * users (especially team members) as bots when their action is captured
	 * server-side via REST. This guardrail counts the contradiction
	 * deterministically — events both attributed to a real WordPress user_id AND
	 * discarded as bot traffic — so nobody has to re-discover "team activity is
	 * being filtered" by hand. It MEASURES the mislabeling; it does not fix the
	 * classifier (that is a separate concern in extrachill-analytics).
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back. 0 for all time.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--examples=<examples>]
	 * : Number of example rows to show for spot-checking. 0 to skip.
	 * ---
	 * default: 10
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
	 *     # How much logged-in human activity was filtered as bot over 28 days.
	 *     wp extrachill analytics bot-filter-impact
	 *
	 *     # Last 7 days, network-wide.
	 *     wp extrachill analytics bot-filter-impact --days=7 --site=all
	 *
	 *     # JSON output for piping into jq / monitoring.
	 *     wp extrachill analytics bot-filter-impact --format=json
	 *
	 * ## NOTES
	 *
	 * This is a network/site read and takes NO acting-user context. Do not pass
	 * the global `--user` flag — it is unused here and on installs where a
	 * user_login collides with a numeric user ID WP-CLI emits a harmless but
	 * noisy "Ambiguous user match detected" warning before the output. Omit
	 * `--user` entirely.
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-bot-filter-impact' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-bot-filter-impact ability not found. Is extrachill-analytics active and >= 0.24.0?' );
		}

		$blog_id  = $this->get_site_filter( $assoc_args );
		$days     = (int) ( $assoc_args['days'] ?? 28 );
		$examples = (int) ( $assoc_args['examples'] ?? 10 );
		$format   = $assoc_args['format'] ?? 'table';

		$input = array(
			'days'     => $days,
			'examples' => $examples,
		);

		if ( $blog_id > 0 ) {
			$input['blog_id'] = $blog_id;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows         = $result['by_event_type'] ?? array();
		$example_rows = $result['examples'] ?? array();

		// JSON / CSV: emit the full structured report and stop.
		if ( 'json' === $format || 'csv' === $format ) {
			$out = array();
			foreach ( $rows as $row ) {
				$out[] = array(
					'event_type'     => $row['event_type'],
					'count'          => (int) $row['count'],
					'distinct_users' => (int) $row['distinct_users'],
				);
			}
			Utils\format_items( $format, $out, array( 'event_type', 'count', 'distinct_users' ) );
			return;
		}

		$period_label = $days > 0 ? "Last {$days} days" : 'All time';
		$site_label   = $this->format_site_label();
		WP_CLI::log(
			sprintf(
				'Bot-Filter Impact — %s (%s) — %s',
				$period_label,
				$result['period'] ?? '',
				$site_label
			)
		);
		WP_CLI::log( str_repeat( '─', 70 ) );
		WP_CLI::log(
			sprintf(
				'%s logged-in events filtered as bot · %s distinct users affected',
				number_format( (int) ( $result['total_events'] ?? 0 ) ),
				number_format( (int) ( $result['distinct_users'] ?? 0 ) )
			)
		);

		if ( empty( $rows ) ) {
			WP_CLI::log( '' );
			WP_CLI::success( 'No logged-in activity is being filtered as bot in this window.' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'By event type:' );

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'event_type'     => $row['event_type'],
				'events'         => number_format( (int) $row['count'] ),
				'distinct_users' => number_format( (int) $row['distinct_users'] ),
			);
		}
		Utils\format_items( 'table', $out, array( 'event_type', 'events', 'distinct_users' ) );

		if ( ! empty( $example_rows ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Examples (most recent %s):', number_format( count( $example_rows ) ) ) );
			$ex_out = array();
			foreach ( $example_rows as $row ) {
				$ex_out[] = array(
					'event_type' => $row['event_type'],
					'user_id'    => (int) $row['user_id'],
					'created_at' => $row['created_at'],
				);
			}
			Utils\format_items( 'table', $ex_out, array( 'event_type', 'user_id', 'created_at' ) );
		}
	}
}
