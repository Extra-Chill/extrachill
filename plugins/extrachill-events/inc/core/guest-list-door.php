<?php
/**
 * Host door view on /guest-list (extrachill#1246).
 *
 * Decides which /guest-list view a viewer gets (the door view for anyone
 * who manages the event, otherwise the guest view for the access mode),
 * and renders the door view: show title, pass image, paste/upload names,
 * and the A to Z list with big Check in buttons.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which /guest-list view a viewer gets (extrachill#1246).
 *
 * - door:         someone who manages the event: the host door view.
 * - claim:        password mode: name and password in, pass out.
 * - host_managed: host_list mode: a "the host manages this list" notice.
 *                 No password prompt; pass links still open their pass.
 * - going:        going mode: mark Going on the event page for a pass.
 * - pass_only:    no guest list (a ticket-only event): pass links only.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  Viewer user ID (0 when logged out).
 * @return string
 */
function extrachill_events_guest_list_page_view( int $event_id, int $user_id ): string {
	if ( $user_id > 0 && function_exists( 'extrachill_events_user_can_manage_event' ) && extrachill_events_user_can_manage_event( $user_id, $event_id ) ) {
		return 'door';
	}

	// Ticket-only events keep the page for purchased pass links, nothing else.
	if ( ! extrachill_events_perk_enabled( $event_id ) ) {
		return 'pass_only';
	}

	switch ( extrachill_events_get_perk_mode( $event_id ) ) {
		case EXTRACHILL_EVENTS_PERK_MODE_HOST_LIST:
			return 'host_managed';
		case EXTRACHILL_EVENTS_PERK_MODE_PASSWORD:
			return extrachill_events_guest_list_accepts_claims( $event_id ) ? 'claim' : 'host_managed';
		default:
			return 'going';
	}
}

/**
 * Rows the host door view lists: everyone holding a pass that checks in at
 * this event (active or already checked in), A to Z. Going without a pass
 * (host_list and password modes) is not on the door.
 *
 * @param array[] $door_rows extrachill_events_get_door_list_rows()['door_rows'].
 * @return array[] Each gains `checked_in` (bool) and `check_in` (user_id or code target).
 */
function extrachill_events_guest_list_door_rows( array $door_rows ): array {
	$rows = array();
	foreach ( $door_rows as $row ) {
		$status = 'attendee' === ( $row['kind'] ?? '' ) ? (string) ( $row['pass_status'] ?? '' ) : (string) ( $row['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'redeemed' ), true ) ) {
			continue;
		}
		$row['checked_in'] = 'redeemed' === $status;
		$row['name']       = 'attendee' === ( $row['kind'] ?? '' ) ? (string) ( $row['display_name'] ?? '' ) : (string) ( $row['label'] ?? $row['guest_name'] ?? '' );
		$rows[]            = $row;
	}

	return $rows;
}

/**
 * Render the host door view on /guest-list (extrachill#1246): the show
 * title, an add-names form (paste or CSV/TXT upload), and the A to Z list
 * with big Check in buttons. Check-in posts to the same
 * extrachill/redeem-event-pass ability (and its manager permission check
 * and REST nonce) as the event-page door list, via assets/js/rsvp-pass.js.
 *
 * Renders nothing unless the current user manages the event.
 *
 * @param int $event_id Event post ID.
 */
function extrachill_events_render_guest_list_door( int $event_id ): void {
	if ( ! extrachill_events_user_can_manage_event( get_current_user_id(), $event_id ) ) {
		return;
	}

	if ( function_exists( 'wp_enqueue_script' ) && defined( 'EXTRACHILL_EVENTS_PLUGIN_URL' ) ) {
		wp_enqueue_script( 'extrachill-events-rsvp-pass', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/rsvp-pass.js', array( 'wp-api-fetch' ), EXTRACHILL_EVENTS_VERSION, true );
	}

	$door_list      = extrachill_events_get_door_list_rows( $event_id );
	$door_rows      = extrachill_events_guest_list_door_rows( $door_list['door_rows'] );
	$event_title    = (string) get_the_title( $event_id );
	$pass_image_url = extrachill_events_get_pass_image_url( $event_id );
	$pass_image_alt = '' !== $pass_image_url ? extrachill_events_get_pass_image_alt( $event_id ) : '';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counts after the add redirect.
	$added_summary = isset( $_GET['ec_names_added'] ) ? sanitize_text_field( wp_unslash( $_GET['ec_names_added'] ) ) : '';
	$added_counts  = preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $added_summary, $added_match ) ? array_map( 'intval', array_slice( $added_match, 1 ) ) : null;

	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/guest-list-door.php';
}
