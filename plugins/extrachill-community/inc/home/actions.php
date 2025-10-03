<?php
/**
 * ExtraChill Community Home Action Hooks
 *
 * Hook-based homepage component registration system allows plugins
 * to modify homepage content via action hooks without template files.
 *
 * Hooks: extrachill_community_home_header, extrachill_community_home_top,
 * extrachill_community_home_before_forums
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

function extrachill_community_default_home_header() {
    include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/forum-home-header.php';
}
add_action('extrachill_community_home_header', 'extrachill_community_default_home_header', 10);

function extrachill_community_default_recently_active() {
    include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/recently-active.php';
}
add_action('extrachill_community_home_top', 'extrachill_community_default_recently_active', 10);

function extrachill_community_default_home_before_forums() {
    extrachill_display_latest_post();
}
add_action('extrachill_community_home_before_forums', 'extrachill_community_default_home_before_forums', 10);
