<?php
/**
 * Shared guest-list host controls and authorized form/QR endpoints.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the host guest-list panel (settings, counts, share link, QR, door list).
 *
 * Renders nothing unless the current user can manage the event.
 *
 * @param int  $event_id Event post ID.
 * @param bool $settings Whether to include the password/capacity form (read by the template).
 */
function extrachill_events_render_guest_list_management( int $event_id, bool $settings = false ): void {
	if ( 'data_machine_events' !== get_post_type( $event_id ) || ! extrachill_events_user_can_manage_event( get_current_user_id(), $event_id ) ) {
		return;
	}
	wp_enqueue_script( 'ec-guest-list-management', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/guest-list-management.js', array(), EXTRACHILL_EVENTS_VERSION, true );
	wp_localize_script(
		'ec-guest-list-management',
		'ecGuestListManagement',
		array(
			'copied'   => __( 'Share link copied.', 'extrachill-events' ),
			'copyHint' => __( 'Select and copy the link above.', 'extrachill-events' ),
		)
	);
	$counts             = extrachill_events_get_guest_list_counts( $event_id );
	$allotments_enabled = extrachill_events_guest_list_allotments_enabled( $event_id );
	$parties            = function_exists( 'extrachill_events_list_guest_list_parties' ) ? extrachill_events_list_guest_list_parties( $event_id ) : array();
	$parties            = is_wp_error( $parties ) ? array() : (array) ( $parties['parties'] ?? array() );
	$requests           = function_exists( 'extrachill_events_list_guest_list_requests' ) ? extrachill_events_list_guest_list_requests( $event_id ) : array();
	$requests           = is_wp_error( $requests ) ? array() : (array) ( $requests['requests'] ?? array() );
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/guest-list-management.php';
}

/**
 * Whether it is the event's show day: the start date, in the site timezone.
 *
 * The event-dates table stores start_datetime as site-local wall time, so the
 * comparison is against "today" in wp_timezone(), not UTC. A 23:30 show is
 * still on its show day at 23:00 local even though UTC has rolled over.
 *
 * @param int      $event_id Event post ID.
 * @param int|null $now      Unix timestamp to evaluate at. Defaults to now.
 * @return bool False when the event has no usable start date.
 */
function extrachill_events_is_event_show_day( int $event_id, ?int $now = null ): bool {
	$dates = function_exists( 'datamachine_get_event_dates' ) ? datamachine_get_event_dates( $event_id ) : null;
	if ( ! is_object( $dates ) || empty( $dates->start_datetime ) ) {
		return false;
	}

	$start_day = substr( (string) $dates->start_datetime, 0, 10 );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_day ) ) {
		return false;
	}

	$today = ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );

	return $start_day === $today;
}

/**
 * Whether to pin the host panel to the top of the event page (extrachill#1139).
 *
 * True only on show day, for a user who can manage the event, when the guest
 * list is enabled. Everyone else (and hosts on other days) keeps the panel in
 * its usual place below the event content.
 *
 * @param int      $event_id Event post ID.
 * @param int      $user_id  Viewer user ID.
 * @param int|null $now      Unix timestamp to evaluate at. Defaults to now.
 * @return bool
 */
function extrachill_events_should_pin_guest_list( int $event_id, int $user_id, ?int $now = null ): bool {
	return $event_id > 0
		&& $user_id > 0
		&& extrachill_events_has_guest_list_page( $event_id )
		&& extrachill_events_is_event_show_day( $event_id, $now )
		&& extrachill_events_user_can_manage_event( $user_id, $event_id );
}

/**
 * Add the host panel to the single event page for managers.
 *
 * Appended below the event content normally; on show day it is prepended
 * instead, above the event details block, so hosts at the door find the
 * guest list and the door-list link without scrolling. Rendered once either way.
 *
 * @param string $content Post content.
 * @return string
 */
