<?php
/**
 * ExtraChill Community Home Action Hooks
 *
 * Hook-based homepage component registration system. Registers the "New Topic"
 * button and modal for creating forum topics from the community homepage.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

function extrachill_community_new_topic_button() {
    include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/new-topic-button.php';
}
add_action('extrachill_community_home_header', 'extrachill_community_new_topic_button', 10);

function extrachill_community_new_topic_modal() {
    if (!is_front_page()) {
        return;
    }
    include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/new-topic-modal.php';
}
add_action('wp_footer', 'extrachill_community_new_topic_modal');

function extrachill_community_default_recently_active() {
    include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/recently-active.php';
}
add_action('extrachill_community_home_top', 'extrachill_community_default_recently_active', 10);

function extrachill_community_default_home_before_forums() {
    extrachill_display_latest_post();
}
add_action('extrachill_community_home_before_forums', 'extrachill_community_default_home_before_forums', 10);
