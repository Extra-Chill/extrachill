<?php
/**
 * ExtraChill Community Home Action Hooks
 *
 * Hook-based homepage component registration for the feed-first homepage (#65):
 *   - extrachill_community_home_header      → "Start a conversation" CTA + modal
 *   - extrachill_community_home_top         → "What's Happening" activity feed (hero)
 *   - extrachill_community_home_after_feed  → "Browse rooms" chip row (demoted nav)
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_community_new_topic_button() {
	include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/new-topic-button.php';
}
add_action( 'extrachill_community_home_header', 'extrachill_community_new_topic_button', 10 );

function extrachill_community_new_topic_modal() {
	if ( ! is_front_page() ) {
		return;
	}
	include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/new-topic-modal.php';
}
add_action( 'wp_footer', 'extrachill_community_new_topic_modal' );

// Hero: "What's Happening" activity feed.
add_action( 'extrachill_community_home_top', 'extrachill_community_render_activity_feed', 10 );

// Demoted nav: "Browse rooms" chip row.
add_action( 'extrachill_community_home_after_feed', 'extrachill_community_render_browse_rooms', 10 );
