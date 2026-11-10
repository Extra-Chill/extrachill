<?php
/**
 * RSVP Pass Integration
 *
 * Event-page composition for the RSVP perk pass feature: the attendee-facing
 * pass card (right after the attendance button) and the host-facing door
 * list (appended to the content, authorized viewers only — available on
 * any event the caller manages, not just perk-enabled ones; see
 * extrachill_events_append_door_list()). Mirrors
 * inc/core/concert-tracking-integration.php's role: this is composition,
 * not domain logic — the domain logic lives in RsvpPassesTable,
 * rsvp-pass-service.php, and event-management-authority.php.
 *
 * @package ExtraChillEvents
 * @since 0.69.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the attendee's RSVP pass card right after the attendance button.
 *
 * Fires at priority 6 on data_machine_events_action_buttons — after the
 * attendance button itself (priority 5, concert-tracking-integration.php).
 * Server-renders the pass when the viewer already holds one (reload
 * persistence); assets/js/rsvp-pass.js fills it in live after a fresh
 * "Going" click without a full page reload.
 *
 * @hook data_machine_events_action_buttons
 * @param int    $post_id Event post ID.
 * @param string $ticket_url Unused here.
 * @param string $timing  Event timing state: 'upcoming' | 'ongoing' | 'past'.
 */
function extrachill_events_render_rsvp_pass_card( $post_id, $ticket_url, $timing = '' ) {
	unset( $ticket_url );

	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	if ( 'past' === $timing ) {
		return;
	}

	$post_id = (int) $post_id;
	if ( ! extrachill_events_perk_enabled( $post_id ) ) {
		return;
	}

	$has_pass = false;
	$code     = '';
	$qr_url   = '';

	if ( is_user_logged_in() && class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		$pass = \ExtraChillEvents\Core\RsvpPassesTable::find_for_user_event( $post_id, get_current_user_id() );
		if ( $pass && \ExtraChillEvents\Core\RsvpPassesTable::STATUS_ACTIVE === $pass['status'] ) {
			$has_pass = true;
			$code     = (string) $pass['code'];
			$qr_url   = function_exists( 'extrachill_events_rsvp_pass_qr_url' ) ? extrachill_events_rsvp_pass_qr_url( $code ) : '';
		}
	}

	// Guest list (extrachill#433, #1246): a curated list (password or
	// host_list mode) lives on its own /guest-list page, so the public event
	// page shows nothing about it — except a member's own pass, when they
	// already hold one.
	$gated = extrachill_events_guest_list_is_gated( $post_id );
	if ( $gated && ! $has_pass ) {
		return;
	}

	$event_id   = $post_id;
	$perk_text  = extrachill_events_get_perk_text( $post_id );
	$guest_form = ! is_user_logged_in() && ! $gated;
	$guest_list = extrachill_events_get_guest_list_counts( $post_id );
	// Read by templates/rsvp-pass.php; only reached here with a pass in hand.
	$password_required = $gated;

	$perk_image_url = function_exists( 'extrachill_events_get_pass_image_url' ) ? extrachill_events_get_pass_image_url( $post_id ) : '';
	$perk_image_alt = '' !== $perk_image_url ? extrachill_events_get_pass_image_alt( $post_id ) : '';
	$pass_card_html = '' === $perk_image_url && function_exists( 'extrachill_events_render_pass_card' ) ? extrachill_events_render_pass_card( $post_id ) : '';

	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/rsvp-pass.php';
}
add_action( 'data_machine_events_action_buttons', 'extrachill_events_render_rsvp_pass_card', 6, 3 );

/**
 * Append the host-facing door list to the event content.
 *
 * Authorized hosts only (extrachill_events_user_can_manage_event()); every
 * other viewer — including a logged-in attendee — sees nothing added.
 *
 * Available on ANY event the caller manages, perk or not: a guest list is
 * useful to a host for planning/capacity/outreach regardless of whether
 * that event happens to offer an RSVP perk (extrachill-events#879). The
 * pass/redeem column is perk-specific and only renders when the event has
 * a perk enabled — see templates/door-list.php's own `$perk_enabled` gate.
 *
 * @param string $content Post content.
 * @return string
 */
