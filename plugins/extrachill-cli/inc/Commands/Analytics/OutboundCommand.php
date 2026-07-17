<?php
/**
 * Analytics Outbound CLI Command
 *
 * Surfaces the first-party outbound-click report from the
 * extrachill-analytics plugin via the extrachill/get-outbound-clicks ability:
 * where readers EXIT the network to external domains (Spotify, ticketing,
 * artist sites, merch, social), grouped by category, top destination host, and
 * top source page. The cross-DOMAIN counterpart to the conversion map.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OutboundCommand {

	/**
	 * Show the first-party outbound-click report.
	 *
	 * Where readers exit the Extra Chill network to external domains, grouped
	 * into actionable destination categories (spotify/social/ticketing/
	 * artist-site/merch/other), ranked by top destination host, and ranked by
	 * the top source pages that drive the most exits. Deterministic from the
	 * stored sendBeacon outbound_click events. All stored rows are counted: the
	 * generic REST bot stamp does not reliably classify these browser beacons.
	 *
	 * The outbound_click event is NEW — it captures exits going forward only,
	 * so a low or zero total IS the young-data state, not a bug.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back. 0 for all time.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--blog-id=<id>]
	 * : Filter to a specific blog ID. 0 for all sites.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--category=<category>]
	 * : Filter to a single destination category.
	 * ---
	 * default: ''
	 * options:
	 *   - ''
	 *   - spotify
	 *   - social
	 *   - ticketing
	 *   - artist-site
	 *   - merch
	 *   - other
	 * ---
	 *
	 * [--limit=<n>]
	 * : Number of rows in the destination and source rankings.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--by=<dimension>]
	 * : Which ranking to print in table mode.
	 * ---
	 * default: category
	 * options:
	 *   - category
	 *   - destination
	 *   - source
	 * ---
	 *
	 * [--include-bots]
	 * : Deprecated compatibility flag; ignored. The report always includes all
	 * stored outbound browser beacons because their generic REST bot stamp is
	 * not a trustworthy human/bot filter.
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
	 *     wp extrachill analytics outbound
	 *     wp extrachill analytics outbound --by=destination --limit=40
	 *     wp extrachill analytics outbound --category=ticketing --by=source
	 *     wp extrachill analytics outbound --days=0 --format=json
	 *
	 * ## NOTES
	 *
	 * This is a network read and takes NO acting-user context. Do not pass the
	 * global `--user` flag — it is unused here and can emit a noisy "Ambiguous
	 * user match detected" warning on some installs. Omit `--user` entirely.
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-outbound-clicks' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-outbound-clicks ability not found. Is extrachill-analytics active?' );
		}

		$days     = (int) ( $assoc_args['days'] ?? 28 );
		$blog_id  = (int) ( $assoc_args['blog-id'] ?? 0 );
		$category = (string) ( $assoc_args['category'] ?? '' );
		$limit    = max( 1, (int) ( $assoc_args['limit'] ?? 25 ) );
		$by       = $assoc_args['by'] ?? 'category';
		$format   = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'days'     => $days,
				'blog_id'  => $blog_id,
				'category' => $category,
				'limit'    => $limit,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( 'table' !== $format ) {
			$rows    = $this->rows_for( $by, $result );
			$columns = $this->columns_for( $by );
			Utils\format_items( $format, $rows, $columns );
			return;
		}

		$period_label = $days > 0 ? "Last {$days} days" : 'All time';
		WP_CLI::log( sprintf( 'Outbound Clicks — %s (%s)', $period_label, $result['period'] ?? '' ) );
		WP_CLI::log(
			sprintf(
				'Total outbound clicks: %s%s',
				number_format( (int) ( $result['total'] ?? 0 ) ),
				'' !== $category ? "  (category: {$category})" : ''
			)
		);
		WP_CLI::log( str_repeat( '─', 72 ) );

		$rows = $this->rows_for( $by, $result );
		if ( empty( $rows ) ) {
			WP_CLI::log( '(no outbound clicks recorded in window — the event captures exits going forward)' );
		} else {
			$label = array(
				'category'    => 'By destination category:',
				'destination' => 'Top destination hosts:',
				'source'      => 'Top source pages (by outbound clicks):',
			);
			WP_CLI::log( $label[ $by ] ?? 'Outbound clicks:' );
			Utils\format_items( 'table', $rows, $this->columns_for( $by ) );
		}

		if ( ! empty( $result['note'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( $result['note'] );
		}
	}

	/**
	 * Build the display rows for a given ranking dimension.
	 *
	 * @param string $by     One of category|destination|source.
	 * @param array  $result Ability result.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_for( $by, $result ) {
		switch ( $by ) {
			case 'destination':
				return array_map(
					static function ( $d ) {
						return array(
							'dest_host' => (string) ( $d['dest_host'] ?? '' ),
							'category'  => (string) ( $d['category'] ?? '' ),
							'clicks'    => number_format( (int) ( $d['clicks'] ?? 0 ) ),
						);
					},
					(array) ( $result['by_destination'] ?? array() )
				);

			case 'source':
				return array_map(
					static function ( $s ) {
						return array(
							'source_url' => (string) ( $s['source_url'] ?? '' ),
							'clicks'     => number_format( (int) ( $s['clicks'] ?? 0 ) ),
						);
					},
					(array) ( $result['by_source'] ?? array() )
				);

			case 'category':
			default:
				return array_map(
					function ( $c ) {
						return array(
							'category' => (string) ( $c['category'] ?? '' ),
							'clicks'   => number_format( (int) ( $c['clicks'] ?? 0 ) ),
							'share'    => $this->pct( $c['share'] ?? 0 ) . '%',
						);
					},
					(array) ( $result['by_category'] ?? array() )
				);
		}
	}

	/**
	 * Column set for a given ranking dimension.
	 *
	 * @param string $by One of category|destination|source.
	 * @return string[]
	 */
	private function columns_for( $by ) {
		switch ( $by ) {
			case 'destination':
				return array( 'dest_host', 'category', 'clicks' );
			case 'source':
				return array( 'source_url', 'clicks' );
			case 'category':
			default:
				return array( 'category', 'clicks', 'share' );
		}
	}

	/**
	 * Format a 0..1 rate as a one-decimal percent string (no % suffix).
	 *
	 * @param mixed $rate Rate in 0..1.
	 * @return string
	 */
	private function pct( $rate ) {
		return number_format( (float) $rate * 100, 1 );
	}
}
