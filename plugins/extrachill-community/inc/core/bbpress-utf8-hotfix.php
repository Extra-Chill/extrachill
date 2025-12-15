<?php
/**
 * bbPress UTF-8 Display Name Hotfix
 *
 * Overrides bbPress's `bbp_format_user_display_name()` filter callback to avoid
 * calling WordPress core's deprecated `seems_utf8()` function on WordPress 6.9+.
 *
 * Remove this file once bbPress ships an official fix.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

function extrachill_community_override_bbp_user_display_name_formatting() {
    if (!function_exists('bbp_get_reply_author_display_name')) {
        return;
    }

    remove_filter('bbp_get_topic_author_display_name', 'bbp_format_user_display_name', 10);
    remove_filter('bbp_get_reply_author_display_name', 'bbp_format_user_display_name', 10);

    add_filter('bbp_get_topic_author_display_name', 'extrachill_community_format_user_display_name', 5, 1);
    add_filter('bbp_get_reply_author_display_name', 'extrachill_community_format_user_display_name', 5, 1);
}
add_action('bbp_loaded', 'extrachill_community_override_bbp_user_display_name_formatting', 20);

function extrachill_community_format_user_display_name($display_name = '') {
    $retval = $display_name;

    if (function_exists('wp_is_valid_utf8') && !wp_is_valid_utf8($display_name)) {
        $retval = _wp_utf8_encode_fallback($display_name);
    } elseif (function_exists('mb_check_encoding') && !mb_check_encoding($display_name, 'UTF-8')) {
        $retval = mb_convert_encoding($display_name, 'UTF-8', 'ISO-8859-1');
    }

    return $retval;
}
