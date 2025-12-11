<?php
/**
 * Single Event Share Button
 *
 * Handles share button integration for single event pages.
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render share button in event action buttons container
 *
 * Displays share button alongside ticket button using flexbox container.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @hook datamachine_events_action_buttons
 * @param int $post_id Event post ID
 * @param string $ticket_url Ticket URL (may be empty)
 * @return void
 * @since 0.1.0
 */
function ec_events_render_share_button( $post_id, $ticket_url ) {
	if ( function_exists( 'extrachill_share_button' ) ) {
		extrachill_share_button( array(
			'share_url'   => get_permalink( $post_id ),
			'share_title' => get_the_title( $post_id ),
			'button_size' => 'button-large',
		) );
	}
}
add_action( 'datamachine_events_action_buttons', 'ec_events_render_share_button', 10, 2 );
