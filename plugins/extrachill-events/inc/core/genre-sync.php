<?php
/**
 * Event genre projection: resolve artist genres, materialize them on events.
 *
 * Genre is an artist fact (Extra-Chill/extrachill-events#30). Events carry it
 * as a materialized projection so the calendar filter can count and query it:
 * an event's genres are the union of its artist terms' genres, capped at
 * GenreSync::EVENT_GENRE_CAP, written with replace semantics so removals
 * propagate. There is no per-event genre path and no per-event AI fallback —
 * an event with no genre is an event whose performers have no genre yet.
 *
 * Resolution order per events-site artist term
 * (Extra-Chill/extrachill-events#828):
 *  1. Local termmeta `_genres` (JSON slug array) on the events-site artist
 *     term — written by the classify-genre step for unclaimed artists.
 *  2. Bridge: resolve the term by slug to the canonical main-site artist term
 *     through `extrachill_network_resolve_term_identity()` and read that
 *     term's `_genres` mirror (written by the artist platform).
 *
 * Every network call is guarded: when extrachill-network predates the genre
 * module (or the multisite blog map is unavailable), sync fails closed — a
 * single logged warning, no partial writes — and the sync-genres CLI aborts.
 *
 * Triggers:
 *  - `datamachine_event_taxonomy_processed` — after an import upsert assigns
 *    artist terms, that event is re-synced.
 *  - `added_term_meta` / `updated_term_meta` / `deleted_term_meta` for key
 *    `_genres` on an `artist` term — fan out to the artist's events. On the
 *    events site the local term fans out directly; on the main site (where
 *    the canonical artist term lives) the events-site term is resolved by
 *    slug first. The main-site branch only runs when this plugin is active
 *    on the main site; today it is events-site only, so main-site mirrors
 *    propagate through the sync-genres CLI or the next event upsert until
 *    the plugin is network-activated.
 *  - Fan-out for an artist with more than GenreSync::FANOUT_INLINE_THRESHOLD
 *    events is queued through Action Scheduler (shipped by data-machine,
 *    which this plugin requires), never inline; wp_cron is the fallback.
 *
 * @package ExtraChillEvents
 * @since 0.65.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/GenreSync.php';

/** Action Scheduler hook that syncs one artist term's events. */
const EXTRACHILL_EVENTS_GENRE_FANOUT_HOOK = 'extrachill_events_genre_fanout';

/** WP-cron fallback hook (Action Scheduler unavailable). */
const EXTRACHILL_EVENTS_GENRE_FANOUT_CRON_HOOK = 'extrachill_events_genre_fanout_cron';

/** Action Scheduler group for genre fan-out jobs. */
const EXTRACHILL_EVENTS_GENRE_SCHEDULER_GROUP = 'extrachill-events-genre-sync';

/** Rows fetched per database batch while walking an artist's events. */
const EXTRACHILL_EVENTS_GENRE_FANOUT_BATCH_SIZE = 500;

/**
 * Initialize the event genre projection hooks
 */
function extrachill_events_init_genre_sync() {
	add_action( 'datamachine_event_taxonomy_processed', 'extrachill_events_sync_event_genres' );

	foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $meta_hook ) {
		add_action( $meta_hook, 'extrachill_events_on_artist_genres_meta', 10, 3 );
	}

	add_action( EXTRACHILL_EVENTS_GENRE_FANOUT_HOOK, 'extrachill_events_genre_fanout_worker', 10, 1 );
	add_action( EXTRACHILL_EVENTS_GENRE_FANOUT_CRON_HOOK, 'extrachill_events_genre_fanout_worker', 10, 1 );
}

/**
 * Whether the genre projection can run on this site.
 *
 * Fails closed when the genre taxonomy is not registered (extrachill-network
 * predates the genre module) or the network term-identity resolver is absent.
 *
 * @return bool
 */
