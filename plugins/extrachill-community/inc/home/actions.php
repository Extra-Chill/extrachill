<?php
/**
 * ExtraChill Community Home Action Hooks
 *
 * Hook-based homepage component registration for the feed-first homepage (#65):
 *   - extrachill_community_home_header      → newcomer welcome or member actions
 *   - extrachill_community_home_top         → "What's Happening" activity feed (hero)
 *   - extrachill_community_home_after_feed  → "Browse rooms" chip row (demoted nav)
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render newcomer messaging or member conversation actions.
 */
function extrachill_community_home_header() {
	if ( is_user_logged_in() ) {
		include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/new-topic-button.php';
		return;
	}

	include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/newcomer-welcome.php';
}
add_action( 'extrachill_community_home_header', 'extrachill_community_home_header', 10 );

/**
 * Render the topic composer modal for logged-in homepage visitors.
 */
function extrachill_community_new_topic_modal() {
	if ( ! is_front_page() || ! is_user_logged_in() ) {
		return;
	}
	include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/new-topic-modal.php';
}
add_action( 'wp_footer', 'extrachill_community_new_topic_modal' );

// Hero: "What's Happening" activity feed.
add_action( 'extrachill_community_home_top', 'extrachill_community_render_activity_feed', 10 );

// Demoted nav: "Browse rooms" chip row.
add_action( 'extrachill_community_home_after_feed', 'extrachill_community_render_browse_rooms', 10 );
