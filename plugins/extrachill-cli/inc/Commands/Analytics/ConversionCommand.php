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
	 * [--return-observation-days=<days>]
	 * : Minimum completed days after an entry journey before it enters the
	 * denominator. Excludes late-window entries with unequal return opportunity.
	 * ---
	 * default: 7
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
	 *     wp extrachill analytics conversion --return-observation-days=14
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

		$days                    = max( 1, (int) ( $assoc_args['days'] ?? 28 ) );
		$session_gap_mins        = max( 1, (int) ( $assoc_args['session-gap-mins'] ?? 30 ) );
		$top_articles            = max( 1, (int) ( $assoc_args['top-articles'] ?? 25 ) );
		$min_entry_sessions      = max( 1, (int) ( $assoc_args['min-entry-sessions'] ?? 1 ) );
		$return_observation_days = max( 0, (int) ( $assoc_args['return-observation-days'] ?? 7 ) );
		$by                      = $assoc_args['by'] ?? 'category';
		$format                  = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'days'                    => $days,
				'session_gap_mins'        => $session_gap_mins,
				'top_articles'            => $top_articles,
				'min_entry_sessions'      => $min_entry_sessions,
				'return_observation_days' => $return_observation_days,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
			return;
		}

		if ( 'csv' === $format ) {
			Utils\format_items(
				$format,
				array( $this->csv_row( $result ) ),
				array_map( 'strval', array_keys( $result ) )
			);
			return;
		}

		$overall      = $result['overall'] ?? array();
		$period_label = "Last {$days} days";
		WP_CLI::log( sprintf( 'Cross-Surface Conversion Map — %s (%s)', $period_label, $result['period'] ?? '' ) );
		if ( ! empty( $result['since'] ) ) {
			WP_CLI::log( sprintf( 'Window (UTC): created_at >= %s  (as of %s)', $result['since'], $result['as_of'] ?? '' ) );
		}
		WP_CLI::log(
			sprintf(
				'Entry: blog %d editorial articles → platform surfaces %s. Session gap: %dm. Return observation: %d completed days.',
				(int) ( $result['entry_blog_id'] ?? 1 ),
				wp_json_encode( $result['platform_blogs'] ?? array() ),
				$session_gap_mins,
				(int) ( $result['return_observation_days'] ?? $return_observation_days )
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
		$this->display_outcomes( (array) ( $result['outcomes'] ?? array() ) );

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
	 * Render outcome attribution without combining its independent lenses.
	 *
	 * @param array $outcomes Ability outcome envelope.
	 * @return void
	 */
	private function display_outcomes( array $outcomes ) {
		$overall  = (array) ( $outcomes['overall'] ?? array() );
		$coverage = (array) ( $outcomes['coverage'] ?? array() );

		if ( empty( $overall ) ) {
			return;
		}

		$event_rows   = array();
		$direct_rows  = array();
		$journey_rows = array();

		foreach ( $overall as $type => $attribution ) {
			$type_coverage = (array) ( $coverage[ $type ] ?? array() );
			$direct        = (array) ( $attribution['direct_source'] ?? array() );
			$journey       = (array) ( $attribution['visitor_journey'] ?? array() );
			$outcome       = str_replace( '_', ' ', (string) $type );

			$event_rows[]   = array(
				'outcome'       => $outcome,
				'stored'        => $this->count( $type_coverage['stored_events'] ?? 0 ),
				'auto_excluded' => $this->count( $type_coverage['automatic_registration_excluded'] ?? 0 ),
				'deduplicated'  => $this->count( $type_coverage['deduplicated_outcomes'] ?? 0 ),
				'duplicates'    => $this->count( $type_coverage['duplicate_events'] ?? 0 ),
			);
			$direct_rows[]  = array(
				'outcome'           => $outcome,
				'count'             => $this->count( $direct['count'] ?? null ),
				'coverage'          => (string) ( $direct['coverage_status'] ?? 'unknown' ),
				'with_source'       => $this->count( $type_coverage['with_source_url'] ?? 0 ),
				'attributed'        => $this->count( $type_coverage['direct_source_attributed'] ?? 0 ),
				'missing_source'    => $this->count( $type_coverage['missing_source_url'] ?? 0 ),
				'unresolved_source' => $this->count( $type_coverage['unresolved_source_url'] ?? 0 ),
			);
			$journey_rows[] = array(
				'outcome'          => $outcome,
				'same_session'     => $this->count( $journey['same_session_count'] ?? null ),
				'later_session'    => $this->count( $journey['later_session_count'] ?? null ),
				'coverage'         => (string) ( $journey['coverage_status'] ?? 'unknown' ),
				'with_identity'    => $this->count( $type_coverage['with_visitor_identity'] ?? 0 ),
				'attributed'       => $this->count( $type_coverage['visitor_journey_attributed'] ?? 0 ),
				'missing_identity' => $this->count( $type_coverage['missing_visitor_identity'] ?? 0 ),
				'no_entry_journey' => $this->count( $type_coverage['identity_without_eligible_journey'] ?? 0 ),
				'before_entry'     => $this->count( $type_coverage['outcome_before_entry'] ?? 0 ),
			);
		}

		WP_CLI::log( 'Outcome event coverage (before attribution):' );
		Utils\format_items( 'table', $event_rows, array( 'outcome', 'stored', 'auto_excluded', 'deduplicated', 'duplicates' ) );
		WP_CLI::log( 'Direct-source lens (source URL resolves to a published entry article):' );
		Utils\format_items( 'table', $direct_rows, array( 'outcome', 'count', 'coverage', 'with_source', 'attributed', 'missing_source', 'unresolved_source' ) );
		WP_CLI::log( 'Visitor-journey lens (identified outcome follows a mature entry journey):' );
		Utils\format_items( 'table', $journey_rows, array( 'outcome', 'same_session', 'later_session', 'coverage', 'with_identity', 'attributed', 'missing_identity', 'no_entry_journey', 'before_entry' ) );
		WP_CLI::log( 'Lens counts are independent and may describe the same outcome; do not add them as unique people.' );
		WP_CLI::log( '' );
	}

	/**
	 * Format an outcome count for human display.
	 *
	 * @param mixed $value Count or null when the lens is not instrumented.
	 * @return string
	 */
	private function count( $value ) {
		return null === $value ? 'n/a' : number_format( (int) $value );
	}

	/**
	 * Preserve the complete response envelope in a deterministic CSV row.
	 *
	 * Nested values are JSON-encoded because CSV cells cannot represent arrays.
	 *
	 * @param array $result Ability result.
	 * @return array<string, mixed>
	 */
	private function csv_row( array $result ) {
		foreach ( $result as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$result[ $key ] = wp_json_encode( $value );
			}
		}

		return $result;
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
