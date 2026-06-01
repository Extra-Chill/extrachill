<?php
/**
 * My Shows — Map view filters.
 *
 * Wires the personal-map tab on `/my-shows/` to the data-machine-events
 * events-map block by hooking the two generic extension points exposed
 * by DME:
 *
 *   - `data_machine_events_map_query_args` — restricts the venue set to
 *     venues attached to the current user's tracked events.
 *   - `data_machine_events_map_venues` — populates `upcoming_events_at_venue`
 *     per venue from the user's tracked shows so the events-map block's
 *     `chronologicalRouteMode` renders the polyline + multi-date popups
 *     against the user's attendance timeline. The built-in DME
 *     attachment path requires `taxonomy + term_id`, which is not
 *     applicable on a Page route like `/my-shows/`.
 *
 * Scope-token model (#160). Both callbacks USED to gate on
 * `is_page('my-shows')` + logged-in user. The events-map block fetches
 * venues over the public REST endpoint on mount (and on every pan/zoom),
 * where `is_page('my-shows')` is FALSE — so the scoping silently dropped
 * and the map leaked the entire network-wide venue set (the same class of
 * bug as the calendar in #160).
 *
 * The fix mirrors the calendar: owner identity travels as a DATA token.
 * We mint a non-spoofable HMAC token for the owner at server-render time
 * via the generic `data_machine_events_map_scope_token` seam; DME emits
 * it on the map root as `data-scope-token`, the map frontend re-sends it
 * as `scope_token` on every venue fetch, and DME threads it into these
 * filters' `$input['scope_token']`. The callbacks validate the token →
 * owner user id and scope accordingly, regardless of page context.
 *
 * Reuses `ec_events_my_shows_get_tracked_post_ids()` from
 * `my-shows-calendar-filter.php` (#110) for the underlying
 * tracked-event lookup against `{$wpdb->base_prefix}ec_concert_tracking`,
 * and the mint/verify helpers from `my-shows-scope-token.php`.
 *
 * Layer purity: data-machine-events stays generic (no knowledge of
 * `c8c_ec_concert_tracking`). Extrachill-events provides the
 * integration glue via the documented extension points.
 *
 * @package ExtraChillEvents
 * @since 0.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mint the owner scope token for the embedded My Shows map.
 *
 * Runs at server-render time via the generic
 * `data_machine_events_map_scope_token` seam. Page context IS reliable
 * here (fires during the `/my-shows/` PHP render, never over REST), so
 * `is_page()` is the correct gate for EMISSION.
 *
 * @param string $scope_token Incoming token (empty unless another consumer set one).
 * @param array  $context     Map render context from DME.
 * @return string Minted token, or the incoming value unchanged when not applicable.
 */
function ec_events_my_shows_emit_map_scope_token( $scope_token, $context = array() ) {
	unset( $context );

	if ( ! function_exists( 'is_page' ) || ! is_page( 'my-shows' ) ) {
		return $scope_token;
	}

	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return $scope_token;
	}

	return ec_events_my_shows_mint_scope_token( $user_id );
}
add_filter( 'data_machine_events_map_scope_token', 'ec_events_my_shows_emit_map_scope_token', 10, 2 );

/**
 * Restrict events-map venue lookup to venues of the token owner's
 * tracked shows.
 *
 * Active only when `$input['scope_token']` is a VALID owner token. No
 * valid token → no scoping (the public map default). Driven by the
 * verified data token rather than `is_page()` so it survives the map's
 * mount + pan/zoom REST round-trips (the #160 fix).
 *
 * The DME venue-map query path computes a candidate venue ID set from
 * any geo / taxonomy filters and exposes the merged result via
 * `$query_args['include_ids']`. We narrow that set further to the
 * venue terms attached to the owner's tracked events. Empty tracked set
 * → sentinel `[0]` so the venue lookup short-circuits to "no results"
 * cleanly.
 *
 * @param array $query_args Resolved query args from VenueMapAbilities.
 * @param array $input      Raw ability input; `scope_token` carries the owner token.
 * @return array Modified $query_args.
 */