function extrachill_events_append_guest_list_management( $content ) {
	if ( ! ec_is_events_site() || ! is_singular( 'data_machine_events' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$event_id = (int) get_the_ID();
	ob_start();
	extrachill_events_render_guest_list_management( $event_id, true );
	$panel = (string) ob_get_clean();
	if ( '' === $panel ) {
		return $content;
	}

	return extrachill_events_should_pin_guest_list( $event_id, get_current_user_id() )
		? $panel . $content
		: $content . $panel;
}
add_filter( 'the_content', 'extrachill_events_append_guest_list_management', 19 );

/**
 * Save the host panel form through the extrachill/set-event-perk ability.
 */
function extrachill_events_save_guest_list_management(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_list_settings_' . $event_id );
	if ( ! ec_is_events_site() || 'data_machine_events' !== get_post_type( $event_id ) ) {
		wp_die( esc_html__( 'You cannot manage this event.', 'extrachill-events' ), '', array( 'response' => 403 ) );
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/set-event-perk' ) : null;
	if ( ! $ability ) {
		wp_die( esc_html__( 'Guest list settings are unavailable.', 'extrachill-events' ), '', array( 'response' => 503 ) );
	}

	$input    = array(
		'event'              => (string) $event_id,
		'enabled'            => true,
		'text'               => extrachill_events_get_perk_text( $event_id ),
		'capacity'           => isset( $_POST['capacity'] ) ? absint( $_POST['capacity'] ) : 0,
		// Unchecked box = off: the checkbox always renders with this form.
		'allotments_enabled' => ! empty( $_POST['allotments_enabled'] ),
	);
	$input['tickets_enabled'] = ! empty( $_POST['tickets_enabled'] );
	$input['ticket_price_minor'] = isset( $_POST['ticket_price_minor'] ) ? (int) $_POST['ticket_price_minor'] : 0;
	$input['ticket_quantity'] = isset( $_POST['ticket_quantity'] ) ? (int) $_POST['ticket_quantity'] : 0;
	if ( ! empty( $_POST['ticket_seller'] ) && is_string( $_POST['ticket_seller'] ) ) {
		$seller_parts = explode( ':', sanitize_text_field( wp_unslash( $_POST['ticket_seller'] ) ), 2 );
		$input['ticket_seller'] = array( 'type' => $seller_parts[0], 'id' => (int) ( $seller_parts[1] ?? 0 ) );
	}
	$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords are hashed, not sanitized.
	if ( '' !== $password ) {
		$input['password'] = $password;
	}

	if ( ! empty( $_POST['remove_pass_image'] ) ) {
		$input['image_id'] = 0;
	}
	if ( ! empty( $_FILES['pass_image']['name'] ) ) {
		$image_id = extrachill_events_handle_pass_image_upload( $event_id, get_current_user_id() );
		if ( is_wp_error( $image_id ) ) {
			wp_die( esc_html( $image_id->get_error_message() ), '', array( 'response' => (int) ( $image_id->get_error_data()['status'] ?? 400 ) ) );
		}
		$input['image_id'] = $image_id;
	}

	// The ability owns validation, authorization, and persistence.
	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
	}

	wp_safe_redirect( get_permalink( $event_id ) . '#ec-guest-list-management' );
	exit;
}
add_action( 'admin_post_ec_guest_list_settings', 'extrachill_events_save_guest_list_management' );

/** Largest pass image a host may upload from the panel, in bytes. */
const EXTRACHILL_EVENTS_PASS_IMAGE_MAX_BYTES = 10 * MB_IN_BYTES;

/**
 * Upload a pass image from the host panel into the media library.
 *
 * Venue and promoter team members manage events without holding
 * upload_files, so authorization is the event-management check, not the
 * media capability. Only raster images within the size cap are accepted.
 * The new attachment is attached to the event.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  Uploading user.
 * @return int|WP_Error Attachment ID.
 */
function extrachill_events_handle_pass_image_upload( int $event_id, int $user_id ) {
	if ( ! extrachill_events_user_can_manage_event( $user_id, $event_id ) ) {
		return new WP_Error( 'forbidden', __( 'You cannot manage this event.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce checked by the caller; the file array is validated below and handled by core.
	$file = isset( $_FILES['pass_image'] ) && is_array( $_FILES['pass_image'] ) ? $_FILES['pass_image'] : array();
	if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
		return new WP_Error( 'invalid_pass_image', __( 'The pass image did not upload. Try again.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	if ( (int) ( $file['size'] ?? 0 ) > EXTRACHILL_EVENTS_PASS_IMAGE_MAX_BYTES ) {
		return new WP_Error( 'invalid_pass_image', __( 'The pass image must be 10 MB or smaller.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$allowed = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);
	$check   = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], $allowed );
	if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
		return new WP_Error( 'invalid_pass_image', __( 'The pass image must be a JPG, PNG, GIF, or WebP image.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	// admin-post.php already loads these through wp-admin/includes/admin.php.
	if ( ! function_exists( 'media_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$attachment_id = media_handle_upload(
		'pass_image',
		$event_id,
		array( 'post_author' => $user_id ),
		array(
			'test_form' => false,
			'mimes'     => $allowed,
		)
	);
	if ( is_wp_error( $attachment_id ) ) {
		return new WP_Error( 'invalid_pass_image', $attachment_id->get_error_message(), array( 'status' => 400 ) );
	}
	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		wp_delete_attachment( $attachment_id, true );
		return new WP_Error( 'invalid_pass_image', __( 'The pass image must be an image.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	return (int) $attachment_id;
}

/**
 * Run a host guest-list ability from a panel or door-list form, then return
 * to the event page. The ability owns validation and authorization.
 *
 * @param string $ability_name Ability to execute.
 * @param array  $input        Ability input.
 * @return array Ability result. Dies on error.
 */
function extrachill_events_run_guest_list_form_ability( string $ability_name, array $input ): array {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
	if ( ! $ability ) {
		wp_die( esc_html__( 'The guest list is unavailable.', 'extrachill-events' ), '', array( 'response' => 503 ) );
	}

	$result = $ability->execute( $input );
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

	return (array) $result;
}

/**
 * Host panel "Add a guest" form, through extrachill/add-guest-list-name.
 */
function extrachill_events_add_guest_list_name_from_panel(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_list_add_' . $event_id );

	$result = extrachill_events_run_guest_list_form_ability(
		'extrachill/add-guest-list-name',
		array(
			'event_id' => $event_id,
			'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'email'    => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
		)
	);

	wp_safe_redirect( add_query_arg( 'ec_guest_added', rawurlencode( (string) ( $result['code'] ?? '' ) ), get_permalink( $event_id ) ) . '#ec-guest-list-management' );
	exit;
}
add_action( 'admin_post_ec_guest_list_add', 'extrachill_events_add_guest_list_name_from_panel' );

/**
 * Door list "Remove" button, through extrachill/remove-guest-list-name.
 */
function extrachill_events_remove_guest_list_name_from_door_list(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_list_remove_' . $event_id );

	extrachill_events_run_guest_list_form_ability(
		'extrachill/remove-guest-list-name',
		array(
			'event_id' => $event_id,
			'code'     => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
		)
	);

	wp_safe_redirect( get_permalink( $event_id ) . '#ec-door-list' );
	exit;
}
add_action( 'admin_post_ec_guest_list_remove', 'extrachill_events_remove_guest_list_name_from_door_list' );

/**
 * Host panel "Invite a band" form, through extrachill/create-guest-list-party.
 *
 * Returns to the panel with the new link so the host can also text it; the
 * raw token exists only in this redirect and the invite email.
 */
function extrachill_events_invite_guest_list_party_from_panel(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_party_invite_' . $event_id );

	$input = array(
		'event_id' => $event_id,
		'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
		'email'    => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
	);
	if ( isset( $_POST['allotment'] ) && '' !== $_POST['allotment'] ) {
		$input['allotment'] = absint( $_POST['allotment'] );
	}

	$result = extrachill_events_run_guest_list_form_ability( 'extrachill/create-guest-list-party', $input );

	wp_safe_redirect( add_query_arg( 'ec_party_invite', rawurlencode( (string) ( $result['invite_url'] ?? '' ) ), get_permalink( $event_id ) ) . '#ec-guest-list-parties' );
	exit;
}
add_action( 'admin_post_ec_guest_party_invite', 'extrachill_events_invite_guest_list_party_from_panel' );

/**
 * Host panel "Revoke" button, through extrachill/revoke-guest-list-party.
 */
function extrachill_events_revoke_guest_list_party_from_panel(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_party_manage_' . $event_id );

	extrachill_events_run_guest_list_form_ability(
		'extrachill/revoke-guest-list-party',
		array(
			'event_id' => $event_id,
			'party_id' => isset( $_POST['party_id'] ) ? absint( $_POST['party_id'] ) : 0,
		)
	);

	wp_safe_redirect( get_permalink( $event_id ) . '#ec-guest-list-parties' );
	exit;
}
add_action( 'admin_post_ec_guest_party_revoke', 'extrachill_events_revoke_guest_list_party_from_panel' );

/**
 * Host panel per-party spots form, through extrachill/set-guest-list-party-allotment.
 */
function extrachill_events_set_guest_list_party_allotment_from_panel(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_party_manage_' . $event_id );

	extrachill_events_run_guest_list_form_ability(
		'extrachill/set-guest-list-party-allotment',
		array(
			'event_id'  => $event_id,
			'party_id'  => isset( $_POST['party_id'] ) ? absint( $_POST['party_id'] ) : 0,
			'allotment' => isset( $_POST['allotment'] ) ? absint( $_POST['allotment'] ) : 0,
		)
	);

	wp_safe_redirect( get_permalink( $event_id ) . '#ec-guest-list-parties' );
	exit;
}
add_action( 'admin_post_ec_guest_party_allotment', 'extrachill_events_set_guest_list_party_allotment_from_panel' );

/**
 * Host panel "Pending requests" Accept button, through
 * extrachill/approve-guest-list-request (extrachill#1129).
 */
function extrachill_events_approve_guest_list_request_from_panel(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_party_manage_' . $event_id );

	$input = array(
		'event_id' => $event_id,
		'party_id' => isset( $_POST['party_id'] ) ? absint( $_POST['party_id'] ) : 0,
		'kind'     => isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '',
	);
	if ( isset( $_POST['spots'] ) && '' !== $_POST['spots'] ) {
		$input['spots'] = absint( $_POST['spots'] );
	}

	$result = extrachill_events_run_guest_list_form_ability( 'extrachill/approve-guest-list-request', $input );

	// An accepted uninvited band: show its new link once, like a fresh invite.
	$url = ! empty( $result['invite_url'] )
		? add_query_arg( 'ec_party_invite', rawurlencode( (string) $result['invite_url'] ), get_permalink( $event_id ) )
		: get_permalink( $event_id );
	wp_safe_redirect( $url . '#ec-guest-list-parties' );
	exit;
}
add_action( 'admin_post_ec_guest_request_approve', 'extrachill_events_approve_guest_list_request_from_panel' );

/**
 * Host panel "Pending requests" Deny button, through
 * extrachill/deny-guest-list-request (extrachill#1129).
 */
function extrachill_events_deny_guest_list_request_from_panel(): void {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	check_admin_referer( 'ec_guest_party_manage_' . $event_id );

	extrachill_events_run_guest_list_form_ability(
		'extrachill/deny-guest-list-request',
		array(
			'event_id' => $event_id,
			'party_id' => isset( $_POST['party_id'] ) ? absint( $_POST['party_id'] ) : 0,
			'kind'     => isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '',
		)
	);

	wp_safe_redirect( get_permalink( $event_id ) . '#ec-guest-list-requests' );
	exit;
}
add_action( 'admin_post_ec_guest_request_deny', 'extrachill_events_deny_guest_list_request_from_panel' );

/**
 * Serve the guest-list share QR (PNG) to event managers.
 */
function extrachill_events_serve_guest_list_qr(): void {
	// Read-only image for a logged-in manager; authorization below, no state change.
	$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! ec_is_events_site() || ! extrachill_events_user_can_manage_event( get_current_user_id(), $event_id ) || ! extrachill_events_has_guest_list_page( $event_id ) ) {
		status_header( 403 );
		exit;
	}
	$url    = extrachill_events_guest_list_url( $event_id );
	$grant  = extrachill_events_rsvp_pass_qr_permission_grant( $url );
	$result = null;
	if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'extrachill/generate-qr-code' ) ) {
		add_filter( 'wp_ability_permission_result', $grant, 10, 3 );
		try {
			$result = wp_get_ability( 'extrachill/generate-qr-code' )->execute(
				array(
					'url'  => $url,
					'size' => 500,
				)
			);
		} finally {
			remove_filter( 'wp_ability_permission_result', $grant, 10 );
		}
	}
	if ( ! is_array( $result ) || empty( $result['image'] ) ) {
		status_header( 503 );
		exit;
	}
	nocache_headers();
	header( 'Content-Type: image/png' );
	if ( isset( $_GET['download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		header( 'Content-Disposition: attachment; filename="guest-list-' . $event_id . '.png"' );
	}
	echo base64_decode( (string) $result['image'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary image from the existing QR ability.
	exit;
}
add_action( 'admin_post_ec_guest_list_qr', 'extrachill_events_serve_guest_list_qr' );
