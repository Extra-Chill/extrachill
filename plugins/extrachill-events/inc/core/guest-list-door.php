<?php
/**
 * Host screen on /guest-list (extrachill#1246, #1702).
 *
 * Decides who may open /guest-list and which view they get (the host
 * screen for anyone who manages the event, otherwise the guest view for
 * the access mode), renders the host screen, and puts the one "Guest
 * Check-In" button on the event page for managers.
 *
 * The host screen is the single home for door and host tools: the A to Z
 * check-in list first, then paste/upload names, then the host tools
 * (guest list settings, counts, share link and QR, band invites and
 * requests, owner's lists) in a collapsible section below. The event page
 * itself stays the public listing.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a viewer may open an event's /guest-list page (extrachill#1702).
 *
 * Guests get exactly the existing rule, extrachill_events_has_guest_list_page():
 * an enabled perk or tickets. Anyone who manages the event can always open
 * it, so they can reach the host screen and turn the guest list on from
 * there even when the perk is off and there are no tickets.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  Viewer user ID (0 when logged out).
 * @return bool
 */
function extrachill_events_user_can_reach_guest_list_page( int $event_id, int $user_id ): bool {
	if ( $event_id <= 0 ) {
		return false;
	}

	if ( extrachill_events_has_guest_list_page( $event_id ) ) {
		return true;
	}

	return $user_id > 0
		&& function_exists( 'extrachill_events_user_can_manage_event' )
		&& extrachill_events_user_can_manage_event( $user_id, $event_id );
}

/**
 * The one manager entry point on the event page (extrachill#1702): a red
 * "Guest Check-In" button that opens the host screen on /guest-list.
 *
 * Managers only; everyone else sees nothing. Rendered on every event a
 * viewer manages, guest list on or off, since the host screen is where the
 * list gets turned on. Fires first on data_machine_events_action_buttons so
 * it leads the action row for the people who work the door.
 *
 * @hook data_machine_events_action_buttons
 * @param int    $post_id    Event post ID.
 * @param string $ticket_url Unused here.
 * @param string $timing     Unused here.
 */
function extrachill_events_render_guest_check_in_button( $post_id, $ticket_url = '', $timing = '' ): void {
	unset( $ticket_url, $timing );

	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	$post_id = (int) $post_id;
	$user_id = (int) get_current_user_id();
	if ( $post_id <= 0 || $user_id <= 0 || ! extrachill_events_user_can_manage_event( $user_id, $post_id ) ) {
		return;
	}
	?>
	<a class="button-danger button-medium" href="<?php echo esc_url( extrachill_events_guest_list_url( $post_id ) ); ?>"><?php esc_html_e( 'Guest Check-In', 'extrachill-events' ); ?></a>
	<?php
}
add_action( 'data_machine_events_action_buttons', 'extrachill_events_render_guest_check_in_button', 4, 3 );

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
		$row['status']        = $status;
		$row['label']         = (string) ( $row['label'] ?? $row['display_name'] ?? '' );
		$row['source']        = (string) ( $row['source'] ?? '' );
		$row['source_kind']   = (string) ( $row['source_kind'] ?? 'attendee' );
		$row['ticket_type']   = (string) ( $row['ticket_type'] ?? '' );
		$row['checked_in_at'] = (string) ( $row['checked_in_at'] ?? '' );
		$row['checked_in']    = 'redeemed' === $status;
		$row['name']          = 'attendee' === ( $row['kind'] ?? '' ) ? (string) ( $row['display_name'] ?? '' ) : $row['label'];
		$rows[]               = $row;
	}

	return $rows;
}

/**
 * Accounts marked Going with no pass to check in (extrachill-events#879):
 * every RSVP on an event without a perk, or a Going without a pass. They
 * are not on the check-in list, so the host tools list them for planning.
 * Cancelled RSVPs (revoked passes) are left out.
 *
 * @param array[] $door_rows extrachill_events_get_door_list_rows()['door_rows'].
 * @return array[] Each attendee row as given: display_name, marked_at, user_id.
 */
