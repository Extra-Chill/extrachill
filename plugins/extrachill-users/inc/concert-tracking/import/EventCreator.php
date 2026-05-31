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
 * Canonical creation path (extrachill-users#81):
 *
 * Creation routes through the platform's canonical content primitive,
 * `datamachine/upsert-post`, instead of a raw `wp_insert_post()`. This is the
 * same primitive the canonical `upsert_event` handler delegates to, so import
 * events stop being second-class citizens:
 *
 * - The post carries a real `data-machine-events/event-details` block, so the
 *   `event-dates-sync` listener (on `save_post`) writes the
 *   `datamachine_event_dates` row from the block's `startDate` — no manual
 *   EventDatesTable::upsert.
 * - `datamachine/upsert-post` gives content-hash idempotency + provenance for
 *   free, on top of our own (source, external_id) idempotency stamp.
 * - Imported created-events publish directly to the calendar. My Shows is
 *   intentionally both historical and forward-looking, and the public
 *   calendar/archive default to upcoming-only views (UpcomingFilter), so
 *   historical imports add timeline depth without flooding default surfaces.
 *   Per-event IndexNow pings are suppressed for the import batch by the
 *   orchestrator (datamachine_indexnow_skip_auto_submit), avoiding thousands
 *   of synchronous outbound pings; the sitemap still advertises events on the
 *   normal crawl cadence.
 * - After taxonomy assignment we fire `datamachine_event_taxonomy_processed`,
 *   so extrachill-events' location-normalizer resolves the `location` (city)
 *   term from the venue's `_venue_city` meta. Without this, imported events
 *   carried no location term and contributed zero to My Shows "top cities" /
 *   "unique cities" stats (the bug #81 fixes).
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

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/upsert-post' ) : null;
		if ( ! $ability ) {
			do_action(
				'datamachine_log',
				'error',
				'Concert import: datamachine/upsert-post ability unavailable',
				array(
					'source_slug' => $source_slug,
					'event'       => $event->label(),
				)
			);
			return null;
		}

		// Find-or-create the venue + artist terms up front. The venue helper
		// (Venue_Taxonomy::find_or_create_venue) does robust 4-layer dedup and
		// stamps `_venue_city` / `_venue_state` meta on the term — which the
		// location-normalizer later reads to resolve the city term.
		$venue_term_id  = self::ensure_venue_term( $event );
		$artist_term_id = self::ensure_artist_term( $event );

		$taxonomies = array();
		if ( $venue_term_id ) {
			$taxonomies['venue'] = array( (int) $venue_term_id );
		}
		if ( $artist_term_id ) {
			$taxonomies['artist'] = array( (int) $artist_term_id );
		}

		// Build the canonical event-details block as post content. The
		// `event-dates-sync` listener (save_post) parses this block's
		// `startDate` and writes the `datamachine_event_dates` row — the same
		// path the canonical upsert_event handler relies on. The source only
		// carries a calendar date, so we leave startTime empty (all-day).
		$content = self::build_event_block_content( $event );

		$result = $ability->execute(
			array(
				'post_type'      => 'data_machine_events',
				'title'          => $title,
				'content'        => $content,
				'content_format' => 'blocks',
				// Imported created-events publish directly to the calendar.
				// My Shows is intentionally both historical and forward-looking,
				// and the public calendar/archive default to upcoming-only views
				// (UpcomingFilter), so historical imports add timeline depth
				// without flooding the default surfaces. Per-event IndexNow pings
				// are suppressed during the import batch by the orchestrator.
				'post_status'    => 'publish',
				'taxonomies'     => $taxonomies,
				// Stamp audit + idempotency meta. Our own (source, external_id)
				// pair is the authoritative re-import guard (checked by the
				// orchestrator before this runs), on top of upsert-post's own
				// content-hash idempotency.
				'meta_input'     => self::import_meta( $event, $source_slug ),
			)
		);

		if ( ! is_array( $result ) || empty( $result['success'] ) || empty( $result['post_id'] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Concert import: datamachine/upsert-post failed to create event',
				array(
					'source_slug' => $source_slug,
					'event'       => $event->label(),
					'error'       => is_array( $result ) ? ( $result['error'] ?? $result['message'] ?? 'unknown' ) : 'non-array result',
				)
			);
			return null;
		}

		$post_id = (int) $result['post_id'];

		// Fire the canonical post-taxonomy hook so extrachill-events'
		// location-normalizer runs and resolves the `location` (city) term
		// from the venue's `_venue_city` meta. This is what makes imported
		// events count toward My Shows "top cities" / "unique cities".
		do_action( 'datamachine_event_taxonomy_processed', $post_id );

		do_action(
			'datamachine_log',
			'info',
			'Concert import: created event via canonical upsert-post',
			array(
				'post_id'     => $post_id,
				'source_slug' => $source_slug,
				'external_id' => $event->source_id,
				'title'       => $title,
				'action'      => $result['action'] ?? '',
			)
		);

		return $post_id;
	}

	/**
	 * Build the audit + idempotency meta for an imported event.
	 *
	 * @param ExternalEvent $event       Normalized source payload.
	 * @param string        $source_slug Source slug (e.g. 'setlist-fm').
	 * @return array<string, string> Meta input for datamachine/upsert-post.
	 */
	private static function import_meta( ExternalEvent $event, string $source_slug ): array {
		$meta = array(
			self::META_IMPORT_SOURCE => sanitize_text_field( $source_slug ),
		);

		if ( '' !== $event->source_id ) {
			$meta[ self::META_IMPORT_EXTERNAL_ID ] = sanitize_text_field( $event->source_id );
		}

		return $meta;
	}

	/**
	 * Build the `data-machine-events/event-details` block markup for an
	 * imported event.
	 *
	 * Mirrors the block shape produced by the canonical upsert_event handler
	 * (EventUpsert::generate_event_block_content): a single event-details block
	 * carrying the date/venue/performer attributes that downstream sync hooks
	 * read. The source carries only a calendar date, so `startTime` is omitted
	 * (treated as all-day).
	 *
	 * @param ExternalEvent $event Normalized source payload.
	 * @return string Serialized block markup.
	 */
	private static function build_event_block_content( ExternalEvent $event ): string {
		$attrs = array(
			'startDate'      => $event->date,
			'venue'          => $event->venue_name,
			'performer'      => $event->headliner,
			'performerType'  => 'PerformingGroup',
			'showVenue'      => true,
			'showPrice'      => false,
			'showTicketLink' => false,
		);

		$attrs = array_filter(
			$attrs,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		// Re-assert the boolean display flags stripped by array_filter.
		$attrs['showVenue']      = true;
		$attrs['showPrice']      = false;
		$attrs['showTicketLink'] = false;

		$block_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE );

		return '<!-- wp:data-machine-events/event-details ' . $block_json . ' -->' . "\n" .
			'<div class="wp-block-data-machine-events-event-details"></div>' . "\n" .
			'<!-- /wp:data-machine-events/event-details -->';
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
	 * Delegates to the canonical Data Machine term resolver
	 * (`datamachine/resolve-term`) with fuzzy matching enabled, so artist
	 * names dedup on normalized comparison (case/punctuation/article/accent
	 * insensitive) — e.g. "Tyler, the Creator" resolves to an existing
	 * "Tyler the Creator" term instead of creating a duplicate. The resolver
	 * is taxonomy-agnostic core, so no artist-awareness leaks into the generic
	 * events layer (extrachill-events#144).
	 */
	private static function ensure_artist_term( ExternalEvent $event ): ?int {
		if ( '' === $event->headliner ) {
			return null;
		}

		if ( ! class_exists( '\\DataMachine\\Abilities\\Taxonomy\\ResolveTermAbility' ) ) {
			return null;
		}

		$result = \DataMachine\Abilities\Taxonomy\ResolveTermAbility::resolve(
			$event->headliner,
			'artist',
			true,  // create if not found
			array(),
			true   // fuzzy: normalized-name dedup
		);

		if ( empty( $result['success'] ) || empty( $result['term_id'] ) ) {
			return null;
		}

		return (int) $result['term_id'];
	}
}
