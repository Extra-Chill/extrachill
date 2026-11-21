<?php
/**
 * "Show published for these artists" signal (#1725).
 *
 * Ownership boundary: Events owns event data and the Events-site artist term
 * ↔ canonical artist term resolution, so it decides WHICH canonical artists a
 * newly published show belongs to. extrachill-artist-platform owns artist
 * mailing lists and the send. Events never reads the subscriber list; it only
 * hands off plain event scalars plus canonical main-site artist term IDs.
 *
 * Flow:
 * 1. A canonical event transitions into `publish` (submission approval, a
 *    trusted direct publish, or an import/scrape). Events queues an async
 *    Action Scheduler action on the events blog. Deferring lets importers
 *    finish assigning artist/venue terms and event data first.
 * 2. The async action reads the event, resolves its artist terms to
 *    canonical artist terms, and enqueues `extrachill_artist_show_published`
 *    on the artist blog's Action Scheduler queue (Artist Platform is only
 *    active there; switch_to_blog() changes the queue's tables, not the
 *    loaded code, so the artist blog's own runner executes the handler).
 *
 * Edits of already-published events (publish → publish) never signal.
 * Artist Platform's receipts make any replay idempotent, and it alone
 * decides eligibility (upcoming, not cancelled) at send time.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/abilities/events-by-artist.php';

/** Events-blog async hook that builds and hands off the signal. */
const EXTRACHILL_EVENTS_ARTIST_SHOW_SIGNAL_HOOK = 'extrachill_events_emit_artist_show_signal';

/** Artist-blog hook owned by extrachill-artist-platform. */
const EXTRACHILL_EVENTS_ARTIST_SHOW_PUBLISHED_HOOK = 'extrachill_artist_show_published';

/** Action Scheduler group on the artist blog (matches Artist Platform). */
const EXTRACHILL_EVENTS_ARTIST_SHOW_PUBLISHED_GROUP = 'extrachill-artist-show-announcements';

add_action( 'transition_post_status', 'extrachill_events_queue_artist_show_signal', 10, 3 );
add_action( EXTRACHILL_EVENTS_ARTIST_SHOW_SIGNAL_HOOK, 'extrachill_events_emit_artist_show_signal', 10, 1 );

/**
 * Queue the signal when a canonical event first becomes published.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param mixed   $post       Post object.
 */
function extrachill_events_queue_artist_show_signal( $new_status, $old_status, $post ): void {
	if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post ) {
		return;
	}
	$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
	if ( $post_type !== $post->post_type || ! ec_is_events_site() ) {
		return;
	}

	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( EXTRACHILL_EVENTS_ARTIST_SHOW_SIGNAL_HOOK, array( (int) $post->ID ), 'extrachill-events', true );
		return;
	}
	if ( function_exists( 'wp_schedule_single_event' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, EXTRACHILL_EVENTS_ARTIST_SHOW_SIGNAL_HOOK, array( (int) $post->ID ) );
	}
}

/**
 * Build the signal for one published event and hand it to the artist blog.
 *
 * @param int $post_id Event post ID on the events blog.
 * @return array|null Signal handed off, or null when nothing was emitted.
 */
function extrachill_events_emit_artist_show_signal( $post_id ): ?array {
	$signal = extrachill_events_build_artist_show_signal( absint( $post_id ) );
	if ( null === $signal ) {
		return null;
	}

	/**
	 * Fires on the events blog when a published show is attributed to canonical artists.
	 *
	 * @param array $signal Event scalars and canonical artist term IDs.
	 */
	do_action( 'extrachill_events_artist_show_published', $signal );

	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
	if ( $artist_blog_id <= 0 || ! function_exists( 'as_enqueue_async_action' ) ) {
		return null;
	}

	switch_to_blog( $artist_blog_id );
	try {
		as_enqueue_async_action( EXTRACHILL_EVENTS_ARTIST_SHOW_PUBLISHED_HOOK, array( $signal ), EXTRACHILL_EVENTS_ARTIST_SHOW_PUBLISHED_GROUP, true );
	} finally {
		restore_current_blog();
	}

	return $signal;
}

/**
 * Read a published event into the signal payload.
 *
 * @param int $post_id Event post ID on the events blog.
 * @return array|null
 */