function extrachill_events_append_door_list( $content ) {
	if ( ! is_singular( 'data_machine_events' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return $content;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return $content;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id || ! function_exists( 'extrachill_events_user_can_manage_event' ) || ! extrachill_events_user_can_manage_event( $user_id, $post_id ) ) {
		return $content;
	}

	if ( ! function_exists( 'ec_users_get_event_attendees_full' ) || ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return $content;
	}

	$door_list      = extrachill_events_get_door_list_rows( (int) $post_id );
	$event_id       = $post_id;
	$perk_enabled   = $door_list['perk_enabled'];
	$perk_text      = $door_list['perk_text'];
	$guest_list     = $door_list['guest_list'];
	$guest_list_url = $door_list['guest_list_url'];
	$rows           = $door_list['rows'];
	$guest_rows     = $door_list['guest_rows'];
	$door_rows      = $door_list['door_rows'];

	// The type-to-filter box lives in the host panel script; the panel
	// usually enqueued it already.
	if ( $door_rows && ! wp_script_is( 'ec-guest-list-management', 'enqueued' ) ) {
		wp_enqueue_script( 'ec-guest-list-management', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/guest-list-management.js', array(), EXTRACHILL_EVENTS_VERSION, true );
	}

	ob_start();
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/door-list.php';
	$door_list_html = ob_get_clean();

	return $content . $door_list_html;
}
add_filter( 'the_content', 'extrachill_events_append_door_list', 20 );

/**
 * Everything the door renders for an event: account RSVPs with their pass
 * state, account-less guests (comps, band adds, password claims, tickets),
 * and the venue's and promoter's standing lists, merged A to Z.
 *
 * Shared by the event-page door list and the /guest-list host door view
 * (extrachill#1246). Authorization is the caller's job.
 *
 * @param int $event_id Event post ID.
 * @return array{perk_enabled: bool, perk_text: string, guest_list: array, guest_list_url: string, rows: array[], guest_rows: array[], door_rows: array[]}
 */
function extrachill_events_get_door_list_rows( int $event_id ): array {
	$perk_enabled = extrachill_events_perk_enabled( $event_id );

	$attendees = function_exists( 'ec_users_get_event_attendees_full' ) ? (array) ec_users_get_event_attendees_full( $event_id ) : array();
	$passes    = $perk_enabled && class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ? \ExtraChillEvents\Core\RsvpPassesTable::list_for_event( $event_id ) : array();

	$rows = array();
	foreach ( $attendees as $attendee ) {
		$attendee_user_id = (int) $attendee['user_id'];
		$pass             = $passes[ $attendee_user_id ] ?? null;
		$rows[]           = array(
			'user_id'      => $attendee_user_id,
			'display_name' => (string) $attendee['display_name'],
			'marked_at'    => ! empty( $attendee['marked_at'] )
				? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $attendee['marked_at'] )
				: '',
			'pass_status'  => $pass ? (string) $pass['status'] : '',
			'redeemed_at'  => ( $pass && ! empty( $pass['redeemed_at'] ) )
				? mysql2date( get_option( 'time_format' ) . ' ' . get_option( 'date_format' ), (string) $pass['redeemed_at'] )
				: '',
		);
	}

	$perk_text      = extrachill_events_get_perk_text( $event_id );
	$guest_list     = $perk_enabled ? extrachill_events_get_guest_list_counts( $event_id ) : array();
	$guest_list_url = ( function_exists( 'extrachill_events_has_guest_list_page' ) && extrachill_events_has_guest_list_page( $event_id ) )
		? extrachill_events_guest_list_url( $event_id )
		: '';
	// Each row carries its source: the invited band or management that
	// added it (extrachill#1128), or the host who comped it (#1119).
	$guest_rows = ( '' !== $guest_list_url && function_exists( 'extrachill_events_door_list_guest_rows' ) )
		? extrachill_events_door_list_guest_rows( $event_id )
		: array();
	// The venue's and promoter's standing lists (extrachill#1130) work at
	// every show they're tagged on, guest list perk or not.
	if ( function_exists( 'extrachill_events_owner_list_door_rows' ) && class_exists( '\\ExtraChillEvents\\Core\\OwnerGuestListTable' ) ) {
		$guest_rows = array_merge( $guest_rows, extrachill_events_owner_list_door_rows( $event_id ) );
	}

	// One A to Z list for the door: accounts, guests, and owner's lists
	// interleaved by name, each "+N" right after its primary (extrachill#1142).
	$door_rows = array();
	foreach ( $rows as $row ) {
		$door_rows[] = array( 'kind' => 'attendee' ) + $row;
	}
	foreach ( $guest_rows as $guest_row ) {
		$door_rows[] = array( 'kind' => 'guest' ) + $guest_row;
	}
	$door_rows = function_exists( 'extrachill_events_sort_guest_rows' ) ? extrachill_events_sort_guest_rows( $door_rows ) : $door_rows;

	return array(
		'perk_enabled'   => $perk_enabled,
		'perk_text'      => $perk_text,
		'guest_list'     => $guest_list,
		'guest_list_url' => $guest_list_url,
		'rows'           => $rows,
		'guest_rows'     => $guest_rows,
		'door_rows'      => $door_rows,
	);
}

/**
 * Enqueue the pass-watcher / door-list / verify-redeem script.
 *
 * Fires on perk-enabled event pages (pass card + door-list redeem) and on
 * the RSVP verify page (QR-scan redeem, slice 2). Depends on
 * 'wp-api-fetch': WordPress core auto-wires the REST root URL and nonce
 * middleware for that handle on every enqueue (front end included, see
 * wp_default_packages_inline_scripts()), so no custom nonce plumbing is
 * needed here.
 */
function extrachill_events_enqueue_rsvp_pass_assets() {
	$is_verify_page = function_exists( 'extrachill_events_is_rsvp_verify_page' ) && extrachill_events_is_rsvp_verify_page();
	$is_guest_list  = function_exists( 'extrachill_events_is_guest_list_request' ) && extrachill_events_is_guest_list_request();
	$post_id        = 0;

	if ( ! $is_verify_page ) {
		if ( ! is_singular( 'data_machine_events' ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! extrachill_events_perk_enabled( $post_id ) ) {
			return;
		}
	}

	wp_enqueue_script(
		'extrachill-events-rsvp-pass',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/rsvp-pass.js',
		array( 'wp-api-fetch' ),
		EXTRACHILL_EVENTS_VERSION,
		true
	);

	// Logged-out visitors get the email-only guest RSVP form, gated by
	// Turnstile at the extrachill-api route.
	$gated      = $post_id && extrachill_events_guest_list_is_gated( $post_id );
	$guest_form = ! $is_verify_page && ! $gated && ! is_user_logged_in();
	$claim_form = $is_guest_list && $post_id && extrachill_events_guest_list_accepts_claims( $post_id );
	if ( ( $guest_form || $claim_form ) && function_exists( 'ec_enqueue_turnstile_script' ) ) {
		ec_enqueue_turnstile_script();
	}

	wp_localize_script(
		'extrachill-events-rsvp-pass',
		'ecRsvpPass',
		array(
			'eventId'       => $post_id,
			'qrBaseUrl'     => function_exists( 'extrachill_events_rsvp_pass_qr_base_url' ) ? extrachill_events_rsvp_pass_qr_base_url() : '',
			'guestRsvpPath' => $guest_form ? '/extrachill/v1/events/' . $post_id . '/guest-rsvp' : '',
			'guestListPath' => $is_guest_list ? '/extrachill/v1/events/' . $post_id . '/guest-list-pass' : '',
			'guestRsvpText' => array(
				'sending'  => __( 'Sending your pass...', 'extrachill-events' ),
				'success'  => __( 'Check your inbox. Your pass is on its way.', 'extrachill-events' ),
				'error'    => __( 'Something went wrong. Check your email address and try again.', 'extrachill-events' ),
				'claimed'  => __( "You're on the list. Show this pass at the door.", 'extrachill-events' ),
				'needPw'   => __( 'Enter the guest list password.', 'extrachill-events' ),
				'needName' => __( 'Enter your name.', 'extrachill-events' ),
				'badEmail' => __( 'Check the email address, or leave it blank.', 'extrachill-events' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_enqueue_rsvp_pass_assets' );
