<?php
/**
 * Owner's guest list panel on the event page (extrachill#1130).
 *
 * Under a show's host panel, each venue or promoter on the show whose team
 * the viewer is on gets "Owner's list for <name>": everyone on the standing
 * list with a copy-able pass link and Remove, plus a name + optional email
 * form. Forms go through admin-post into the owner's-list abilities, which
 * own validation and authorization.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the owner's-list panels for one event, for the current user.
 *
 * @param int $event_id Event post ID.
 */
function extrachill_events_render_owner_guest_lists( int $event_id ): void {
	if ( ! class_exists( '\\ExtraChillEvents\\Core\\OwnerGuestListTable' ) || ! function_exists( 'extrachill_events_owner_lists_for_manager' ) ) {
		return;
	}

	$owner_lists = array();
	foreach ( extrachill_events_owner_lists_for_manager( $event_id, get_current_user_id() ) as $owner ) {
		$list = extrachill_events_list_owner_list( $owner['owner_type'], $owner['owner_id'] );
		if ( is_array( $list ) ) {
			$owner_lists[] = $list;
		}
	}
	if ( ! $owner_lists ) {
		return;
	}

	// Copy and confirm buttons; the host panel usually enqueued it already.
	if ( ! wp_script_is( 'ec-guest-list-management', 'enqueued' ) ) {
		wp_enqueue_script( 'ec-guest-list-management', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/guest-list-management.js', array(), EXTRACHILL_EVENTS_VERSION, true );
		wp_localize_script(
			'ec-guest-list-management',
			'ecGuestListManagement',
			array(
				'copied'   => __( 'Pass link copied.', 'extrachill-events' ),
				'copyHint' => __( 'Select and copy the link above.', 'extrachill-events' ),
			)
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation after the add redirect; matched against the lists below.
	$added_member_id = isset( $_GET['ec_owner_list_added'] ) ? absint( $_GET['ec_owner_list_added'] ) : 0;
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/owner-guest-list.php';
}

/**
 * Append the owner's-list panels after the host guest list panel.
 *
 * @param string $content Post content.
 * @return string
 */
function extrachill_events_append_owner_guest_lists( $content ) {
	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() || ! is_singular( 'data_machine_events' ) || ! in_the_loop() || ! is_main_query() || ! is_user_logged_in() ) {
		return $content;
	}
	ob_start();
	extrachill_events_render_owner_guest_lists( (int) get_the_ID() );
	return $content . ob_get_clean();
}
add_filter( 'the_content', 'extrachill_events_append_owner_guest_lists', 19 );

/**
 * Read the owner fields posted by an owner's-list form.
 *
 * @return array{event_id: int, owner_type: string, owner_id: int}
 */
function extrachill_events_owner_list_form_target(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Each handler verifies its nonce, keyed on these values, right after reading them.
	return array(
		'event_id'   => isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0,
		'owner_type' => isset( $_POST['owner_type'] ) ? sanitize_key( wp_unslash( $_POST['owner_type'] ) ) : '',
		'owner_id'   => isset( $_POST['owner_id'] ) ? absint( $_POST['owner_id'] ) : 0,
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

/**
 * Nonce action for one owner's list.
 *
 * @param string $owner_type venue|promoter.
 * @param int    $owner_id   Owner term ID.
 * @return string
 */
function extrachill_events_owner_list_nonce_action( string $owner_type, int $owner_id ): string {
	return 'ec_owner_list_' . $owner_type . '_' . $owner_id;
}

/**
 * Back to the panel on the event page the form was on.
 *
 * @param int   $event_id Event post ID.
 * @param array $args     Extra query args.
 */
function extrachill_events_owner_list_form_redirect( int $event_id, array $args = array() ): void {
	$url = $event_id ? (string) get_permalink( $event_id ) : home_url( '/' );
	wp_safe_redirect( ( $args ? add_query_arg( $args, $url ) : $url ) . '#ec-owner-guest-list' );
	exit;
}

/**
 * "Add to list" form, through extrachill/add-owner-guest-list-name.
 */
function extrachill_events_add_owner_list_name_from_panel(): void {
	$target = extrachill_events_owner_list_form_target();
	check_admin_referer( extrachill_events_owner_list_nonce_action( $target['owner_type'], $target['owner_id'] ) );

	$result = extrachill_events_run_guest_list_form_ability(
		'extrachill/add-owner-guest-list-name',
		array(
			'owner_type' => $target['owner_type'],
			'owner_id'   => $target['owner_id'],
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
		)
	);

	extrachill_events_owner_list_form_redirect( $target['event_id'], array( 'ec_owner_list_added' => (int) ( $result['member_id'] ?? 0 ) ) );
}
add_action( 'admin_post_ec_owner_list_add', 'extrachill_events_add_owner_list_name_from_panel' );

/**
 * "Remove" button, through extrachill/remove-owner-guest-list-name.
 */
function extrachill_events_remove_owner_list_name_from_panel(): void {
	$target = extrachill_events_owner_list_form_target();
	check_admin_referer( extrachill_events_owner_list_nonce_action( $target['owner_type'], $target['owner_id'] ) );

	extrachill_events_run_guest_list_form_ability(
		'extrachill/remove-owner-guest-list-name',
		array(
			'owner_type' => $target['owner_type'],
			'owner_id'   => $target['owner_id'],
			'member_id'  => isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0,
		)
	);

	extrachill_events_owner_list_form_redirect( $target['event_id'] );
}
add_action( 'admin_post_ec_owner_list_remove', 'extrachill_events_remove_owner_list_name_from_panel' );
