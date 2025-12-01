<?php
/**
 * Forum User Badges
 *
 * Displays team member, artist, and professional badges in forum posts and profiles.
 * Uses ec_is_team_member() from extrachill-users plugin for proper manual override support.
 *
 * @package ExtraChillCommunity
 */

/**
 * Render a forum badge with inline SVG icon
 *
 * @param string $icon_id The extrachill.svg symbol ID
 * @param string $class CSS class for the badge
 * @param string $title Tooltip title
 */
function extrachill_render_badge($icon_id, $class, $title) {
    printf(
        '<span class="%s" data-title="%s">%s</span>',
        esc_attr($class),
        esc_attr($title),
        ec_icon($icon_id)
    );
}

function extrachill_add_after_reply_author() {
    $user_id = bbp_get_reply_author_id();

    if (!$user_id) {
        return;
    }

    $is_artist = get_user_meta($user_id, 'user_is_artist', true);
    $is_professional = get_user_meta($user_id, 'user_is_professional', true);

    if (function_exists('ec_is_team_member') && ec_is_team_member($user_id)) {
        extrachill_render_badge('igloo', 'extrachill-team-member', 'Extra Chill Team Member');
    }

    if ($is_artist == 1) {
        extrachill_render_badge('guitar', 'user-is-artist', 'Artist');
    }

    if ($is_professional == 1) {
        extrachill_render_badge('briefcase', 'user-is-professional', 'Music Industry Professional');
    }
}

add_action('bbp_theme_after_reply_author_details', 'extrachill_add_after_reply_author');

function extrachill_add_after_user_name($user_id) {
    if (!$user_id) {
        return;
    }

    $is_artist = get_user_meta($user_id, 'user_is_artist', true);
    $is_professional = get_user_meta($user_id, 'user_is_professional', true);

    if (function_exists('ec_is_team_member') && ec_is_team_member($user_id)) {
        extrachill_render_badge('igloo', 'extrachill-team-member', 'Extra Chill Team Member');
    }

    if ($is_artist == 1) {
        extrachill_render_badge('guitar', 'user-is-artist', 'Artist');
    }

    if ($is_professional == 1) {
        extrachill_render_badge('briefcase', 'user-is-professional', 'Music Industry Professional');
    }
}

add_action('bbp_theme_after_user_name', 'extrachill_add_after_user_name');

function ec_add_after_user_details_menu_items() {
    $user_id = bbp_get_displayed_user_id();

    if (!$user_id) {
        return;
    }

    $is_artist = get_user_meta($user_id, 'user_is_artist', true);
    $is_professional = get_user_meta($user_id, 'user_is_professional', true);

    if (function_exists('ec_is_team_member') && ec_is_team_member($user_id)) {
        extrachill_render_badge('igloo', 'extrachill-team-member', 'Extra Chill Team Member');
    }

    if ($is_artist == 1) {
        extrachill_render_badge('guitar', 'user-is-artist', 'Artist');
    }

    if ($is_professional == 1) {
        extrachill_render_badge('briefcase', 'user-is-professional', 'Music Industry Professional');
    }
}
add_action('bbp_template_after_user_details_menu_items', 'ec_add_after_user_details_menu_items');