function extrachill_events_genre_sync_supported(): bool {
	return taxonomy_exists( 'genre' )
		&& function_exists( 'extrachill_network_resolve_term_identity' )
		&& extrachill_events_main_blog_id() > 0;
}

/**
 * Return the main (canonical) site blog ID.
 *
 * @return int 0 when the multisite blog map cannot resolve it.
 */
function extrachill_events_main_blog_id(): int {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( ! $main_blog_id && defined( 'EC_BLOG_ID_MAIN' ) ) {
		$main_blog_id = (int) EC_BLOG_ID_MAIN;
	}

	return $main_blog_id;
}

/**
 * Resolve genres for a batch of events-site artist terms.
 *
 * Reads local `_genres` termmeta for all IDs in one meta-cache pass, then
 * resolves the misses by slug against the canonical main-site artist term in
 * a single site switch. Results are memoized per request; the returned map
 * is filterable through `extrachill_events_resolved_artist_genres` (also the
 * seam the structural dry-run harness stubs).
 *
 * @param array<int,int> $events_artist_term_ids Events-site artist term IDs.
 * @return array<int,string[]> Map of every requested term ID => genre slugs (possibly empty).
 */
function extrachill_events_resolve_artist_genres( array $events_artist_term_ids ): array {
	static $cache = array();

	$term_ids = array_values(
		array_unique(
			array_filter(
				array_map( 'intval', $events_artist_term_ids ),
				static fn( int $term_id ) => $term_id > 0
			)
		)
	);
	if ( empty( $term_ids ) ) {
		return array();
	}

	$pending = array();
	foreach ( $term_ids as $term_id ) {
		if ( ! array_key_exists( $term_id, $cache ) ) {
			$pending[] = $term_id;
		}
	}

	if ( $pending ) {
		// Prime the termmeta cache once for the whole batch.
		update_meta_cache( 'term', $pending );

		foreach ( $pending as $term_id ) {
			$cache[ $term_id ] = \ExtraChillEvents\Core\GenreSync::decode_genre_slugs(
				get_term_meta( $term_id, '_genres', true )
			);
		}

		$misses = array();
		foreach ( $pending as $term_id ) {
			if ( empty( $cache[ $term_id ] ) ) {
				$misses[] = $term_id;
			}
		}

		foreach ( extrachill_events_bridge_artist_genres( $misses ) as $term_id => $slugs ) {
			if ( ! empty( $slugs ) ) {
				$cache[ $term_id ] = $slugs;
			}
		}
	}

	$map = array();
	foreach ( $term_ids as $term_id ) {
		$map[ $term_id ] = $cache[ $term_id ];
	}

	/**
	 * Filters the resolved genre sets for events-site artist terms.
	 *
	 * @param array<int,string[]> $map      Term ID => genre slugs.
	 * @param array<int,int>      $term_ids Requested artist term IDs.
	 */
	return apply_filters( 'extrachill_events_resolved_artist_genres', $map, $term_ids );
}

/**
 * Resolve artist genres for local misses against the canonical main-site terms.
 *
 * One site switch for the whole batch: each miss is resolved by slug through
 * the network identity resolver (guarded) and the main-site term's `_genres`
 * mirror is read while switched. Unresolvable terms stay absent from the
 * returned map.
 *
 * @param array<int,int> $term_ids Events-site artist term IDs without local genres.
 * @return array<int,string[]> Term ID => genre slugs for the terms that resolved.
 */
