<?php
/**
 * Analytics Crosslink Clicks CLI Command
 *
 * Thin formatter over the extrachill/get-crosslink-clicks ability from the
 * extrachill-analytics plugin: per source post, how many readers clicked a
 * network link inside the post body, against the post's human pageviews.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrosslinkClicksCommand {

	/**
	 * Show in-article crosslink clicks per source post.
	 *
	 * Counts clicks on network links inside the post body (same site and other
	 * network sites) and divides by the post's human pageviews. Destinations
	 * Data Machine inserted are counted as `inserted`; the rest as `manual`.
	 * Posts Data Machine crosslinked are listed even with zero clicks.
	 *
	 * The content_link_click event is new and counts clicks going forward only,
	 * so low numbers are the young-data state, not a bug.
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
	 * : Site to report on. 0 for the current site (use --url to target one).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--post-id=<id>]
	 * : Show one source post, with a destination breakdown.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--limit=<n>]
	 * : Number of source posts to show.
	 * ---
	 * default: 25
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
	 *     wp extrachill analytics crosslink-clicks
	 *     wp extrachill analytics crosslink-clicks --days=7
	 *     wp extrachill analytics crosslink-clicks --post-id=96350
	 *     wp extrachill analytics crosslink-clicks --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-crosslink-clicks' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-crosslink-clicks ability not found. Is extrachill-analytics active?' );
			return;
		}

		$days    = (int) ( $assoc_args['days'] ?? 28 );
		$post_id = (int) ( $assoc_args['post-id'] ?? 0 );
		$format  = (string) ( $assoc_args['format'] ?? 'table' );

		$result = $ability->execute(
			array(
				'days'    => $days,
				'blog_id' => (int) ( $assoc_args['blog-id'] ?? 0 ),
				'post_id' => $post_id,
				'limit'   => max( 1, (int) ( $assoc_args['limit'] ?? 25 ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		$posts   = (array) ( $result['posts'] ?? array() );
		$columns = array( 'post_id', 'title', 'pageviews', 'clicks', 'click_visitors', 'click_rate', 'inserted_links', 'inserted_clicks', 'manual_clicks' );

		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( 'table' !== $format ) {
			Utils\format_items( $format, array_map( array( $this, 'row' ), $posts ), $columns );
			return;
		}

		$totals = (array) ( $result['totals'] ?? array() );
		WP_CLI::log( sprintf( 'Crosslink clicks — %s', $days > 0 ? "last {$days} days" : 'all time' ) . ' (' . ( $result['period'] ?? '' ) . ')' );
		WP_CLI::log(
			sprintf(
				'%d posts · %s clicks / %s human pageviews (%s%%) · inserted %d · manual %d',
				(int) ( $totals['posts'] ?? 0 ),
				number_format( (int) ( $totals['clicks'] ?? 0 ) ),
				number_format( (int) ( $totals['pageviews'] ?? 0 ) ),
				$this->pct( $totals['click_rate'] ?? 0 ),
				(int) ( $totals['inserted_clicks'] ?? 0 ),
				(int) ( $totals['manual_clicks'] ?? 0 )
			)
		);

		if ( empty( $posts ) ) {
			WP_CLI::log( '(no in-article link clicks and no crosslinked posts in this window)' );
		} else {
			Utils\format_items( 'table', array_map( array( $this, 'row' ), $posts ), $columns );
		}

		if ( $post_id > 0 && ! empty( $posts[0]['destinations'] ) ) {
			WP_CLI::log( 'Destinations:' );
			Utils\format_items( 'table', (array) $posts[0]['destinations'], array( 'destination', 'clicks', 'link_kind', 'origin' ) );
		}

		if ( ! empty( $result['note'] ) ) {
			WP_CLI::log( (string) $result['note'] );
		}
	}

	/**
	 * Flatten one source-post row for display.
	 *
	 * @param array $post Ability row.
	 * @return array<string, mixed>
	 */
	public function row( $post ) {
		return array(
			'post_id'         => (int) ( $post['post_id'] ?? 0 ),
			'title'           => mb_substr( (string) ( $post['title'] ?? '' ), 0, 50 ),
			'pageviews'       => (int) ( $post['pageviews'] ?? 0 ),
			'clicks'          => (int) ( $post['clicks'] ?? 0 ),
			'click_visitors'  => (int) ( $post['click_visitors'] ?? 0 ),
			'click_rate'      => $this->pct( $post['click_rate'] ?? 0 ) . '%',
			'inserted_links'  => (int) ( $post['inserted_links'] ?? 0 ),
			'inserted_clicks' => (int) ( $post['inserted_clicks'] ?? 0 ),
			'manual_clicks'   => (int) ( $post['manual_clicks'] ?? 0 ),
		);
	}

	/**
	 * Format a 0..1 rate as a percent string with two decimals (no suffix).
	 *
	 * @param mixed $rate Rate in 0..1.
	 * @return string
	 */
	private function pct( $rate ) {
		return number_format( (float) $rate * 100, 2 );
	}
}
