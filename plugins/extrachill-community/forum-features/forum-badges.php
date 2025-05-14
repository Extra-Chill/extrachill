<?php

// Function to check if the user is an Extra Chill Team member
function is_exchill_team_member($user_id) {
    return get_user_meta($user_id, 'extrachill_team', true) == 1;
}

// Conditional check for the 'extrachill-team-member' meta
function is_user_extrachill_team_member($user_id) {
    return get_user_meta($user_id, 'extrachill_team', true) == 1;
}



// Add content after the reply author details if the user is a team member
function extrachill_add_after_reply_author($reply_id) {
    $user_id = bbp_get_reply_author_id($reply_id);
    $is_artist = get_user_meta($user_id, 'user_is_artist', true);
    $is_professional = get_user_meta($user_id, 'user_is_professional', true);
    
    if (is_user_extrachill_team_member($user_id)) {
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

    if (is_user_extrachill_team_member($user_id)) {
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
    
    if (is_user_extrachill_team_member($user_id)) {
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

