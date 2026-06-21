<?php
/**
 * Analytics Content Flags CLI Command
 *
 * Thin wrapper around the datamachine/content-flags ability — a deterministic,
 * OUTCOME-first TRIAGE SCREEN (not a quality score) over a category's published
 * posts. Reuses the content-performance category->GA4-engagement join, flags
 * posts by the single confident outcome rule (demand_failing_content), and
 * annotates each flagged post with advisory structural notes (thin, padded_stub)
 * as a possible explanation — never a standalone verdict. Also surfaces the
 * category coverage ratio (with_traffic/published): a low ratio is a discovery
 * gap, not a content gap.
 *
 * Flags and dwell are valid only WITHIN a category — dwell is contaminated by
 * traffic-source and demand differences between categories, so a post must never
 * be compared against a post in another category. Per-page dwell is GA4-only
 * (the first-party pageview table has no duration event), so results carry GA's
 * sampling caveats and are not bot-filtered the way the first-party reads are.
 *
 * Two confidence guards are surfaced in the output: a per-post `confidence`
 * column (low/moderate/good from engaged_sessions — low-sample dwell is noisy,
 * so don't finely rank low-confidence rows) and a query-intent caveat in the
 * footer (a flagged post may be a healthy quick-answer, not a defect — dwell
 * can't see the query's cultural/topical weight).
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
	 * Screen a category's posts for demand-failing content (outcome-first).
	 *
	 * Reuses the content-performance category->GA4-engagement join, then flags
	 * posts by the ONE confident outcome rule: demand_failing_content
	 * (engaged_sessions >= 10 AND avg dwell < 0.4x the category median). Each
	 * flagged post carries advisory structural notes (thin: < 500 words;
	 * padded_stub: > 15 headings/1k words AND < 1000 words) as a POSSIBLE
	 * explanation — structure never flags a post on its own (it predicts quality
	 * at barely-above-chance precision). Also reports the category coverage ratio
	 * (with_traffic/published); a low ratio signals a discovery gap, not a
	 * content gap. This is a TRIAGE SCREEN, not a quality score, and it is valid
	 * only WITHIN this category.
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
	 *     wp extrachill analytics content-flags --category=song-meanings
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

		$days     = max( 1, (int) ( $assoc_args['days'] ?? 28 ) );
		$hostname = $assoc_args['hostname'] ?? 'extrachill.com';
		$limit    = max( 1, (int) ( $assoc_args['limit'] ?? 30 ) );
		$format   = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'category' => $category,
				'days'     => $days,
				'hostname' => $hostname,
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
				array( 'slug', 'flag', 'confidence', 'structural_notes', 'engaged_sessions', 'avg_duration', 'category_median', 'word_count' )
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

		$published = (int) ( $result['published_total'] ?? 0 );
		$traffic   = (int) ( $result['with_traffic'] ?? 0 );
		$coverage  = (float) ( $result['coverage'] ?? 0 );
		WP_CLI::log(
			sprintf(
				'Coverage: %s%% (%d/%d with traffic) — a LOW ratio is a DISCOVERY gap (crosslink traffic IN), not a content gap.',
				$this->num( $coverage * 100 ),
				$traffic,
				$published
			)
		);
		WP_CLI::log(
			sprintf(
				'%d comparable · %d flagged · median dwell %ss',
				(int) ( $result['comparable'] ?? 0 ),
				(int) ( $result['flagged'] ?? 0 ),
				$this->num( $result['median_duration'] ?? 0 )
			)
		);
		WP_CLI::log( str_repeat( '─', 72 ) );

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No posts tripped demand_failing_content in this window. Widen --days or check coverage above.' );
		} else {
			WP_CLI::log( 'demand_failing_content — real demand, dwell far below the category median (worst-holding first):' );
			WP_CLI::log( '' );
			Utils\format_items(
				'table',
				$rows,
				array( 'slug', 'flag', 'confidence', 'structural_notes', 'engaged_sessions', 'avg_duration', 'category_median', 'word_count' )
			);
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Triage screen, not a quality score. demand_failing_content is the only confident flag (it measures outcome);' );
		WP_CLI::log( 'structural_notes are POSSIBLE explanations, never verdicts — structure predicts quality near chance.' );
		WP_CLI::log( 'Flags/dwell are valid only WITHIN this category — never compare a post against one in another category.' );
		WP_CLI::log( 'Confidence (low/moderate/good) is from engaged_sessions — at low sample size avg dwell is noisy, so the FACT of a' );
		WP_CLI::log( 'flag holds but the worst-holding-first ORDERING among low-confidence rows is soft; do not finely rank them.' );

		$query_intent_caveat = $result['query_intent_caveat'] ?? 'A flagged post may be a quick-answer query where low dwell is APPROPRIATE — the reader got what they came for and left satisfied. Dwell vs. category median cannot distinguish weak content from a shallow-but-satisfied query (the cultural/topical weight of the underlying topic is invisible to this tool). Confirm content weakness with human judgment before treating a flag as a fix; some flagged posts are healthy quick-answers, not defects.';
		WP_CLI::log( '' );
		WP_CLI::warning( 'Query intent: ' . $query_intent_caveat );

		WP_CLI::log( 'Note: per-page dwell is GA4-only and carries GA sampling caveats; it is not bot-filtered like the first-party reads.' );
	}

	/**
	 * Shape one ability flagged-post row for display.
	 *
	 * @param array $p Flagged post row from the ability.
	 * @return array<string, mixed>
	 */
	private function row( array $p ) {
		$notes = (array) ( $p['structural_notes'] ?? array() );

		return array(
			'slug'             => $p['slug'] ?? '',
			'flag'             => $p['flag'] ?? '',
			'confidence'       => $p['confidence'] ?? '',
			'structural_notes' => empty( $notes ) ? '—' : implode( ',', $notes ),
			'engaged_sessions' => (int) ( $p['engaged_sessions'] ?? 0 ),
			'avg_duration'     => $this->num( $p['avg_duration'] ?? 0 ) . 's',
			'category_median'  => $this->num( $p['category_median'] ?? 0 ) . 's',
			'word_count'       => (int) ( $p['word_count'] ?? 0 ),
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
