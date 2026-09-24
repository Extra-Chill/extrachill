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

	if ( is_user_logged_in() && class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		$pass = \ExtraChillEvents\Core\RsvpPassesTable::find_for_user_event( $post_id, get_current_user_id() );
		if ( $pass && \ExtraChillEvents\Core\RsvpPassesTable::STATUS_ACTIVE === $pass['status'] ) {
			$has_pass = true;
			$code     = (string) $pass['code'];
		}
	}

	$event_id  = $post_id;
	$perk_text = extrachill_events_get_perk_text( $post_id );

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

	$perk_enabled = extrachill_events_perk_enabled( $post_id );

	$attendees = ec_users_get_event_attendees_full( $post_id );
	$passes    = $perk_enabled ? \ExtraChillEvents\Core\RsvpPassesTable::list_for_event( $post_id ) : array();

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

	$event_id  = $post_id;
	$perk_text = extrachill_events_get_perk_text( $post_id );

	ob_start();
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/door-list.php';
	$door_list_html = ob_get_clean();

	return $content . $door_list_html;
}
add_filter( 'the_content', 'extrachill_events_append_door_list', 20 );

/**
 * Enqueue the pass-watcher / door-list script on perk-enabled event pages.
 *
 * Depends on 'wp-api-fetch': WordPress core auto-wires the REST root URL
 * and nonce middleware for that handle on every enqueue (front end
 * included, see wp_default_packages_inline_scripts()), so no custom nonce
 * plumbing is needed here.
 */
function extrachill_events_enqueue_rsvp_pass_assets() {
	if ( ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id || ! extrachill_events_perk_enabled( $post_id ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-events-rsvp-pass',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/rsvp-pass.js',
		array( 'wp-api-fetch' ),
		EXTRACHILL_EVENTS_VERSION,
		true
	);

	wp_localize_script(
		'extrachill-events-rsvp-pass',
		'ecRsvpPass',
		array(
			'eventId' => $post_id,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_enqueue_rsvp_pass_assets' );
