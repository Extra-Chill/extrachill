<?php
/**
 * Community Sidebar Override
 *
 * Replaces theme sidebar content on bbPress pages using extrachill_sidebar_content filter.
 * Prevents theme sidebar from displaying alongside bbPress custom sidebars.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Override theme sidebar on bbPress pages
 *
 * @param mixed $sidebar_content Default sidebar content
 * @return string Empty string on bbPress pages, default content otherwise
 */
function extrachill_community_sidebar_override($sidebar_content) {
    if (is_bbpress()) {
        return '';
    }
    return $sidebar_content;
}
add_filter('extrachill_sidebar_content', 'extrachill_community_sidebar_override', 10);