function extrachill_events_bridge_artist_genres( array $term_ids ): array {
	if ( empty( $term_ids ) || ! function_exists( 'extrachill_network_resolve_term_identity' ) ) {
		return array();
	}

	$main_blog_id = extrachill_events_main_blog_id();
	if ( ! $main_blog_id || get_current_blog_id() === $main_blog_id ) {
		// The canonical terms are already current; resolve in place.
		return extrachill_events_read_main_artist_genres( $term_ids );
	}

	$slugs_by_term_id = extrachill_events_artist_term_slugs( $term_ids );
	if ( empty( $slugs_by_term_id ) ) {
		return array();
	}

	$resolved = array();

	switch_to_blog( $main_blog_id );
	try {
		foreach ( $slugs_by_term_id as $term_id => $slug ) {
			$identity = extrachill_network_resolve_term_identity( 'artist', $slug );
			if ( is_wp_error( $identity ) || empty( $identity['source_term_id'] ) ) {
				continue;
			}

			$slugs = \ExtraChillEvents\Core\GenreSync::decode_genre_slugs(
				get_term_meta( (int) $identity['source_term_id'], '_genres', true )
			);
			if ( ! empty( $slugs ) ) {
				$resolved[ $term_id ] = $slugs;
			}
		}
	} finally {
		restore_current_blog();
	}

	return $resolved;
}

/**
 * Read `_genres` from the (already current) canonical artist terms by term ID.
 *
 * @param array<int,int> $main_term_ids Canonical main-site artist term IDs.
 * @return array<int,string[]> Term ID => genre slugs for the terms that have any.
 */
function extrachill_events_read_main_artist_genres( array $main_term_ids ): array {
	$resolved = array();

	update_meta_cache( 'term', $main_term_ids );
	foreach ( $main_term_ids as $term_id ) {
		$slugs = \ExtraChillEvents\Core\GenreSync::decode_genre_slugs( get_term_meta( $term_id, '_genres', true ) );
		if ( ! empty( $slugs ) ) {
			$resolved[ $term_id ] = $slugs;
		}
	}

	return $resolved;
}

/**
 * Map events-site artist term IDs to their slugs.
 *
 * @param array<int,int> $term_ids Artist term IDs.
 * @return array<int,string> Term ID => slug (missing terms omitted).
 */
function extrachill_events_artist_term_slugs( array $term_ids ): array {
	$slugs_by_term_id = array();

	$terms = get_terms(
		array(
			'taxonomy'   => 'artist',
			'include'    => array_values( $term_ids ),
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	if ( is_array( $terms ) ) {
		foreach ( $terms as $term ) {
			$slugs_by_term_id[ (int) $term->term_id ] = $term->slug;
		}
	}

	return $slugs_by_term_id;
}

/**
 * Materialize the genre projection for one event.
 *
 * Computes the capped union of the event's artist genres, keeps existing
 * assignments untouched when they already match, and otherwise writes with
 * replace semantics through `wp_set_object_terms()`. Only genre terms that
 * exist on the events site are assigned; missing vocabulary terms are
 * materialized through the network's approved projection (never created
 * directly), and anything the projection cannot approve is skipped.
 *
 * Fails closed with a single logged warning when the genre taxonomy or the
 * network resolver is unavailable.
 *
 * @param int $post_id Event post ID.
 * @return string[] The genre slugs the event now projects (empty when unsupported).
 */
function extrachill_events_sync_event_genres( int $post_id ): array {
	static $warned_unsupported = false;

	if ( ! extrachill_events_genre_sync_supported() ) {
		if ( ! $warned_unsupported ) {
			$warned_unsupported = true;
			do_action(
				'datamachine_log',
				'warning',
				'Event genre sync unavailable: genre taxonomy or extrachill-network genre resolver is missing.',
				array( 'post_id' => $post_id )
			);
		}

		return array();
	}

	$artist_term_ids = wp_get_object_terms( $post_id, 'artist', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $artist_term_ids ) ) {
		return array();
	}

	$artist_term_ids = array_map( 'intval', (array) $artist_term_ids );
	$artist_genres   = extrachill_events_resolve_artist_genres( $artist_term_ids );
	$genre_slugs     = \ExtraChillEvents\Core\GenreSync::union_genres( $artist_genres, \ExtraChillEvents\Core\GenreSync::EVENT_GENRE_CAP );

	$terms    = extrachill_events_genre_term_ids_for_slugs( $genre_slugs, true );
	$assigned = array();
	foreach ( $terms['ids'] as $term_id ) {
		$term = get_term( $term_id, 'genre' );
		if ( $term instanceof WP_Term ) {
			$assigned[] = $term->slug;
		}
	}

	$current = wp_get_object_terms( $post_id, 'genre', array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $current ) ) {
		$current = array();
	}

	sort( $assigned );
	$current = array_values( (array) $current );
	sort( $current );

	if ( $assigned === $current ) {
		return $assigned;
	}

	wp_set_object_terms( $post_id, $terms['ids'], 'genre', false );

	return $assigned;
}

