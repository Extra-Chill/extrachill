<?php
// Multisite integration functions for Extra Chill Platform
// [MULTISITE MIGRATION COMPLETED]
// This file provides direct multisite queries for user data and post counts
// eliminating the need for REST API calls and hardcoded user ID mapping.

function get_main_site_user_id($community_user_id) {
    // With multisite, users exist across the network
    // Simply return the same user ID since they're in the same database
    return $community_user_id;
}

// Get user nicename directly from WordPress user data
function get_author_nicename_by_id($author_id) {
    $user_info = get_userdata($author_id);
    return $user_info ? $user_info->user_nicename : null;
}


function fetch_main_site_post_count_for_user($user_id) {
    // Get the main site ID (assuming site ID 1 is the main extrachill.com site)
    $main_site_id = 1;

    // Switch to main site to count posts
    switch_to_blog($main_site_id);
    $post_count = count_user_posts($user_id, 'post', true);
    restore_current_blog();

    return $post_count;
}