function ec_events_my_shows_filter_map_query( array $query_args, array $input = array() ): array {
	$token   = $input['scope_token'] ?? '';
	$user_id = ec_events_my_shows_verify_scope_token( $token );

	if ( $user_id < 1 ) {
		return $query_args;
	}

	$venue_term_ids = ec_events_my_shows_get_tracked_venue_term_ids( $user_id );

	if ( empty( $venue_term_ids ) ) {
		// Sentinel — the non-existent term_id 0 short-circuits the
		// venue query and surfaces the empty-state cleanly.
		$query_args['include_ids'] = array( 0 );
		return $query_args;
	}

	// Honor stacked filters (defensive — DME may have already populated
	// include_ids from geo / taxonomy filters earlier in the resolver).
	if ( isset( $query_args['include_ids'] ) && is_array( $query_args['include_ids'] ) ) {
		$narrowed                  = array_values( array_intersect( $query_args['include_ids'], $venue_term_ids ) );
		$query_args['include_ids'] = empty( $narrowed ) ? array( 0 ) : $narrowed;
	} else {
		$query_args['include_ids'] = $venue_term_ids;
	}

	return $query_args;
}
add_filter( 'data_machine_events_map_query_args', 'ec_events_my_shows_filter_map_query', 10, 2 );

/**
 * Populate per-venue tracked-event payloads and re-order chronologically.
 *
 * The events-map frontend's `chronologicalRouteMode` requires
 * `upcoming_events_at_venue` to be populated (the frontend filters out
 * venues without it and derives the polyline order from the earliest
 * entry per venue). DME's built-in attachment is gated on a
 * taxonomy/term filter which is not applicable on a Page route like
 * `/my-shows/`, so we attach our own payload here using the user's
 * tracked shows.
 *
 * The array key name (`upcoming_events_at_venue`) is the contract the
 * frontend already consumes; "upcoming" is a misnomer for past shows
 * but renaming would require a DME-side rename which is out of scope.
 *
 * Ordering: venues are sorted by earliest tracked-show date so the
 * polyline traces the user's attendance order, mirroring the artist
 * tour-route mode that uses earliest-event-at-venue ordering.
 *
 * @param array $venues Final venue array from VenueMapAbilities (already sorted + capped).
 * @param array $input  Raw ability input; `scope_token` carries the owner token.
 * @return array Venues with tracked events attached and re-ordered chronologically.
 */
function ec_events_my_shows_attach_tracked_events( array $venues, array $input = array() ): array {
	$token   = $input['scope_token'] ?? '';
	$user_id = ec_events_my_shows_verify_scope_token( $token );

	if ( $user_id < 1 || empty( $venues ) ) {
		return $venues;
	}

	$venue_ids = array_values(
		array_filter(
			array_map(
				static fn( $v ) => (int) ( $v['term_id'] ?? 0 ),
				$venues
			)
		)
	);
	if ( empty( $venue_ids ) ) {
		return $venues;
	}

	$events_by_venue = ec_events_my_shows_get_tracked_events_for_venues( $user_id, $venue_ids );

	$enriched = array();
	foreach ( $venues as $venue ) {
		$term_id                           = (int) ( $venue['term_id'] ?? 0 );
		$venue['upcoming_events_at_venue'] = $events_by_venue[ $term_id ] ?? array();
		$enriched[]                        = $venue;
	}

	// Re-order by earliest tracked-show date so chronologicalRouteMode
	// draws the polyline in user-attendance order. Venues with no
	// tracked shows fall to the end (the frontend filters them out
	// anyway, but keeping them last preserves array shape).
	usort(
		$enriched,
		static function ( array $a, array $b ): int {
			$a_first = ( $a['upcoming_events_at_venue'][0]['start_date'] ?? '' )
				. ' ' . ( $a['upcoming_events_at_venue'][0]['start_time'] ?? '' );
			$b_first = ( $b['upcoming_events_at_venue'][0]['start_date'] ?? '' )
				. ' ' . ( $b['upcoming_events_at_venue'][0]['start_time'] ?? '' );
			$a_key   = trim( $a_first );
			$b_key   = trim( $b_first );
			if ( '' === $a_key && '' === $b_key ) {
				return 0;
			}
			if ( '' === $a_key ) {
				return 1;
			}
			if ( '' === $b_key ) {
				return -1;
			}
			return strcmp( $a_key, $b_key );
		}
	);

	return $enriched;
}
add_filter( 'data_machine_events_map_venues', 'ec_events_my_shows_attach_tracked_events', 10, 2 );

