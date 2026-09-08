<?php
/**
 * Event genre projection helpers.
 *
 * Pure static logic for the event genre projection (Extra-Chill/extrachill-events#828):
 * an event's genres are the union of its artist terms' genres, capped, kept in
 * sync as a materialized projection. No WordPress runtime dependencies live in
 * this class so the rules stay unit-testable in isolation; the hooks, triggers,
 * and writes live in inc/core/genre-sync.php.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers for the event genre projection and the import lockout.
 */
final class GenreSync {

	/**
	 * Maximum genres materialized on a single event.
	 *
	 * Per-artist vocab cap is 3 (network); the event union spans several
	 * artists, so the event cap sits above the per-artist cap.
	 */
	public const EVENT_GENRE_CAP = 5;

	/**
	 * Artist fan-out above this many events is queued, never inline.
	 */
	public const FANOUT_INLINE_THRESHOLD = 200;

	/**
	 * The post type the genre import lockout applies to.
	 *
	 * Events derive genre from their performers; the import path can never
	 * write it (Extra-Chill/extrachill-events#30 §3).
	 */
	public const LOCKED_POST_TYPE = 'data_machine_events';

	/**
	 * Union per-artist genre sets into one capped, first-seen-ordered slug list.
	 *
	 * Term order (array key order) breaks ties: an artist's own order wins
	 * over later artists.
	 *
	 * @param array<int|string, mixed> $genres_by_term_id Map of artist term ID => genre slug list.
	 * @param int                      $cap               Maximum slugs returned.
	 * @return string[] Unique canonical slugs in first-seen order, at most $cap.
	 */
	public static function union_genres( array $genres_by_term_id, int $cap = self::EVENT_GENRE_CAP ): array {
		$cap = max( 1, $cap );

		$union = array();
		foreach ( $genres_by_term_id as $slugs ) {
			if ( ! is_array( $slugs ) ) {
				continue;
			}

			foreach ( $slugs as $slug ) {
				if ( ! is_string( $slug ) ) {
					continue;
				}

				$slug = sanitize_title( $slug );
				if ( '' === $slug || in_array( $slug, $union, true ) ) {
					continue;
				}

				$union[] = $slug;
				if ( count( $union ) >= $cap ) {
					return $union;
				}
			}
		}

		return $union;
	}

	/**
	 * Decode a stored `_genres` termmeta value into a clean slug list.
	 *
	 * The artist-side contract stores a JSON array of slugs; plain arrays and
	 * comma-separated strings are accepted so a malformed mirror degrades to
	 * whatever can still be parsed instead of failing the whole sync.
	 *
	 * @param mixed $raw Stored termmeta value.
	 * @return string[] Slug list (sanitized, deduplicated, original order).
	 */
	public static function decode_genre_slugs( $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : ( is_string( $decoded ) ? $decoded : $raw );
		}

		if ( ! is_array( $raw ) ) {
			$raw = is_string( $raw ) && '' !== $raw ? explode( ',', $raw ) : array();
		}

		$slugs = array();
		foreach ( $raw as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}

			$slug = sanitize_title( $slug );
			if ( '' === $slug || in_array( $slug, $slugs, true ) ) {
				continue;
			}

			$slugs[] = $slug;
		}

		return $slugs;
	}

	/**
	 * Whether the import path is locked out of writing this taxonomy/post type pair.
	 *
	 * @param string      $taxonomy  Taxonomy being assigned.
	 * @param string|null $post_type Post type receiving the assignment.
	 * @return bool True when the assignment must be refused.
	 */
	public static function is_import_locked_taxonomy( string $taxonomy, ?string $post_type ): bool {
		return 'genre' === $taxonomy && self::LOCKED_POST_TYPE === $post_type;
	}

	/**
	 * Strip the genre parameter from the upsert_event tool schema.
	 *
	 * Runs on the datamachine_tools registry: the generic taxonomy parameter
	 * builder cannot remove a parameter once exposed, so the registered tool
	 * definition is corrected here. Removes the property and any required[]
	 * entry; everything else passes through untouched.
	 *
	 * @param array $tools Raw datamachine_tools registry.
	 * @return array Registry with genre removed from the upsert_event schema.
	 */
	public static function strip_genre_tool_param( array $tools ): array {
		if ( ! isset( $tools['upsert_event']['parameters'] ) || ! is_array( $tools['upsert_event']['parameters'] ) ) {
			return $tools;
		}

		$parameters = $tools['upsert_event']['parameters'];

		if ( isset( $parameters['properties']['genre'] ) ) {
			unset( $parameters['properties']['genre'] );
		}

		if ( isset( $parameters['required'] ) && is_array( $parameters['required'] ) ) {
			$parameters['required'] = array_values(
				array_filter(
					$parameters['required'],
					static fn( $name ) => 'genre' !== $name
				)
			);
		}

		$tools['upsert_event']['parameters'] = $parameters;

		return $tools;
	}
}