function extrachill_events_guest_list_going_rows( array $door_rows ): array {
	return array_values(
		array_filter(
			$door_rows,
			static function ( $row ) {
				return 'attendee' === ( $row['kind'] ?? '' ) && '' === (string) ( $row['pass_status'] ?? '' );
			}
		)
	);
}

/**
 * Host tools markup for the host screen (extrachill#1702): the guest list
 * panel (settings, counts, share link and QR, add a guest, Eventbrite
 * import, band invites and requests) and the owner's-list panels. Each
 * renderer checks the viewer's authority itself.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function extrachill_events_guest_list_host_tools_html( int $event_id ): string {
	ob_start();
	if ( function_exists( 'extrachill_events_render_guest_list_management' ) ) {
		extrachill_events_render_guest_list_management( $event_id, true );
	}
	if ( function_exists( 'extrachill_events_render_owner_guest_lists' ) ) {
		extrachill_events_render_owner_guest_lists( $event_id );
	}
	return (string) ob_get_clean();
}

/**
 * Whether the host tools section starts open: when the guest list is off
 * (the host came to turn it on), or after a host-tools form redirect
 * (`ec_host_tools`, set by extrachill_events_guest_list_host_tools_url()).
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function extrachill_events_guest_list_host_tools_open( int $event_id ): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirects.
	return ! extrachill_events_has_guest_list_page( $event_id ) || isset( $_GET['ec_host_tools'] );
}

/**
 * Render the host screen on /guest-list (extrachill#1246, #1702): the show
 * title, the A to Z list with big Check in buttons and a find-a-name box,
 * an add-names form (paste or CSV/TXT upload), then the host tools in a
 * collapsible section. Check-in posts to the extrachill/redeem-event-pass
 * ability (and its manager permission check and REST nonce) via
 * assets/js/rsvp-pass.js; copy, confirm, and filter come from
 * assets/js/guest-list-management.js.
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
		// Find a name, Remove confirms, and the host tools' copy buttons.
		wp_enqueue_script( 'ec-guest-list-management', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/guest-list-management.js', array(), EXTRACHILL_EVENTS_VERSION, true );
		if ( function_exists( 'wp_localize_script' ) ) {
			wp_localize_script(
				'ec-guest-list-management',
				'ecGuestListManagement',
				array(
					'copied'   => __( 'Share link copied.', 'extrachill-events' ),
					'copyHint' => __( 'Select and copy the link above.', 'extrachill-events' ),
				)
			);
		}
	}

	$door_list       = extrachill_events_get_door_list_rows( $event_id );
	$door_rows       = extrachill_events_guest_list_door_rows( $door_list['door_rows'] );
	$going_rows      = extrachill_events_guest_list_going_rows( $door_list['door_rows'] );
	$has_guest_list  = extrachill_events_has_guest_list_page( $event_id );
	$event_title     = (string) get_the_title( $event_id );
	$pass_image_url  = extrachill_events_get_pass_image_url( $event_id );
	$pass_image_alt  = '' !== $pass_image_url ? extrachill_events_get_pass_image_alt( $event_id ) : '';
	$host_tools_html = extrachill_events_guest_list_host_tools_html( $event_id );
	$host_tools_open = extrachill_events_guest_list_host_tools_open( $event_id );
	// Sales report, door list CSV and the Buy tickets QR (extrachill#1746).
	$buy_tickets_url   = function_exists( 'extrachill_events_door_buy_tickets_url' ) ? extrachill_events_door_buy_tickets_url( $event_id ) : '';
	$buy_tickets_qr    = '' !== $buy_tickets_url ? extrachill_events_buy_tickets_qr_url( $event_id ) : '';
	$ticket_report     = function_exists( 'extrachill_events_door_ticket_report' ) ? extrachill_events_door_ticket_report( $event_id ) : null;
	$door_list_csv_url = function_exists( 'extrachill_events_door_list_csv_url' ) ? extrachill_events_door_list_csv_url( $event_id ) : '';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counts after the add redirect.
	$added_summary = isset( $_GET['ec_names_added'] ) ? sanitize_text_field( wp_unslash( $_GET['ec_names_added'] ) ) : '';
	$added_counts  = preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $added_summary, $added_match ) ? array_map( 'intval', array_slice( $added_match, 1 ) ) : null;

	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/guest-list-door.php';
}
