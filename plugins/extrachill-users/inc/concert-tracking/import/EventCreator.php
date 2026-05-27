<?php
/**
 * Create internal `data_machine_events` posts from ExternalEvent payloads
 * when the matcher finds nothing to link to.
 *
 * Why this exists:
 *
 * The whole point of importing a user's setlist.fm / phish.net history is to
 * bring their attendance record into Extra Chill. Skipping shows we don't
 * already have = silently losing data the user wanted us to import. Every
 * other event-import path on the platform (Ticketmaster, Dice.fm,
 * UniversalWebScraper) creates events. Concert-import does the same.
 *
 * The creator runs from the orchestrator after EventMatcher returns null AND
 * after an external_id idempotency lookup against `_dm_import_external_id`
 * post_meta also returns null. It always operates inside a
 * `switch_to_blog( events_blog )` context — managed by the orchestrator.
 *
 * What the creator does NOT do:
 *
 * - Geocoding: venue addresses are written as `_venue_address` meta and the
 *   data-machine-events background sweep picks them up. Inline geocoding
 *   would block the import on a slow / unavailable Nominatim — not worth the
 *   latency cost for an import that can take days anyway.
 * - Schedule / rescheduling: the orchestrator owns Action Scheduler state.
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.14.0
 */

namespace ExtraChill\Users\Concert_Import;

defined( 'ABSPATH' ) || exit;

final class EventCreator {

	public const META_IMPORT_SOURCE      = '_dm_import_source';
	public const META_IMPORT_EXTERNAL_ID = '_dm_import_external_id';

