<?php
/**
 * Analytics Conversion CLI Command
 *
 * Surfaces the first-party cross-surface conversion map from the
 * extrachill-analytics plugin via the extrachill/get-conversion-map ability:
 * for visitors whose first session starts on an editorial article (blog 1),
 * the share that reach a platform surface (events/community/artist) same-session
 * or on a return visit, ranked per entry article and per entry category.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConversionCommand {

	/**
	 * Show the first-party cross-surface conversion map.
	 *
	 * For visitors whose first session starts on a blog-1 editorial article,
	 * reports the share that reach a platform surface (events/community/artist)
	 * in the SAME session vs on a RETURN visit — the two are distinct and shown
	 * separately. Ranked per entry article and per entry category (the same
	 * category axis content-flags uses). Deterministic + bot-filtered from the
	 * first-party ec_vid pageview events; a low or zero reach IS the finding
	 * (the article front door is siloed from the platform), not a bug.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back for the window.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--session-gap-mins=<mins>]
	 * : Inactivity gap (minutes) that ends a session. GA-standard is 30.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--top-articles=<n>]
	 * : Number of top entry articles to rank.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--min-entry-sessions=<n>]
	 * : Minimum entry sessions for an article/category to appear.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--by=<dimension>]
	 * : Which ranking to print in table mode.
	 * ---
	 * default: category
	 * options:
	 *   - category
	 *   - article
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
	 *     wp extrachill analytics conversion
	 *     wp extrachill analytics conversion --by=article --top-articles=40
	 *     wp extrachill analytics conversion --days=90 --min-entry-sessions=10
	 *     wp extrachill analytics conversion --format=json
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
		$ability = wp_get_ability( 'extrachill/get-conversion-map' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-conversion-map ability not found. Is extrachill-analytics active?' );
		}

		$days               = max( 1, (int) ( $assoc_args['days'] ?? 28 ) );
		$session_gap_mins   = max( 1, (int) ( $assoc_args['session-gap-mins'] ?? 30 ) );
		$top_articles       = max( 1, (int) ( $assoc_args['top-articles'] ?? 25 ) );
		$min_entry_sessions = max( 1, (int) ( $assoc_args['min-entry-sessions'] ?? 1 ) );
		$by                 = $assoc_args['by'] ?? 'category';
		$format             = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'days'               => $days,
				'session_gap_mins'   => $session_gap_mins,
				'top_articles'       => $top_articles,
				'min_entry_sessions' => $min_entry_sessions,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( 'table' !== $format ) {
			$rows    = ( 'article' === $by )
				? array_map( array( $this, 'article_machine_row' ), (array) ( $result['by_article'] ?? array() ) )
				: array_map( array( $this, 'category_machine_row' ), (array) ( $result['by_category'] ?? array() ) );
			$columns = ( 'article' === $by )
				? array( 'post_id', 'title', 'url', 'path', 'entry_sessions', 'reached_any', 'reached_any_rate', 'reached_any_same_count', 'same_session_rate', 'reached_any_return_count', 'return_rate', 'returned_count', 'returned_rate' )
				: array( 'term_id', 'category', 'entry_sessions', 'reached_any', 'reached_any_rate', 'reached_any_same_count', 'same_session_rate', 'reached_any_return_count', 'return_rate', 'returned_count', 'returned_rate' );
			Utils\format_items( $format, $rows, $columns );
			return;
		}

		$overall      = $result['overall'] ?? array();
		$period_label = $days > 0 ? "Last {$days} days" : 'All time';
		WP_CLI::log( sprintf( 'Cross-Surface Conversion Map — %s (%s)', $period_label, $result['period'] ?? '' ) );
		if ( ! empty( $result['since'] ) ) {
			WP_CLI::log( sprintf( 'Window (UTC): created_at >= %s  (as of %s)', $result['since'], $result['as_of'] ?? '' ) );
		}
		WP_CLI::log(
			sprintf(
				'Entry: blog %d editorial articles → platform surfaces %s. Session gap: %dm.',
				(int) ( $result['entry_blog_id'] ?? 1 ),
				wp_json_encode( $result['platform_blogs'] ?? array() ),
				$session_gap_mins
			)
		);
		WP_CLI::log( str_repeat( '─', 72 ) );

		$n = (int) ( $overall['entry_sessions'] ?? 0 );
		WP_CLI::log( sprintf( 'Article-entry journeys:  %s', number_format( $n ) ) );
		WP_CLI::log(
			sprintf(
				'Reached ANY platform:    %s%%  (%s) — same-session %s%% | return %s%%',
				$this->pct( $overall['reached_any_rate'] ?? 0 ),
				number_format( (int) ( $overall['reached_any'] ?? 0 ) ),
				$this->pct( $overall['same_session']['any'] ?? 0 ),
				$this->pct( $overall['return']['any'] ?? 0 )
			)
		);
		WP_CLI::log(
			sprintf(
				'  same-session:  events %s%% | community %s%% | artist %s%%',
				$this->pct( $overall['same_session']['events'] ?? 0 ),
				$this->pct( $overall['same_session']['community'] ?? 0 ),
				$this->pct( $overall['same_session']['artist'] ?? 0 )
			)
		);
		WP_CLI::log(
			sprintf(
				'  return:        events %s%% | community %s%% | artist %s%%',
				$this->pct( $overall['return']['events'] ?? 0 ),
				$this->pct( $overall['return']['community'] ?? 0 ),
				$this->pct( $overall['return']['artist'] ?? 0 )
			)
		);
		WP_CLI::log( sprintf( 'Returned (2nd session):  %s%%', $this->pct( $overall['returned_rate'] ?? 0 ) ) );

		WP_CLI::log( '' );

		if ( 'article' === $by ) {
			WP_CLI::log( sprintf( 'Top %d entry articles (by entry sessions):', $top_articles ) );
			$rows = array_map( array( $this, 'article_row' ), (array) ( $result['by_article'] ?? array() ) );
			if ( empty( $rows ) ) {
				WP_CLI::log( '  (no entry articles in window)' );
				return;
			}
			Utils\format_items( 'table', $rows, array( 'title', 'entry_sessions', 'same_any', 'return_any', 'returned', 'reached_any' ) );
		} else {
			WP_CLI::log( 'Entry categories (by entry sessions):' );
			$rows = array_map( array( $this, 'category_row' ), (array) ( $result['by_category'] ?? array() ) );
			if ( empty( $rows ) ) {
				WP_CLI::log( '  (no entry categories in window)' );
				return;
			}
			Utils\format_items( 'table', $rows, array( 'category', 'entry_sessions', 'same_any', 'return_any', 'returned', 'reached_any' ) );
		}

		if ( ! empty( $result['note'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( $result['note'] );
		}
	}

	/**
	 * Build a typed machine-readable row for an entry article.
	 *
	 * @param array $a Article result row.
	 * @return array<string, int|float|string>
	 */
	private function article_machine_row( $a ) {
		return array_merge(
			array(
				'post_id' => (int) ( $a['post_id'] ?? 0 ),
				'title'   => (string) ( $a['title'] ?? '(unknown)' ),
				'url'     => (string) ( $a['url'] ?? '' ),
				'path'    => (string) ( $a['path'] ?? '' ),
			),
			$this->machine_metrics( $a )
		);
	}

	/**
	 * Build a typed machine-readable row for an entry category.
	 *
	 * @param array $c Category result row.
	 * @return array<string, int|float|string>
	 */
	private function category_machine_row( $c ) {
		return array_merge(
			array(
				'term_id'  => (int) ( $c['term_id'] ?? 0 ),
				'category' => (string) ( $c['category'] ?? '' ),
			),
			$this->machine_metrics( $c )
		);
	}

	/**
	 * Flatten typed ability metrics for deterministic JSON and CSV rows.
	 *
	 * @param array $row Ability result row.
	 * @return array<string, int|float>
	 */
	private function machine_metrics( $row ) {
		return array(
			'entry_sessions'           => (int) ( $row['entry_sessions'] ?? 0 ),
			'reached_any'              => (int) ( $row['reached_any'] ?? 0 ),
			'reached_any_rate'         => (float) ( $row['reached_any_rate'] ?? 0 ),
			'reached_any_same_count'   => (int) ( $row['reached_any_same_count'] ?? 0 ),
			'same_session_rate'        => (float) ( $row['same_session']['any'] ?? 0 ),
			'reached_any_return_count' => (int) ( $row['reached_any_return_count'] ?? 0 ),
			'return_rate'              => (float) ( $row['return']['any'] ?? 0 ),
			'returned_count'           => (int) ( $row['returned_count'] ?? 0 ),
			'returned_rate'            => (float) ( $row['returned_rate'] ?? 0 ),
		);
	}

	/**
	 * Build a display row for an entry article.
	 *
	 * @param array $a Article result row.
	 * @return array<string, mixed>
	 */
	private function article_row( $a ) {
		return array(
			'title'          => $this->truncate( (string) ( $a['title'] ?? '(unknown)' ), 56 ),
			'entry_sessions' => number_format( (int) ( $a['entry_sessions'] ?? 0 ) ),
			'same_any'       => $this->pct( $a['same_session']['any'] ?? 0 ) . '%',
			'return_any'     => $this->pct( $a['return']['any'] ?? 0 ) . '%',
			'returned'       => $this->pct( $a['returned_rate'] ?? 0 ) . '%',
			'reached_any'    => $this->pct( $a['reached_any_rate'] ?? 0 ) . '%',
		);
	}

	/**
	 * Build a display row for an entry category.
	 *
	 * @param array $c Category result row.
	 * @return array<string, mixed>
	 */
	private function category_row( $c ) {
		return array(
			'category'       => $this->truncate( (string) ( $c['category'] ?? '' ), 40 ),
			'entry_sessions' => number_format( (int) ( $c['entry_sessions'] ?? 0 ) ),
			'same_any'       => $this->pct( $c['same_session']['any'] ?? 0 ) . '%',
			'return_any'     => $this->pct( $c['return']['any'] ?? 0 ) . '%',
			'returned'       => $this->pct( $c['returned_rate'] ?? 0 ) . '%',
			'reached_any'    => $this->pct( $c['reached_any_rate'] ?? 0 ) . '%',
		);
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

	/**
	 * Truncate a string for table display.
	 *
	 * @param string $text Text.
	 * @param int    $len  Max length.
	 * @return string
	 */
	private function truncate( $text, $len ) {
		if ( strlen( $text ) <= $len ) {
			return $text;
		}
		return substr( $text, 0, $len - 1 ) . '…';
	}
}
