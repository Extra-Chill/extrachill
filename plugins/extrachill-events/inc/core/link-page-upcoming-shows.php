<?php
/**
 * Push upcoming artist shows into managed Link Page sections.
 *
 * Event records remain on Events; only the source's rendered links are stored.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'extrachill_events_register_upcoming_shows_source', 40 );

/** Optional Link Pages integration, deferred until all plugins are loaded. */
function extrachill_events_register_upcoming_shows_source() {
	if ( ! function_exists( 'ec_register_link_page_section_source' ) ) {
		return;
	}
	$result = ec_register_link_page_section_source(
		'upcoming_events',
		array(
			'label'         => 'Upcoming Shows',
			'config_schema' => array( 'limit' => array( 'type' => 'int' ) ),
			'resolve'       => 'extrachill_events_resolve_upcoming_shows',
		)
	);
	if ( is_wp_error( $result ) ) {
		return;
	}
	add_action( 'init', 'extrachill_events_schedule_upcoming_shows_refresh' );
	add_action( 'extrachill_events_refresh_upcoming_shows', 'extrachill_events_refresh_upcoming_shows' );
	add_action( 'save_post_data_machine_events', 'extrachill_events_queue_upcoming_shows_refresh' );
	add_action( 'deleted_post', 'extrachill_events_upcoming_shows_deleted', 10, 2 );
	add_action( 'set_object_terms', 'extrachill_events_upcoming_shows_terms_changed', 10, 4 );
	add_action( 'added_post_meta', 'extrachill_events_upcoming_shows_meta_changed', 10, 2 );
	add_action( 'updated_post_meta', 'extrachill_events_upcoming_shows_meta_changed', 10, 2 );
	add_action( 'deleted_post_meta', 'extrachill_events_upcoming_shows_meta_changed', 10, 2 );
}

/** Resolve only artist-profile owners, never an arbitrary artist from section config. */
function extrachill_events_resolve_upcoming_shows( $context ) {
	$owner = ec_parse_link_page_owner_reference( $context['owner_reference'] ?? '' );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}
	if ( 'post' !== ( $owner['kind'] ?? '' ) || 'artist_profile' !== ( $owner['subtype'] ?? '' )
		|| (int) $owner['blog_id'] !== (int) ec_get_blog_id( 'artist' ) ) {
		return new WP_Error( 'upcoming_shows_owner_unsupported', 'Upcoming shows require an artist profile owner.' );
	}
	if ( ! function_exists( 'extrachill_events_ability_events_by_artist' ) ) {
		return new WP_Error( 'upcoming_shows_unavailable', 'The artist events adapter is unavailable.' );
	}
	switch_to_blog( (int) $owner['blog_id'] );
	try {
		$term_id = (int) get_post_meta( (int) $owner['object_id'], '_artist_term_id', true );
	} finally {
		restore_current_blog();
	}
	if ( $term_id < 1 ) {
		return new WP_Error( 'upcoming_shows_artist_unbound', 'The artist profile has no canonical artist binding.' );
	}
	$limit  = max( 1, min( 25, (int) ( $context['section']['source_config']['limit'] ?? 5 ) ) );
	$result = extrachill_events_ability_events_by_artist(
		array( 'artist_term_id' => $term_id, 'scope' => 'upcoming', 'limit' => $limit )
	);
	if ( is_wp_error( $result ) ) {
		return $result; // Preserve the last good snapshot on dependency/mapping failure.
	}
	if ( ! is_array( $result ) || ! isset( $result['upcoming'] ) || ! is_array( $result['upcoming'] ) ) {
		return new WP_Error( 'upcoming_shows_invalid_result', 'The artist events response is invalid.' );
	}
	$links = array();
	foreach ( array_slice( $result['upcoming'], 0, $limit ) as $show ) {
		if ( ! is_array( $show ) || empty( $show['permalink'] ) ) {
			return new WP_Error( 'upcoming_shows_invalid_result', 'An upcoming show has no canonical event URL.' );
		}
		$parts   = array_filter( array( $show['date_display'] ?? '', $show['venue_name'] ?? '', $show['title'] ?? '' ) );
		$links[] = array(
			'link_text' => implode( ' — ', $parts ),
			'link_url'  => $show['permalink'],
		);
	}
	return $links;
}

/** Hourly push handles shows passing without a subsequent event write. */
function extrachill_events_schedule_upcoming_shows_refresh() {
	if ( get_current_blog_id() === (int) ec_get_blog_id( 'events' ) && ! wp_next_scheduled( 'extrachill_events_refresh_upcoming_shows' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'extrachill_events_refresh_upcoming_shows' );
	}
}

/** Refresh after all event metadata and taxonomy writes, once per request. */
function extrachill_events_queue_upcoming_shows_refresh() {
	static $queued = false;
	if ( get_current_blog_id() !== (int) ec_get_blog_id( 'events' ) || $queued ) {
		return;
	}
	$queued = true;
	add_action( 'shutdown', 'extrachill_events_refresh_upcoming_shows', 20 );
}

/** Deletion fires after removal so the query cannot return the deleted show. */
function extrachill_events_upcoming_shows_deleted( $post_id, $post ) {
	if ( 'data_machine_events' === $post->post_type ) {
		extrachill_events_queue_upcoming_shows_refresh();
	}
}

/** Catch artist changes made after save_post, including removed artists. */
function extrachill_events_upcoming_shows_terms_changed( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( 'artist' === $taxonomy && 'data_machine_events' === get_post_type( $object_id ) ) {
		extrachill_events_queue_upcoming_shows_refresh();
	}
}

/** Catch date/venue edits made independently of a post save. */
function extrachill_events_upcoming_shows_meta_changed( $meta_id, $post_id ) {
	if ( 'data_machine_events' === get_post_type( $post_id ) ) {
		extrachill_events_queue_upcoming_shows_refresh();
	}
}

/** Refresh all opted-in sections, including owners removed from an event's lineup. */
function extrachill_events_refresh_upcoming_shows() {
	if ( function_exists( 'ec_refresh_link_page_sections_for_source' ) ) {
		return ec_refresh_link_page_sections_for_source( 'upcoming_events' );
	}
}