/**
 * Resolve genre slugs to events-site term IDs, optionally materializing missing ones.
 *
 * Existing terms are used directly. Missing slugs go through the network's
 * approved term projection (`extrachill_network_project_term()`, guarded) so
 * term creation stays inside the closed vocabulary — this sync path never
 * inserts a genre term itself. Slugs the projection refuses are reported as
 * missing and skipped.
 *
 * @param array<int,string> $slugs  Genre slugs.
 * @param bool              $ensure Whether to materialize missing approved terms.
 * @return array{ids:int[],missing:string[]}
 */
function extrachill_events_genre_term_ids_for_slugs( array $slugs, bool $ensure = true ): array {
	$ids     = array();
	$missing = array();

	foreach ( $slugs as $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			continue;
		}

		$term = get_term_by( 'slug', $slug, 'genre' );
		if ( $term instanceof WP_Term ) {
			$ids[] = (int) $term->term_id;
			continue;
		}

		if ( ! $ensure || ! function_exists( 'extrachill_network_project_term' ) ) {
			$missing[] = $slug;
			continue;
		}

		$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		$projected = extrachill_network_project_term( 'events', $post_type, 'genre', $slug );
		if ( is_wp_error( $projected ) || empty( $projected['term_id'] ) ) {
			$missing[] = $slug;
			continue;
		}

		$ids[] = (int) $projected['term_id'];
	}

	return array(
		'ids'     => $ids,
		'missing' => $missing,
	);
}

/**
 * Fan out a genre change for one events-site artist term to its events.
 *
 * Small fan-outs (at or below GenreSync::FANOUT_INLINE_THRESHOLD events) run
 * inline; anything larger is queued through Action Scheduler, with wp_cron as
 * the fallback, so a popular artist's genre edit never blocks the request.
 *
 * @param int $term_id Events-site artist term ID.
 */
function extrachill_events_fanout_artist_genres( int $term_id ): void {
	if ( $term_id <= 0 || ! extrachill_events_genre_sync_supported() ) {
		return;
	}

	$event_count = extrachill_events_count_artist_term_events( $term_id );
	if ( $event_count <= \ExtraChillEvents\Core\GenreSync::FANOUT_INLINE_THRESHOLD ) {
		extrachill_events_genre_fanout_worker( $term_id );
		return;
	}

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time() + 1, EXTRACHILL_EVENTS_GENRE_FANOUT_HOOK, array( $term_id ), EXTRACHILL_EVENTS_GENRE_SCHEDULER_GROUP, true );
		return;
	}

	if ( function_exists( 'wp_schedule_single_event' ) ) {
		wp_schedule_single_event( time() + 1, EXTRACHILL_EVENTS_GENRE_FANOUT_CRON_HOOK, array( $term_id ) );
		return;
	}

	do_action(
		'datamachine_log',
		'warning',
		'Event genre fan-out skipped: no scheduler available for a large artist term.',
		array(
			'term_id' => $term_id,
			'events'  => $event_count,
		)
	);
}

/**
 * Handle an `_genres` termmeta change on an artist term.
 *
 * On the events site the term fans out directly. On the main site (canonical
 * artist terms) the events-site term is resolved by slug and fans out there;
 * this branch only executes when this plugin is active on the main site.
 *
 * @param mixed  $meta_id  Meta row ID (or IDs array on delete).
 * @param int    $term_id  Artist term ID.
 * @param string $meta_key Meta key.
 */
