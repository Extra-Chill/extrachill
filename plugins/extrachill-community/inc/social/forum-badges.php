<?php
/**
 * Forum User Badges
 *
 * Displays team member, artist, and professional badges in forum posts and profiles.
 * Uses ec_is_team_member() from extrachill-users plugin for proper manual override support.
 *
 * @package ExtraChillCommunity
 */

// Add content after the reply author details if the user is a team member
function extrachill_add_after_reply_author($reply_id) {
    $user_id = bbp_get_reply_author_id($reply_id);
    $is_artist = get_user_meta($user_id, 'user_is_artist', true);
    $is_professional = get_user_meta($user_id, 'user_is_professional', true);

    // Use ec_is_team_member() from extrachill-users plugin (supports manual overrides)
    if (function_exists('ec_is_team_member') && ec_is_team_member($user_id)) {
        echo '<span class="extrachill-team-member" data-title="Extra Chill Team Member"></span>';
    }

    if ($is_artist == 1) {
        echo '<span class="user-is-artist" data-title="Artist"></span>';
    }

    if ($is_professional == 1) {
        echo '<span class="user-is-professional" data-title="Music Industry Professional"></span>';
    }
}

add_action('bbp_theme_after_reply_author_details', 'extrachill_add_after_reply_author');

// New function to add badges after username in user profiles and active users section
function extrachill_add_after_user_name($user_id) {
    $is_artist = get_user_meta($user_id, 'user_is_artist', true);
    $is_professional = get_user_meta($user_id, 'user_is_professional', true);

    // Use ec_is_team_member() from extrachill-users plugin (supports manual overrides)
    if (function_exists('ec_is_team_member') && ec_is_team_member($user_id)) {
        echo '<span class="extrachill-team-member" data-title="Extra Chill Team Member"></span>';
    }

    if ($is_artist == 1) {
        echo '<span class="user-is-artist" data-title="Artist"></span>';
    }

    if ($is_professional == 1) {
        echo '<span class="user-is-professional" data-title="Music Industry Professional"></span>';
    }
}

// New action hook for adding badges after username
add_action('bbp_theme_after_user_name', 'extrachill_add_after_user_name');

function ec_add_after_user_details_menu_items() {
    // Assuming you can get the current user ID or the displayed user ID in this context
    $user_id = bbp_get_displayed_user_id();

    $is_artist = get_user_meta($user_id, 'user_is_artist', true);
    $is_professional = get_user_meta($user_id, 'user_is_professional', true);

    // Use ec_is_team_member() from extrachill-users plugin (supports manual overrides)
    if (function_exists('ec_is_team_member') && ec_is_team_member($user_id)) {
        echo '<span class="extrachill-team-member" data-title="Extra Chill Team Member"></span>';
    }

    if ($is_artist == 1) {
        echo '<span class="user-is-artist" data-title="Artist"></span>';
    }

    if ($is_professional == 1) {
        echo '<span class="user-is-professional" data-title="Music Industry Professional"></span>';
    }
}
add_action('bbp_template_after_user_details_menu_items', 'ec_add_after_user_details_menu_items');

