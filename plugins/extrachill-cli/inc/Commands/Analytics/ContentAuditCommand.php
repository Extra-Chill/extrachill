<?php
/**
 * Analytics Content Audit CLI Command
 *
 * Thin wrapper around the datamachine/content-performance ability — the
 * within-category content-performance instrument. Ranks a category's published
 * posts by GA4 engagement (avg session duration, engagement rate) to surface
 * content-level UNDERPERFORMERS: posts that draw real demand but hold readers
 * poorly, versus structurally-similar siblings that perform well.
 *
 * This is the engagement-axis answer to "which posts in this category are weak
 * content despite similar surface traits" — the editorial update/rewrite/retire
 * worklist. Per-page dwell is GA4-only (the first-party pageview table has no
 * duration event), so results carry GA's sampling caveats and are not
 * bot-filtered the way the first-party reads are.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentAuditCommand {

	/**
	 * Rank a category's posts by engagement to surface content underperformers.
	 *
	 * Joins the category's published posts to GA4 per-page engagement and ranks
	 * them so the weakest (real traffic, low dwell / low engagement rate) sort
	 * first — the editorial update/rewrite/retire list. Each row carries word
	 * count and post age so two structurally-similar posts with divergent
	 * outcomes are directly comparable. Posts below --min-sessions are listed
	 * separately as insufficient-sample, never ranked, so a single anomalous
	 * session cannot dominate the dwell average.
	 *
	 * ## OPTIONS
	 *
	 * --category=<slug>
	 * : Category slug to audit (e.g. music-history, song-meanings).
	 *
	 * [--days=<days>]
	 * : Lookback window in days.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--min-sessions=<n>]
	 * : Minimum engaged sessions for a post to be ranked.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--sort=<metric>]
	 * : Rank metric. Underperformers sort ascending on this.
	 * ---
	 * default: duration
	 * options:
	 *   - duration
	 *   - rate
	 * ---
	 *
	 * [--hostname=<host>]
	 * : Hostname whose pages map to this blog's posts.
	 * ---
	 * default: extrachill.com
	 * ---
	 *
	 * [--limit=<n>]
	 * : Max ranked rows to display.
	 * ---
	 * default: 20
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
	 *     wp extrachill analytics content-audit --category=music-history
	 *     wp extrachill analytics content-audit --category=song-meanings --min-sessions=10
	 *     wp extrachill analytics content-audit --category=music-history --sort=rate --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'datamachine/content-performance' );

		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/content-performance ability not found. Is Data Machine Business active and GA configured?' );
		}

		$category = $assoc_args['category'] ?? '';
		if ( '' === $category ) {
			WP_CLI::error( 'A --category=<slug> is required.' );
		}

		$days         = max( 1, (int) ( $assoc_args['days'] ?? 28 ) );
		$min_sessions = max( 1, (int) ( $assoc_args['min-sessions'] ?? 5 ) );
		$sort_by      = ( 'rate' === ( $assoc_args['sort'] ?? '' ) ) ? 'rate' : 'duration';
		$hostname     = $assoc_args['hostname'] ?? 'extrachill.com';
		$limit        = max( 1, (int) ( $assoc_args['limit'] ?? 20 ) );
		$format       = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'category'     => $category,
				'days'         => $days,
				'min_sessions' => $min_sessions,
				'sort_by'      => $sort_by,
				'hostname'     => $hostname,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Content audit failed.' );
		}

		$posts = array_slice( (array) ( $result['posts'] ?? array() ), 0, $limit );
		$rows  = array_map( array( $this, 'row' ), $posts );

		if ( 'table' !== $format ) {
			Utils\format_items(
				$format,
				$rows,
				array( 'slug', 'engaged_sessions', 'avg_duration', 'engagement_rate', 'word_count', 'age_days' )
			);
			return;
		}

		$window = $result['window'] ?? array();
		WP_CLI::log(
			sprintf(
				'Content Audit — category "%s" — %dd window (%s → %s)',
				$result['category'] ?? $category,
				(int) ( $window['days'] ?? $days ),
				$window['start'] ?? '',
				$window['end'] ?? ''
			)
		);
		$published = (int) ( $result['published_total'] ?? 0 );
		$traffic   = (int) ( $result['with_traffic'] ?? 0 );
		WP_CLI::log(
			sprintf(
				'%d published · %d with traffic · %d comparable (>=%d sessions) · median dwell %ss',
				$published,
				$traffic,
				(int) ( $result['comparable'] ?? 0 ),
				$min_sessions,
				$this->num( $result['median_duration'] ?? 0 )
			)
		);
		$coverage = $published > 0 ? round( ( $traffic / $published ) * 100, 1 ) : 0.0;
		WP_CLI::log(
			sprintf(
				'Coverage: %s%% (%d/%d) — a LOW ratio signals a DISCOVERY gap (fix: crosslink traffic IN), distinct from a content-quality gap.',
				$this->num( $coverage ),
				$traffic,
				$published
			)
		);
		WP_CLI::log( str_repeat( '─', 72 ) );

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No comparable posts in window. Lower --min-sessions or widen --days.' );
		} else {
			WP_CLI::log(
				sprintf(
					'Underperformers first (sorted by %s, ascending):',
					'rate' === $sort_by ? 'engagement rate' : 'avg session duration'
				)
			);
			WP_CLI::log( '' );
			Utils\format_items(
				'table',
				$rows,
				array( 'slug', 'engaged_sessions', 'avg_duration', 'engagement_rate', 'word_count', 'age_days' )
			);
		}

		$insufficient = (array) ( $result['insufficient_sample'] ?? array() );
		if ( ! empty( $insufficient ) ) {
			WP_CLI::log( '' );
			WP_CLI::log(
				sprintf(
					'%d post(s) had traffic but <%d engaged sessions — excluded from ranking (too small to compare honestly).',
					count( $insufficient ),
					$min_sessions
				)
			);
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Note: per-page dwell is GA4-only and carries GA sampling caveats; it is not bot-filtered like the first-party reads.' );
	}

	/**
	 * Shape one ability post row for display.
	 *
	 * @param array $p Post row from the ability.
	 * @return array<string, mixed>
	 */
	private function row( array $p ) {
		return array(
			'slug'             => $p['slug'] ?? '',
			'engaged_sessions' => (int) ( $p['engaged_sessions'] ?? 0 ),
			'avg_duration'     => $this->num( $p['avg_duration'] ?? 0 ) . 's',
			'engagement_rate'  => $this->num( $p['engagement_rate'] ?? 0 ),
			'word_count'       => (int) ( $p['word_count'] ?? 0 ),
			'age_days'         => (int) ( $p['age_days'] ?? 0 ),
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

		if ( floor( $float ) === $float ) {
			return (string) (int) $float;
		}

		return (string) round( $float, 2 );
	}
}