function extrachill_events_on_artist_genres_meta( $meta_id, $term_id, $meta_key ): void {
	unset( $meta_id );

	if ( '_genres' !== $meta_key ) {
		return;
	}

	$term = get_term( (int) $term_id, 'artist' );
	if ( ! $term instanceof WP_Term ) {
		return;
	}

	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	if ( ! $events_blog_id || get_current_blog_id() === $events_blog_id ) {
		extrachill_events_fanout_artist_genres( (int) $term_id );
		return;
	}

	switch_to_blog( $events_blog_id );
	try {
		$events_term = get_term_by( 'slug', $term->slug, 'artist' );
		if ( $events_term instanceof WP_Term ) {
			extrachill_events_fanout_artist_genres( (int) $events_term->term_id );
		}
	} finally {
		restore_current_blog();
	}
}

/**
 * Count the syncable events assigned to one events-site artist term.
 *
 * @param int $term_id Artist term ID.
 * @return int
 */
function extrachill_events_count_artist_term_events( int $term_id ): int {
	global $wpdb;

	$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Set-based fan-out decision; per-row WP_Query would be orders of magnitude slower.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts/term_relationships/term_taxonomy are trusted core table properties; every variable value goes through %s/%d placeholders.
			'SELECT COUNT(p.ID) FROM ' . $wpdb->posts . ' AS p'
			. ' INNER JOIN ' . $wpdb->term_relationships . ' AS tr ON tr.object_id = p.ID'
			. ' INNER JOIN ' . $wpdb->term_taxonomy . ' AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id'
			. " WHERE tt.term_id = %d AND tt.taxonomy = 'artist' AND p.post_type = %s AND p.post_status NOT IN ( 'auto-draft', 'trash' )",
			$term_id,
			$post_type
		)
	);
}

/**
 * Sync every event assigned to one events-site artist term, in batches.
 *
 * Action Scheduler / wp_cron callback for large fan-outs; also the inline
 * path for small ones.
 *
 * @param int $term_id Artist term ID.
 */
function extrachill_events_genre_fanout_worker( int $term_id ): void {
	global $wpdb;

	if ( $term_id <= 0 || ! extrachill_events_genre_sync_supported() ) {
		return;
	}

	$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
	$last_id   = 0;

	while ( true ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk catalog walk; per-row WP_Query would be orders of magnitude slower inside a batch worker.
		$event_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted core table properties; every variable value goes through %s/%d placeholders.
				'SELECT p.ID FROM ' . $wpdb->posts . ' AS p'
				. ' INNER JOIN ' . $wpdb->term_relationships . ' AS tr ON tr.object_id = p.ID'
				. ' INNER JOIN ' . $wpdb->term_taxonomy . ' AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id'
				. " WHERE tt.term_id = %d AND tt.taxonomy = 'artist' AND p.post_type = %s AND p.post_status NOT IN ( 'auto-draft', 'trash' ) AND p.ID > %d"
				. ' ORDER BY p.ID ASC LIMIT %d',
				$term_id,
				$post_type,
				$last_id,
				EXTRACHILL_EVENTS_GENRE_FANOUT_BATCH_SIZE
			)
		);

		if ( empty( $event_ids ) ) {
			break;
		}

		foreach ( $event_ids as $event_id ) {
			$event_id = (int) $event_id;
			$last_id  = $event_id;
			extrachill_events_sync_event_genres( $event_id );
		}

		if ( count( $event_ids ) < EXTRACHILL_EVENTS_GENRE_FANOUT_BATCH_SIZE ) {
			break;
		}
	}

	// The full-page cache purges on term CRUD, not term assignment — flush the
	// current blog once after a fan-out so badges and calendar filters reflect
	// the new projection. No-op when extrachill-cache is inactive.
	do_action( 'extrachill_cache_flush' );
}