	/**
	 * Look up a previously-imported event by stable (source, external_id).
	 *
	 * Used by the orchestrator BEFORE running EventMatcher's similarity-based
	 * lookup. external_id is more authoritative than venue/artist similarity
	 * because it's a direct round-trip identifier from the source. Re-imports
	 * are no-ops as long as this check fires first.
	 *
	 * Caller must already be inside the events-blog switch_to_blog context.
	 *
	 * @param string $source_slug Source slug stored in `_dm_import_source`
	 *                            (e.g. 'setlist-fm', 'phish-net').
	 * @param string $external_id Stable external identifier from the source.
	 * @return int|null Post ID, or null when no matching post exists.
	 */
	public static function find_by_external_id( string $source_slug, string $external_id ): ?int {
		$source_slug = trim( $source_slug );
		$external_id = trim( $external_id );

		if ( '' === $source_slug || '' === $external_id ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'data_machine_events',
				'post_status'            => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Lookup must hit meta; the (source, external_id) pair is unique enough that the query is fast in practice.
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => self::META_IMPORT_SOURCE,
						'value' => $source_slug,
					),
					array(
						'key'   => self::META_IMPORT_EXTERNAL_ID,
						'value' => $external_id,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		return (int) $query->posts[0];
	}

	/**
	 * Create a `data_machine_events` post from an ExternalEvent.
	 *
	 * Caller must already be inside the events-blog switch_to_blog context.
	 * Returns the new post ID on success, or null on failure.
	 *
	 * Side effects:
	 *   - Inserts a `datamachine_event_dates` row at midnight UTC on the
	 *     source-reported date (the source typically only carries a calendar
	 *     date, not a time).
	 *   - Find-or-creates the venue term and assigns it to the post. Uses
	 *     `DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue()` when
	 *     available so address-based dedupe matches the existing creation paths
	 *     (Ticketmaster, Dice.fm, scraper) — and falls back to `wp_insert_term`
	 *     when the helper is not loaded.
	 *   - Find-or-creates the artist term and assigns it. The artist taxonomy
	 *     has no public find-or-create helper, so we use `wp_insert_term`
	 *     directly with smart-lookup variations.
	 *   - Stamps `_dm_import_source` + `_dm_import_external_id` for audit and
	 *     idempotency on re-import.
	 *
	 * @param ExternalEvent $event       Normalized source payload.
	 * @param string        $source_slug Source slug (e.g. 'setlist-fm').
	 * @return int|null Post ID on success, null on failure.
	 */
	public static function create( ExternalEvent $event, string $source_slug ): ?int {
		if ( ! $event->is_matchable() ) {
			return null;
		}

		$title = self::format_title( $event );
		if ( '' === $title ) {
			return null;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			do_action(
				'datamachine_log',
				'error',
				'Concert import: failed to create event post',
				array(
					'source_slug' => $source_slug,
					'event'       => $event->label(),
					'error'       => is_wp_error( $post_id ) ? $post_id->get_error_message() : 'unknown',
				)
			);
			return null;
		}

		$post_id = (int) $post_id;

		// Stamp audit + idempotency meta FIRST so even a partial creation
		// (no venue/artist) is still discoverable by external_id and won't be
		// re-created on the next pass.
		update_post_meta( $post_id, self::META_IMPORT_SOURCE, sanitize_text_field( $source_slug ) );
		if ( '' !== $event->source_id ) {
			update_post_meta( $post_id, self::META_IMPORT_EXTERNAL_ID, sanitize_text_field( $event->source_id ) );
		}

		// Insert the event-dates row. The source only carries a calendar
		// date — we anchor at midnight UTC on that date. Downstream date
		// rendering treats this as an all-day show.
		$start_datetime = $event->date . ' 00:00:00';
		if ( class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ) {
			\DataMachineEvents\Core\EventDatesTable::upsert( $post_id, $start_datetime, null, 'publish' );
		}

		// Find-or-create the venue.
		$venue_term_id = self::ensure_venue_term( $event );
		if ( $venue_term_id ) {
			wp_set_object_terms( $post_id, array( (int) $venue_term_id ), 'venue', false );
		}

		// Find-or-create the artist.
		$artist_term_id = self::ensure_artist_term( $event );
		if ( $artist_term_id ) {
			wp_set_object_terms( $post_id, array( (int) $artist_term_id ), 'artist', false );
		}

		do_action(
			'datamachine_log',
			'info',
			'Concert import: created event from external payload',
			array(
				'post_id'     => $post_id,
				'source_slug' => $source_slug,
				'external_id' => $event->source_id,
				'title'       => $title,
			)
		);

		return $post_id;
	}

	/**
	 * Build a human-readable event title from the external payload.
	 *
	 * Format: "<Headliner> at <Venue> · <Pretty Date>". Falls back gracefully
	 * if any field is missing. Mirrors how existing concert-stats UI labels
	 * events.
	 */
	private static function format_title( ExternalEvent $event ): string {
		$pretty_date = '';
		if ( '' !== $event->date ) {
			$ts          = strtotime( $event->date . ' 00:00:00 UTC' );
			$pretty_date = $ts ? gmdate( 'F j, Y', $ts ) : $event->date;
		}

		$parts = array();
		if ( '' !== $event->headliner ) {
			$parts[] = $event->headliner;
		}
		if ( '' !== $event->venue_name ) {
			$parts[] = ( $parts ? 'at ' : '' ) . $event->venue_name;
		}

		$prefix = implode( ' ', $parts );
		if ( '' === $prefix ) {
			$prefix = 'Concert';
		}

		return '' !== $pretty_date ? $prefix . ' · ' . $pretty_date : $prefix;
	}

	/**
	 * Find or create the venue term for this event.
	 *
	 * Prefers data-machine-events' canonical helper (address-based dedupe +
	 * smart name variations), and falls back to wp_insert_term when the
	 * events plugin's class is not loaded.
	 *
	 * Sets `_venue_address` meta on the term when address-ish fields are
	 * present in the payload so the background geocoding sweep can fill in
	 * lat/lng without blocking the import.
	 */
	private static function ensure_venue_term( ExternalEvent $event ): ?int {
		if ( '' === $event->venue_name ) {
			return null;
		}

		$venue_data = array(
			'city'    => $event->city,
			'state'   => $event->state,
			'country' => $event->country,
			'address' => '',
		);

		if ( class_exists( '\\DataMachineEvents\\Core\\Venue_Taxonomy' ) ) {
			$result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue(
				$event->venue_name,
				$venue_data
			);
			$term_id = isset( $result['term_id'] ) ? (int) $result['term_id'] : 0;
		} else {
			$existing = get_term_by( 'name', $event->venue_name, 'venue' );
			if ( $existing && ! is_wp_error( $existing ) ) {
				$term_id = (int) $existing->term_id;
			} else {
				$inserted = wp_insert_term( $event->venue_name, 'venue' );
				$term_id  = is_wp_error( $inserted ) ? 0 : (int) ( $inserted['term_id'] ?? 0 );
			}
		}

		if ( ! $term_id ) {
			return null;
		}

		// Mark for background geocoding via the existing `_venue_address`
		// pattern when we have any address-ish fields. The sweep treats
		// city/state/country as enough to seed a Nominatim query.
		$address_line = trim( implode( ', ', array_filter( array( $event->city, $event->state, $event->country ) ) ) );
		if ( '' !== $address_line ) {
			$existing_address = (string) get_term_meta( $term_id, '_venue_address', true );
			if ( '' === $existing_address ) {
				update_term_meta( $term_id, '_venue_address', $address_line );
			}
		}

		return $term_id;
	}

	/**
	 * Find or create the artist term for this event.
	 *
	 * The artist taxonomy doesn't ship a public find-or-create helper, so we
	 * implement the same smart-lookup pattern locally (exact name, then with /
	 * without "The " prefix).
	 */
	private static function ensure_artist_term( ExternalEvent $event ): ?int {
		if ( '' === $event->headliner ) {
			return null;
		}

		$name = $event->headliner;

		$existing = get_term_by( 'name', $name, 'artist' );

		if ( ! $existing ) {
			$alt = ( stripos( $name, 'The ' ) === 0 ) ? substr( $name, 4 ) : 'The ' . $name;
			if ( $alt ) {
				$existing = get_term_by( 'name', $alt, 'artist' );
			}
		}

		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		$inserted = wp_insert_term( $name, 'artist' );
		if ( is_wp_error( $inserted ) ) {
			return null;
		}

		return isset( $inserted['term_id'] ) ? (int) $inserted['term_id'] : null;
	}
}