function extrachill_events_build_artist_show_signal( int $post_id ): ?array {
	$post      = $post_id > 0 ? get_post( $post_id ) : null;
	$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
	if ( ! $post instanceof WP_Post || $post_type !== $post->post_type || 'publish' !== $post->post_status || ! function_exists( 'data_machine_events_parse_event_data' ) ) {
		return null;
	}

	$local_terms = wp_get_post_terms( $post->ID, 'artist', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $local_terms ) || empty( $local_terms ) ) {
		return null;
	}
	$artist_term_ids = extrachill_events_canonical_artist_term_ids( array_map( 'absint', $local_terms ) );
	if ( empty( $artist_term_ids ) ) {
		return null;
	}

	$data = data_machine_events_parse_event_data( $post );
	if ( ! is_array( $data ) ) {
		return null;
	}
	$start = extrachill_events_artist_show_signal_start( $data );
	$url   = (string) get_permalink( $post );
	if ( null === $start || '' === $url ) {
		return null;
	}

	$venue_terms = wp_get_post_terms( $post->ID, 'venue' );
	$venue       = ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ? $venue_terms[0]->name : (string) ( $data['venue'] ?? '' );

	return array(
		'event_key'       => get_current_blog_id() . ':' . (int) $post->ID,
		'title'           => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
		'url'             => $url,
		'venue'           => wp_specialchars_decode( (string) $venue, ENT_QUOTES ),
		'starts_at'       => $start['starts_at'],
		'has_time'        => $start['has_time'],
		'status'          => (string) ( $data['eventStatus'] ?? '' ),
		'ticket_url'      => (string) ( $data['ticketUrl'] ?? '' ),
		'artist_term_ids' => $artist_term_ids,
	);
}

/**
 * Resolve the event's start into an ISO 8601 string in the venue timezone.
 *
 * @param array $data Parsed event data.
 * @return array{starts_at:string, has_time:bool}|null
 */
function extrachill_events_artist_show_signal_start( array $data ): ?array {
	$date = (string) ( $data['startDate'] ?? '' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return null;
	}

	$timezone_name = (string) ( $data['venueTimezone'] ?? '' );
	$timezone      = in_array( $timezone_name, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true ) ? new DateTimeZone( $timezone_name ) : wp_timezone();

	$time = (string) ( $data['startTime'] ?? '' );
	if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
		$time .= ':00';
	}
	$has_time = (bool) preg_match( '/^\d{2}:\d{2}:\d{2}$/', $time ) && '00:00:00' !== $time;

	$start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . ( $has_time ? $time : '00:00:00' ), $timezone );
	if ( ! $start instanceof DateTimeImmutable ) {
		return null;
	}

	return array(
		'starts_at' => $start->format( DATE_ATOM ),
		'has_time'  => $has_time,
	);
}

/**
 * Resolve Events-site artist term IDs to canonical main-site artist term IDs.
 *
 * Uses the existing canonical mapping (`_extrachill_events_artist_term_id`
 * on the main-site term). A local term with no mapping yet is resolved
 * through the events-by-artist adapter's exact-slug lazy mapping, which only
 * binds unambiguous, unclaimed, profile-bound artists. Ambiguous or
 * duplicate claims resolve to nothing.
 *
 * @param int[] $local_term_ids Events-site artist term IDs.
 * @return int[] Canonical artist term IDs.
 */
function extrachill_events_canonical_artist_term_ids( array $local_term_ids ): array {
	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return array();
	}

	$canonical = array();
	$unmapped  = array();
	switch_to_blog( $main_blog_id );
	try {
		foreach ( array_unique( array_filter( $local_term_ids ) ) as $local_id ) {
			$claims = extrachill_events_read_artist_mapping_claims( (int) $local_id );
			if ( is_wp_error( $claims ) || count( $claims ) > 1 ) {
				continue;
			}
			if ( 1 === count( $claims ) ) {
				$canonical[] = (int) $claims[0];
			} else {
				$unmapped[] = (int) $local_id;
			}
		}
	} finally {
		restore_current_blog();
	}

	foreach ( $unmapped as $local_id ) {
		$local = get_term( $local_id, 'artist' );
		if ( ! $local instanceof WP_Term ) {
			continue;
		}
		switch_to_blog( $main_blog_id );
		try {
			$matches = extrachill_events_read_artist_slug_matches( (string) $local->slug );
		} finally {
			restore_current_blog();
		}
		if ( is_wp_error( $matches ) || 1 !== count( $matches ) ) {
			continue;
		}
		$candidate = (int) $matches[0]->term_id;
		$resolved  = extrachill_events_resolve_artist_term( $candidate );
		if ( is_array( $resolved ) && $local_id === (int) $resolved['term_id'] ) {
			$canonical[] = $candidate;
		}
	}

	return array_values( array_unique( array_filter( $canonical ) ) );
}
