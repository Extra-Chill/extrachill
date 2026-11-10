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
 * Every event with an enabled perk (any access mode) or tickets has the
 * page; every other event answers 404 at this URL. Hosts get the door view
 * here (extrachill#1246); guests get the claim form in password mode, a
 * "managed by the host" notice in host_list mode, and pass links open the
 * pass in every mode without a password prompt. The page is never cached
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
 * Every enabled perk has one, whatever its access mode (extrachill#1246):
 * hosts add names in every mode, and each added name's pass link opens on
 * this page. Ticketed events keep theirs for purchased pass links.
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function extrachill_events_has_guest_list_page( int $event_id ): bool {
	$ticket_offer = get_post_meta( $event_id, '_extrachill_ticket_offer', true );
	// Keep purchased pass links usable even when ticket sales are disabled.
	$has_tickets = is_array( $ticket_offer ) && ! empty( $ticket_offer['ticket_seller']['id'] );
	return $event_id > 0 && ( $has_tickets || extrachill_events_perk_enabled( $event_id ) );
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

/**
 * Guest list page "Request guest list spots" form (extrachill#1129): a
 * band or manager not invited to the show asks the venue for spots.
 *
 * Abuse guards: a nonce, a honeypot field (a filled one gets the normal
 * "sent" page and nothing is saved), a required email, and the per-IP and
 * per-email rate limits inside extrachill_events_request_guest_list_party().
 * The request itself is inert until a host accepts it.
 */
function extrachill_events_guest_list_request_from_page(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_party_request_' . $event_id );

	if ( 'data_machine_events' !== get_post_type( $event_id ) || ! extrachill_events_has_guest_list_page( $event_id ) ) {
		wp_die( esc_html__( 'This event has no guest list.', 'extrachill-events' ), '', array( 'response' => 404 ) );
	}

	$sent_url = add_query_arg( 'ec_request', 'sent', extrachill_events_guest_list_url( $event_id ) ) . '#ec-guest-list-request';

	// Honeypot: people never see this field; bots fill it.
	if ( ! empty( $_POST['website'] ) ) {
		wp_safe_redirect( $sent_url );
		exit;
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/request-guest-list-party' ) : null;
	if ( ! $ability ) {
		wp_die( esc_html__( 'The guest list is unavailable.', 'extrachill-events' ), '', array( 'response' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'event_id'  => $event_id,
			'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'email'     => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'spots'     => isset( $_POST['spots'] ) ? absint( $_POST['spots'] ) : 0,
			'names'     => isset( $_POST['names'] ) ? sanitize_textarea_field( wp_unslash( $_POST['names'] ) ) : '',
			'note'      => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
			'client_ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		)
	);
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		wp_die(
			esc_html( $result->get_error_message() ),
			'',
			array(
				'response'  => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400,
				'back_link' => true,
			)
		);
	}

	wp_safe_redirect( $sent_url );
	exit;
}
add_action( 'admin_post_ec_guest_party_request', 'extrachill_events_guest_list_request_from_page' );
add_action( 'admin_post_nopriv_ec_guest_party_request', 'extrachill_events_guest_list_request_from_page' );
