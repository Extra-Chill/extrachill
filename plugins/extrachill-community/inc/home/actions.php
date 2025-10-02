<?php
/**
 * ExtraChill Community Home Action Hooks
 *
 * Centralized registration of homepage template action hooks
 * Following extrachill theme patterns for consistency
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default homepage header component
 * Includes title and description with extensibility hook
 */
function extrachill_community_default_home_header() {
    include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/forum-home-header.php';
}
add_action('extrachill_community_home_header', 'extrachill_community_default_home_header', 10);

/**
 * Default recently active topics component
 * Displays the three most recently active topics in card format
 */
function extrachill_community_default_recently_active() {
    include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/recently-active.php';
}
add_action('extrachill_community_home_top', 'extrachill_community_default_recently_active', 10);
