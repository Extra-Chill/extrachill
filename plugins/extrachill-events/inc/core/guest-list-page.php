<?php
/**
 * Guest List Page
 *
 * The guest-facing half of a password-gated guest list (extrachill#433):
 * `/events/{slug}/guest-list`. A core rewrite endpoint on the event
 * permalink (add_rewrite_endpoint), so every event gets one with no slug
 * to keep in sync. The page asks only for the password, shows the QR pass
 * on screen, and offers an optional email copy. The event page itself stays
 * the public listing; hosts keep the door list there.
 *
 * Only events with an enabled perk and a guest list password have the page;
 * every other event answers 404 at this URL. The page is never cached
 * (spots left is live) and never indexed.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Endpoint slug appended to the event permalink. */
const EXTRACHILL_EVENTS_GUEST_LIST_ENDPOINT = 'guest-list';

/**
 * Register the endpoint on single-post permalinks (custom post types use
 * EP_PERMALINK by default, which covers data_machine_events). Rule changes
 * are flushed by extrachill_events_maybe_flush_router_rewrites().
 *
 * @hook init
 */
function extrachill_events_guest_list_rewrite_endpoint(): void {
	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	add_rewrite_endpoint( EXTRACHILL_EVENTS_GUEST_LIST_ENDPOINT, EP_PERMALINK );
}
add_action( 'init', 'extrachill_events_guest_list_rewrite_endpoint' );

/**
 * Public URL of an event's guest list page.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function extrachill_events_guest_list_url( int $event_id ): string {
	return trailingslashit( (string) get_permalink( $event_id ) ) . EXTRACHILL_EVENTS_GUEST_LIST_ENDPOINT . '/';
}

/**
 * Whether an event offers a guest list page.
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function extrachill_events_has_guest_list_page( int $event_id ): bool {
	return $event_id > 0
		&& extrachill_events_perk_enabled( $event_id )
		&& extrachill_events_perk_password_required( $event_id );
}

/**
 * Whether the current request targets an event's /guest-list endpoint.
 *
 * Endpoint query vars are set to '' when present, so presence (not value)
 * is the signal.
 *
 * @return bool
 */
function extrachill_events_is_guest_list_request(): bool {
	global $wp_query;

	return is_singular( 'data_machine_events' )
		&& $wp_query instanceof WP_Query
		&& array_key_exists( EXTRACHILL_EVENTS_GUEST_LIST_ENDPOINT, (array) $wp_query->query );
}

/**
 * 404 events without a guest list; keep the page out of caches and search.
 *
 * @hook template_redirect
 */
function extrachill_events_guest_list_template_redirect(): void {
	if ( ! extrachill_events_is_guest_list_request() ) {
		return;
	}

	if ( ! extrachill_events_has_guest_list_page( (int) get_queried_object_id() ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
}
add_action( 'template_redirect', 'extrachill_events_guest_list_template_redirect', 5 );

/**
 * Render the guest list template instead of the single event template.
 *
 * @hook template_include
 * @param string $template Resolved template.
 * @return string
 */
function extrachill_events_guest_list_template( $template ) {
	if ( is_404() || ! extrachill_events_is_guest_list_request() ) {
		return $template;
	}

	return extrachill_events_has_guest_list_page( (int) get_queried_object_id() )
		? EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/guest-list.php'
		: $template;
}
add_filter( 'template_include', 'extrachill_events_guest_list_template', 99 );

/**
 * Prefix the document title so a shared link reads as the guest list.
 *
 * @hook document_title_parts
 * @param array $parts Title parts.
 * @return array
 */
function extrachill_events_guest_list_title( $parts ) {
	if ( extrachill_events_is_guest_list_request() && isset( $parts['title'] ) ) {
		/* translators: %s: event title. */
		$parts['title'] = sprintf( __( 'Guest list: %s', 'extrachill-events' ), $parts['title'] );
	}
	return $parts;
}
add_filter( 'document_title_parts', 'extrachill_events_guest_list_title' );
