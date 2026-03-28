<?php
/**
 * Concert Tracking Integration
 *
 * Hooks into data_machine_events_action_buttons to render attendance buttons
 * on single event pages. Delegates rendering to extrachill-users.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render attendance button in event action buttons container.
 *
 * Fires on the data_machine_events_action_buttons hook at priority 5
 * (before the share button at 10) to place it as the primary CTA.
 *
 * Guards:
 * - Only on events.extrachill.com (blog_id check)
 * - Graceful no-op if extrachill-users isn't active
 *
 * @hook data_machine_events_action_buttons
 * @param int    $post_id    Event post ID.
 * @param string $ticket_url Ticket URL (may be empty).
 */
function ec_events_render_attendance_button( $post_id, $ticket_url ) {
	if ( ! ec_is_events_site() ) {
		return;
	}

	if ( ! function_exists( 'ec_users_render_attendance_button' ) ) {
		return;
	}

	ec_users_render_attendance_button( (int) $post_id );
}
add_action( 'data_machine_events_action_buttons', 'ec_events_render_attendance_button', 5, 2 );
