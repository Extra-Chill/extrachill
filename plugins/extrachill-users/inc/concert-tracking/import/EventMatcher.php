<?php
/**
 * Match an ExternalEvent against the internal `data_machine_events` post type.
 *
 * Strategy:
 *  1. Use the events blog's `datamachine_event_dates` table to find all
 *     events whose `start_datetime` falls on the external event's date
 *     (inclusive day window).
 *  2. Score each candidate by combined venue-name similarity + headliner
 *     similarity (via similar_text() percentage).
 *  3. Return the highest-scoring candidate above the configured threshold,
 *     or null.
 *
 * The matcher runs in the events-blog context (callers switch_to_blog before
 * invoking). All taxonomy lookups happen against the events blog's tables.
 *
 * Documented threshold: 85% on the combined score. Venue-name similarity is
 * weighted higher (0.6) than headliner similarity (0.4) because venue+date
 * uniquely identifies most shows already, while headliner names drift across
 * sources due to formatting (feat./with/&).
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.13.0
 */

namespace ExtraChill\Users\Concert_Import;

defined( 'ABSPATH' ) || exit;

final class EventMatcher {

	/**
	 * Default minimum combined similarity score (0-100).
	 */
	public const DEFAULT_THRESHOLD = 85;

	/**
	 * Events blog ID (data_machine_events lives here).
	 *
	 * @var int
	 */
	private int $blog_id;

	/**
	 * Minimum combined score to accept a match.
	 *
	 * @var int
	 */
	private int $threshold;

	public function __construct( int $blog_id = 0, int $threshold = self::DEFAULT_THRESHOLD ) {
		if ( ! $blog_id ) {
			$blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 7;
			$blog_id = (int) apply_filters( 'extrachill_users_events_blog_id', $blog_id );
		}
		$this->blog_id   = $blog_id;
		$this->threshold = $threshold;
	}

	public function blog_id(): int {
		return $this->blog_id;
	}

	public function threshold(): int {
		return $this->threshold;
	}

	/**
	 * Attempt to match an ExternalEvent to an internal post.
	 *
	 * @param ExternalEvent $event
	 * @return int|null Internal post ID, or null when no candidate clears the threshold.
	 */
	public function match( ExternalEvent $event ): ?int {
		if ( ! $event->is_matchable() ) {
			return null;
		}

		$candidates = $this->find_candidates_for_date( $event->date );
		if ( empty( $candidates ) ) {
			return null;
		}

		$switched     = false;
		$current_blog = get_current_blog_id();
		if ( $current_blog !== $this->blog_id ) {
			switch_to_blog( $this->blog_id );
			$switched = true;
		}

		try {
			$best_score = 0;
			$best_id    = null;

			foreach ( $candidates as $candidate_post_id ) {
				$score = $this->score_candidate( $candidate_post_id, $event );
				if ( $score > $best_score ) {
					$best_score = $score;
					$best_id    = $candidate_post_id;
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		if ( $best_id && $best_score >= $this->threshold ) {
			return (int) $best_id;
		}

		return null;
	}

	/**
	 * Return all candidate event post IDs whose start_datetime falls on $date.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return int[]
	 */
	private function find_candidates_for_date( string $date ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $this->blog_id );
		$table  = $prefix . 'datamachine_event_dates';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->get_blog_prefix
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$table}
				WHERE DATE(start_datetime) = %s
				  AND post_status = 'publish'",
				$date
			)
		);
		// phpcs:enable

		return array_map( 'intval', $rows ? $rows : array() );
	}

	/**
	 * Score a candidate post against an external event.
	 *
	 * Returns a value in [0, 100] — combined weighted similarity of the
	 * venue name and headliner artist.
	 *
	 * Must be called within the events-blog switch_to_blog context.
	 */
	private function score_candidate( int $post_id, ExternalEvent $event ): int {
		$venue_terms  = wp_get_post_terms( $post_id, 'venue' );
		$artist_terms = wp_get_post_terms( $post_id, 'artist' );

		$venue_score   = 0;
		$artist_score  = 0;
		$venue_weight  = 0.6;
		$artist_weight = 0.4;

		if ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) {
			$best = 0;
			foreach ( $venue_terms as $term ) {
				$s = self::name_similarity( $term->name, $event->venue_name );
				if ( $s > $best ) {
					$best = $s;
				}
			}
			$venue_score = $best;
		}

		if ( '' === $event->headliner ) {
			// No headliner reported by source — give artist axis full
			// credit so we don't penalize valid venue+date matches.
			$artist_score = 100;
		} elseif ( ! is_wp_error( $artist_terms ) && ! empty( $artist_terms ) ) {
			$best = 0;
			foreach ( $artist_terms as $term ) {
				$s = self::name_similarity( $term->name, $event->headliner );
				if ( $s > $best ) {
					$best = $s;
				}
			}
			$artist_score = $best;
		}

		$combined = ( $venue_score * $venue_weight ) + ( $artist_score * $artist_weight );
		return (int) round( $combined );
	}

	/**
	 * Normalize a name for similarity comparison.
	 *
	 * Lowercase, strip diacritics where ASCII fallback is sensible, collapse
	 * whitespace, drop common venue noise words (the, a, an).
	 */
	public static function normalize_name( string $name ): string {
		$name = wp_strip_all_tags( $name );
		$name = function_exists( 'remove_accents' ) ? remove_accents( $name ) : $name;
		$name = strtolower( $name );
		// Drop punctuation we don't want to penalize.
		$name = preg_replace( '/[\'`"\.,&]/', '', $name );
		// Collapse whitespace.
		$name = preg_replace( '/\s+/', ' ', $name );
		// Trim leading articles.
		$name = preg_replace( '/^(the |a |an )/i', '', (string) $name );
		return trim( (string) $name );
	}

	/**
	 * Compute a 0-100 similarity score between two names.
	 *
	 * Uses similar_text() in percent mode after normalization. Identical
	 * strings score 100; completely disjoint strings score 0.
	 */
	public static function name_similarity( string $a, string $b ): int {
		$a = self::normalize_name( $a );
		$b = self::normalize_name( $b );

		if ( '' === $a || '' === $b ) {
			return 0;
		}
		if ( $a === $b ) {
			return 100;
		}

		$percent = 0.0;
		similar_text( $a, $b, $percent );
		return (int) round( $percent );
	}
}
