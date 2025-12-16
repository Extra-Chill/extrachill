<?php
/**
 * Forum User Badges
 *
 * Displays user badges in forum contexts. Badge logic is centralized in extrachill-users.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a forum badge.
 *
 * @param string $icon_id The extrachill.svg symbol ID.
 * @param string $class   CSS class for the badge.
 * @param string $title   Tooltip title.
 */
function extrachill_render_badge( $icon_id, $class, $title ) {
	printf(
		'<span class="%s" data-title="%s">%s</span>',
		esc_attr( $class ),
		esc_attr( $title ),
		ec_icon( $icon_id )
	);
}

/**
 * Render badges for a specific user.
 *
 * @param int $user_id User ID.
 */
function extrachill_render_user_badges( $user_id ) {
	if ( ! function_exists( 'ec_get_user_badges' ) ) {
		return;
	}

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}

	foreach ( ec_get_user_badges( $user_id ) as $badge ) {
		if ( empty( $badge['icon'] ) || empty( $badge['class_name'] ) || empty( $badge['title'] ) ) {
			continue;
		}

		extrachill_render_badge( $badge['icon'], $badge['class_name'], $badge['title'] );
	}
}

function extrachill_add_after_reply_author() {
	if ( ! function_exists( 'bbp_get_reply_author_id' ) ) {
		return;
	}

	extrachill_render_user_badges( bbp_get_reply_author_id() );
}
add_action( 'bbp_theme_after_reply_author_details', 'extrachill_add_after_reply_author' );

function extrachill_add_after_user_name( $user_id ) {
	extrachill_render_user_badges( $user_id );
}
add_action( 'bbp_theme_after_user_name', 'extrachill_add_after_user_name' );

function ec_add_after_user_details_menu_items() {
	if ( ! function_exists( 'bbp_get_displayed_user_id' ) ) {
		return;
	}

	extrachill_render_user_badges( bbp_get_displayed_user_id() );
}
add_action( 'bbp_template_after_user_details_menu_items', 'ec_add_after_user_details_menu_items' );


