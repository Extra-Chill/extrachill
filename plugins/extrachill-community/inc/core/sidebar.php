<?php
/**
 * Community Sidebar Override
 *
 * Hides theme sidebar on bbPress pages via extrachill_sidebar_content filter.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

function extrachill_community_sidebar_override($sidebar_content) {
    if (is_bbpress()) {
        return '';
    }
    return $sidebar_content;
}
add_filter('extrachill_sidebar_content', 'extrachill_community_sidebar_override', 10);