/**
 * Resolve venue term IDs attached to the user's tracked events.
 *
 * Two-step lookup: tracked event IDs (via
 * `ec_events_my_shows_get_tracked_post_ids()` from
 * `my-shows-calendar-filter.php`) → venue taxonomy terms for those
 * events with `switch_to_blog()` to the events site for the taxonomy
 * query.
 *
 * @param int $user_id Current user ID.
 * @return int[] Unique venue term IDs.
 */
function ec_events_my_shows_get_tracked_venue_term_ids( int $user_id ): array {
	if ( ! function_exists( 'ec_events_my_shows_get_tracked_post_ids' ) ) {
		return array();
	}

	$event_ids = ec_events_my_shows_get_tracked_post_ids( $user_id );
	if ( empty( $event_ids ) ) {
		return array();
	}

	$events_blog_id = function_exists( 'ec_get_blog_id' )
		? (int) ec_get_blog_id( 'events' )
		: 7;

	$switched = false;
	if ( get_current_blog_id() !== $events_blog_id ) {
		switch_to_blog( $events_blog_id );
		$switched = true;
	}

	$venue_term_ids = array();
	foreach ( $event_ids as $event_id ) {
		$terms = wp_get_post_terms( (int) $event_id, 'venue', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
			$venue_term_ids = array_merge( $venue_term_ids, $terms );
		}
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return array_values( array_unique( array_map( 'intval', $venue_term_ids ) ) );
}

/**
 * Build per-venue arrays of the user's tracked shows.
 *
 * Returns a map of `venue_term_id => [ { post_id, start_date, start_time, title, permalink }, ... ]`
 * ordered chronologically (earliest first) within each venue.
 *
 * Cross-references the network-scoped tracking table with the events
 * blog's posts / event_dates / term_relationships tables via a single
 * batched query. Runs in events-blog context (switch_to_blog) so
 * `$wpdb->prefix` resolves to the events site's per-blog tables; the
 * tracking table sits at `$wpdb->base_prefix` so we reference it via
 * the explicit base prefix.
 *
 * @param int   $user_id   Current user ID.
 * @param int[] $venue_ids Venue term IDs to populate.
 * @return array<int, array<int, array{post_id:int,start_date:string,start_time:string,title:string,permalink:string}>>
 */
function ec_events_my_shows_get_tracked_events_for_venues( int $user_id, array $venue_ids ): array {
	global $wpdb;

	if ( empty( $venue_ids ) ) {
		return array();
	}

	$events_blog_id = function_exists( 'ec_get_blog_id' )
		? (int) ec_get_blog_id( 'events' )
		: 7;

	$switched = false;
	if ( get_current_blog_id() !== $events_blog_id ) {
		switch_to_blog( $events_blog_id );
		$switched = true;
	}

	$tracking_table = $wpdb->base_prefix . 'ec_concert_tracking';
	$placeholders   = implode( ',', array_fill( 0, count( $venue_ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$query = $wpdb->prepare(
		"SELECT
			tt.term_id AS venue_term_id,
			p.ID AS post_id,
			p.post_title AS title,
			ed.start_date AS start_date,
			ed.start_time AS start_time
		FROM {$tracking_table} t
		INNER JOIN {$wpdb->posts} p
			ON t.event_id = p.ID
		INNER JOIN {$wpdb->term_relationships} tr
			ON tr.object_id = p.ID
		INNER JOIN {$wpdb->term_taxonomy} tt
			ON tr.term_taxonomy_id = tt.term_taxonomy_id
		LEFT JOIN {$wpdb->prefix}datamachine_event_dates ed
			ON ed.post_id = p.ID
		WHERE t.user_id = %d
			AND t.blog_id = %d
			AND tt.taxonomy = 'venue'
			AND tt.term_id IN ($placeholders)
			AND p.post_status = 'publish'
		ORDER BY tt.term_id ASC, ed.start_date ASC, ed.start_time ASC",
		array_merge( array( $user_id, $events_blog_id ), $venue_ids )
	);

	$rows = $wpdb->get_results( $query );
	// phpcs:enable

	$by_venue = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$venue_term_id = (int) $row->venue_term_id;
			$post_id       = (int) $row->post_id;
			$permalink     = get_permalink( $post_id );

			$by_venue[ $venue_term_id ][] = array(
				'post_id'    => $post_id,
				'start_date' => (string) ( $row->start_date ?? '' ),
				'start_time' => (string) ( $row->start_time ?? '' ),
				'title'      => (string) ( $row->title ?? '' ),
				'permalink'  => is_string( $permalink ) ? $permalink : '',
			);
		}
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return $by_venue;
}
