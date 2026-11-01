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
			'copyHint' => __( 'Select and copy the share link above.', 'extrachill-events' ),
		)
	);
	$counts = extrachill_events_get_guest_list_counts( $event_id );
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/guest-list-management.php';
}

/**
 * Append the host panel to the single event page for managers.
 *
 * @param string $content Post content.
 * @return string
 */
function extrachill_events_append_guest_list_management( $content ) {
	if ( ! ec_is_events_site() || ! is_singular( 'data_machine_events' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	ob_start();
	extrachill_events_render_guest_list_management( (int) get_the_ID(), true );
	return $content . ob_get_clean();
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
		'event'    => (string) $event_id,
		'enabled'  => true,
		'text'     => extrachill_events_get_perk_text( $event_id ),
		'capacity' => isset( $_POST['capacity'] ) ? absint( $_POST['capacity'] ) : 0,
	);
	$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords are hashed, not sanitized.
	if ( '' !== $password ) {
		$input['password'] = $password;
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
