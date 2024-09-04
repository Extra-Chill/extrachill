<?php

// user profile customization - replaces fan, artist, indo pro, etc
function save_bbp_user_music_fan_fields($user_id) {
    if (isset($_POST['favorite_genres'])) {
        update_user_meta($user_id, 'favorite_genres', sanitize_text_field($_POST['favorite_genres']));
    }

    if (isset($_POST['favorite_bands'])) {
        update_user_meta($user_id, 'favorite_bands', sanitize_text_field($_POST['favorite_bands']));
    }

    if (isset($_POST['best_concerts'])) {
        update_user_meta($user_id, 'best_concerts', sanitize_textarea_field($_POST['best_concerts']));
    }

    if (isset($_POST['music_interests'])) {
        update_user_meta($user_id, 'music_interests', sanitize_textarea_field($_POST['music_interests']));
    }
}
add_action('personal_options_update', 'save_bbp_user_music_fan_fields');
add_action('edit_user_profile_update', 'save_bbp_user_music_fan_fields');


add_action( 'personal_options_update', 'save_extra_user_profile_fields' );
add_action( 'edit_user_profile_update', 'save_extra_user_profile_fields' );

function save_extra_user_profile_fields( $user_id ) {
    if ( !current_user_can( 'edit_user', $user_id ) ) { 
        return false; 
    }

    update_user_meta( $user_id, 'instagram', $_POST['instagram'] );
    update_user_meta( $user_id, 'spotify', $_POST['spotify'] );
    update_user_meta( $user_id, 'soundcloud', $_POST['soundcloud'] );
    update_user_meta( $user_id, 'twitter', $_POST['twitter'] );
    update_user_meta( $user_id, 'facebook', $_POST['facebook'] );
    update_user_meta( $user_id, 'bandcamp', $_POST['bandcamp'] );
    for ($i = 1; $i <= 3; $i++) {
        update_user_meta( $user_id, 'utility_link_' . $i, $_POST['utility_link_' . $i] );
    }
}

function save_bbp_user_music_fan_details($user_id) {
    // Ensure the current user has permission to edit the user.
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Save Favorite Artists
    if (isset($_POST['favorite_artists'])) {
        update_user_meta($user_id, 'favorite_artists', sanitize_textarea_field($_POST['favorite_artists']));
    }

    // Save Top Concerts
    if (isset($_POST['top_concerts'])) {
        update_user_meta($user_id, 'top_concerts', sanitize_textarea_field($_POST['top_concerts']));
    }

    // Save Musical Memories
    if (isset($_POST['top_festivals'])) {
        update_user_meta($user_id, 'top_festivals', sanitize_textarea_field($_POST['top_festivals']));
    }

    // Save Desert Island Albums
    if (isset($_POST['desert_island_albums'])) {
        update_user_meta($user_id, 'desert_island_albums', sanitize_textarea_field($_POST['desert_island_albums']));
    }
}
add_action('personal_options_update', 'save_bbp_user_music_fan_details');
add_action('edit_user_profile_update', 'save_bbp_user_music_fan_details');

function save_bbp_user_local_scene_details($user_id) {
    // Ensure the current user has permission to edit the user.
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Save Local City
    if (isset($_POST['local_city'])) {
        update_user_meta($user_id, 'local_city', sanitize_text_field($_POST['local_city']));
    }

    // Save Top Local Venues
    if (isset($_POST['top_local_venues'])) {
        update_user_meta($user_id, 'top_local_venues', sanitize_textarea_field($_POST['top_local_venues']));
    }

    // Save Top Local Artists
    if (isset($_POST['top_local_artists'])) {
        update_user_meta($user_id, 'top_local_artists', sanitize_textarea_field($_POST['top_local_artists']));
    }
}
add_action('personal_options_update', 'save_bbp_user_local_scene_details');
add_action('edit_user_profile_update', 'save_bbp_user_local_scene_details');

function save_bbp_user_artist_fields($user_id) {
    // Check for permissions
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Save Artist Name
    if (isset($_POST['artist_name'])) {
        update_user_meta($user_id, 'artist_name', sanitize_text_field($_POST['artist_name']));
    }

    // Save Genre
    if (isset($_POST['artist_genre'])) {
        update_user_meta($user_id, 'artist_genre', sanitize_text_field($_POST['artist_genre']));
    }

    // Save Influences
    if (isset($_POST['artist_influences'])) {
        update_user_meta($user_id, 'artist_influences', sanitize_textarea_field($_POST['artist_influences']));
    }

    // Save Featured Embed URL
    if (isset($_POST['featured_embed'])) {
        update_user_meta($user_id, 'featured_embed', esc_url_raw($_POST['featured_embed']));
    }
     // Save Band Name
     if (isset($_POST['band_name'])) {
            update_user_meta($user_id, 'band_name', sanitize_text_field($_POST['band_name']));
     }
    
    // Save Instruments Played
     if (isset($_POST['instruments_played'])) {
            update_user_meta($user_id, 'instruments_played', sanitize_text_field($_POST['instruments_played']));
    }
}
add_action('personal_options_update', 'save_bbp_user_artist_fields');
add_action('edit_user_profile_update', 'save_bbp_user_artist_fields');

function save_bbp_user_professional_fields($user_id) {
    // Check for permissions
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Save Role
    if (isset($_POST['professional_role'])) {
        update_user_meta($user_id, 'professional_role', sanitize_text_field($_POST['professional_role']));
    }

    // Save Company
    if (isset($_POST['professional_company'])) {
        update_user_meta($user_id, 'professional_company', sanitize_text_field($_POST['professional_company']));
    }

    // Save Skills
    if (isset($_POST['professional_skills'])) {
        update_user_meta($user_id, 'professional_skills', sanitize_text_field($_POST['professional_skills']));
    }

    // Save Goals
    if (isset($_POST['professional_goals'])) {
        update_user_meta($user_id, 'professional_goals', sanitize_textarea_field($_POST['professional_goals']));
    }
}
add_action('personal_options_update', 'save_bbp_user_professional_fields');
add_action('edit_user_profile_update', 'save_bbp_user_professional_fields');

function display_main_site_post_count_on_profile() {
    $community_user_id = bbp_get_displayed_user_id();
    $author_id = convert_community_user_id_to_author_id($community_user_id);

    if ($author_id !== null) {
        $post_count = fetch_main_site_post_count_for_user($author_id);

        // Use the new mapping to get the author slug (user nicename)
        $author_slug = get_author_nicename_by_id($author_id);
        $author_url = "https://extrachill.com/author/{$author_slug}/"; // Adjust URL structure as needed

        if ($post_count > 0) {
            echo "<p><b>Extra Chill Articles:</b> $post_count <a href='" . esc_url($author_url) . "'>(View All)</a></p>";
        }
    }
}