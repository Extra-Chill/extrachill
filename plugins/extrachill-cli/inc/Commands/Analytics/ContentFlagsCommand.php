<?php
/**
 * Analytics Content Flags CLI Command
 *
 * Thin wrapper around the datamachine/content-flags ability — a deterministic
 * TRIAGE SCREEN (not a quality score) over a category's published posts. Reuses
 * the content-performance category->GA4-engagement join and runs structural
 * red-flag signatures over each comparable post (thin, padded_stub, the
 * load-bearing demand_failing_content, and an advisory format_mismatch),
 * surfacing the posts a human should look at — the human judges, the screen
 * does not.
 *
 * Per-page dwell is GA4-only (the first-party pageview table has no duration
 * event), so results carry GA's sampling caveats and are not bot-filtered the
 * way the first-party reads are.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentFlagsCommand {

	/**
	 * Screen a category's posts for deterministic structural red flags.
	 *
	 * Reuses the content-performance category->GA4-engagement join, then runs
	 * deterministic flag signatures: thin (word_count < 600), padded_stub
	 * (>15 headings/1k words AND <1000 words), and the load-bearing
	 * demand_failing_content (>=10 engaged sessions AND avg dwell < 0.4x the
	 * category median). demand_failing_content posts sort first. This is a
	 * TRIAGE SCREEN, not a quality score — it tells you which posts to inspect.
	 *
	 * ## OPTIONS
	 *
	 * --category=<slug>
	 * : Category slug to screen (e.g. music-history, song-meanings).
	 *
	 * [--days=<days>]
	 * : Lookback window in days.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--hostname=<host>]
	 * : Hostname whose pages map to this blog's posts.
	 * ---
	 * default: extrachill.com
	 * ---
	 *
	 * [--include-advisory]
	 * : Include the category-relative advisory format_mismatch flag (listicle
	 * titles in an explainer category). Off by default.
	 *
	 * [--limit=<n>]
	 * : Max flagged rows to display.
	 * ---
	 * default: 30
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
	 *     wp extrachill analytics content-flags --category=music-history
	 *     wp extrachill analytics content-flags --category=song-meanings --include-advisory
	 *     wp extrachill analytics content-flags --category=music-history --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'datamachine/content-flags' );

		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/content-flags ability not found. Is Data Machine Business active and GA configured?' );
		}

		$category = $assoc_args['category'] ?? '';
		if ( '' === $category ) {
			WP_CLI::error( 'A --category=<slug> is required.' );
		}

		$days             = max( 1, (int) ( $assoc_args['days'] ?? 28 ) );
		$hostname         = $assoc_args['hostname'] ?? 'extrachill.com';
		$include_advisory = isset( $assoc_args['include-advisory'] );
		$limit            = max( 1, (int) ( $assoc_args['limit'] ?? 30 ) );
		$format           = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'category'         => $category,
				'days'             => $days,
				'hostname'         => $hostname,
				'include_advisory' => $include_advisory,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Content-flags screen failed.' );
		}

		$posts = array_slice( (array) ( $result['posts'] ?? array() ), 0, $limit );
		$rows  = array_map( array( $this, 'row' ), $posts );

		if ( 'table' !== $format ) {
			Utils\format_items(
				$format,
				$rows,
				array( 'slug', 'flags', 'word_count', 'headings_per_1k', 'engaged_sessions', 'avg_duration', 'category_median' )
			);
			return;
		}

		$window = $result['window'] ?? array();
		WP_CLI::log(
			sprintf(
				'Content Flags — category "%s" — %dd window (%s → %s)',
				$result['category'] ?? $category,
				(int) ( $window['days'] ?? $days ),
				$window['start'] ?? '',
				$window['end'] ?? ''
			)
		);

		$counts = (array) ( $result['flag_counts'] ?? array() );
		WP_CLI::log(
			sprintf(
				'%d comparable · %d flagged · median dwell %ss',
				(int) ( $result['comparable'] ?? 0 ),
				(int) ( $result['flagged'] ?? 0 ),
				$this->num( $result['median_duration'] ?? 0 )
			)
		);
		if ( ! empty( $counts ) ) {
			$parts = array();
			foreach ( $counts as $flag => $n ) {
				$parts[] = "{$flag}: {$n}";
			}
			WP_CLI::log( 'Flag counts — ' . implode( ' · ', $parts ) );
		}
		WP_CLI::log( str_repeat( '─', 72 ) );

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No posts tripped a flag in this window. Widen --days or check a different category.' );
		} else {
			WP_CLI::log( 'Highest-severity first (demand_failing_content > padded_stub > thin):' );
			WP_CLI::log( '' );
			Utils\format_items(
				'table',
				$rows,
				array( 'slug', 'flags', 'word_count', 'headings_per_1k', 'engaged_sessions', 'avg_duration', 'category_median' )
			);
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Triage screen, not a quality score — the flags tell you which posts to inspect; you judge.' );
		WP_CLI::log( 'Note: per-page dwell is GA4-only and carries GA sampling caveats; it is not bot-filtered like the first-party reads.' );
	}

	/**
	 * Shape one ability flagged-post row for display.
	 *
	 * @param array $p Flagged post row from the ability.
	 * @return array<string, mixed>
	 */
	private function row( array $p ) {
		return array(
			'slug'             => $p['slug'] ?? '',
			'flags'            => implode( ',', (array) ( $p['flags'] ?? array() ) ),
			'word_count'       => (int) ( $p['word_count'] ?? 0 ),
			'headings_per_1k'  => $this->num( $p['headings_per_1k'] ?? 0 ),
			'engaged_sessions' => (int) ( $p['engaged_sessions'] ?? 0 ),
			'avg_duration'     => $this->num( $p['avg_duration'] ?? 0 ) . 's',
			'category_median'  => $this->num( $p['category_median'] ?? 0 ) . 's',
		);
	}

	/**
	 * Format a numeric value, rendering whole numbers without a trailing ".0".
	 *
	 * @param mixed $value Value to format.
	 * @return string
	 */
	private function num( $value ) {
		$float = (float) $value;

		if ( $float === floor( $float ) ) {
			return (string) (int) $float;
		}

		return (string) round( $float, 2 );
	}
}
