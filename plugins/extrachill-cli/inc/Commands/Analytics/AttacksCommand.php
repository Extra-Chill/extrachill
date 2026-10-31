<?php
/**
 * Analytics Attacks CLI Command
 *
 * Reports search_attack events captured by extrachill-analytics. Wraps the
 * extrachill/get-attack-summary ability.
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

class AttacksCommand {

	use NetworkAwareTrait;

	/**
	 * Show search_attack event summary grouped by pattern, day, IP, or URL.
	 *
	 * Reports scanner / injection probe activity captured by the extrachill-analytics
	 * security classifier. Use this to spot ongoing attacks, identify the worst
	 * offending IPs, and confirm Cloudflare WAF rules are working.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back. 0 for all time.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--group-by=<group>]
	 * : Grouping dimension.
	 * ---
	 * default: pattern
	 * options:
	 *   - pattern
	 *   - day
	 *   - ip
	 *   - url
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum rows to show. 0 for unlimited.
	 * ---
	 * default: 25
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
	 *     # Top attack patterns over the last 28 days (default).
	 *     wp extrachill analytics attacks
	 *
	 *     # Daily attack volume — spot attack windows.
	 *     wp extrachill analytics attacks --group-by=day --limit=0
	 *
	 *     # Worst offending IPs in the last 7 days (post-Cloudflare-real-IP).
	 *     wp extrachill analytics attacks --group-by=ip --days=7 --limit=20
	 *
	 *     # Which pages scanners are hitting hardest.
	 *     wp extrachill analytics attacks --group-by=url --days=28
	 *
	 *     # Network-wide view, JSON output for piping into jq.
	 *     wp extrachill analytics attacks --site=all --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-attack-summary' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-attack-summary ability not found. Is extrachill-analytics active and >= 0.7.0?' );
		}

		$blog_id  = $this->get_site_filter( $assoc_args );
		$days     = (int) ( $assoc_args['days'] ?? 28 );
		$group_by = $assoc_args['group-by'] ?? 'pattern';
		$limit    = (int) ( $assoc_args['limit'] ?? 25 );
		$format   = $assoc_args['format'] ?? 'table';

		$input = array(
			'days'     => $days,
			'group_by' => $group_by,
			'limit'    => $limit,
		);

		if ( $blog_id > 0 ) {
			$input['blog_id'] = $blog_id;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['rows'] ) ) {
			$period = $days > 0 ? "the last {$days} days" : 'all time';
			WP_CLI::success( "No search_attack events found for {$period}." );
			return;
		}

		// Column label depends on grouping dimension.
		$key_label = array(
			'pattern' => 'classification',
			'day'     => 'date',
			'ip'      => 'ip_address',
			'url'     => 'source_url',
		)[ $result['group_by'] ] ?? 'key';

		// Raw integers for json/csv; thousands-separated strings for table.
		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = array(
				$key_label => $row['key'],
				'count'    => 'table' === $format ? number_format( $row['count'] ) : (int) $row['count'],
			);
		}

		if ( 'table' === $format ) {
			$period_label = $days > 0 ? "Last {$days} days" : 'All time';
			$site_label   = $this->format_site_label();
			WP_CLI::log( sprintf(
				'Attack Summary — %s (%s) — %s — grouped by %s',
				$period_label,
				$result['period'],
				$site_label,
				$result['group_by']
			) );
			WP_CLI::log( str_repeat( '─', 70 ) );
		}

		Utils\format_items( $format, $rows, array( $key_label, 'count' ) );

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf(
				'Total: %s attack events across %s distinct %s%s',
				number_format( $result['total'] ),
				number_format( $result['distinct'] ),
				$result['group_by'] . ( 1 === $result['distinct'] ? '' : 's' ),
				$result['truncated'] ? sprintf( ' (showing top %d)', $limit ) : ''
			) );
		}
	}
}
