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

add_action( 'personal_options_update', 'save_extra_user_profile_fields' );
add_action( 'edit_user_profile_update', 'save_extra_user_profile_fields' );

function save_extra_user_profile_fields( $user_id ) {
    if ( !current_user_can( 'edit_user', $user_id ) ) { 
        return false; 
    }
    
    // Verify nonce for security
    if ( !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update-user_' . $user_id) ) {
        return false;
    }

    update_user_meta( $user_id, 'instagram', isset($_POST['instagram']) ? $_POST['instagram'] : '' );
    update_user_meta( $user_id, 'spotify', isset($_POST['spotify']) ? $_POST['spotify'] : '' );
    update_user_meta( $user_id, 'soundcloud', isset($_POST['soundcloud']) ? $_POST['soundcloud'] : '' );
    update_user_meta( $user_id, 'twitter', isset($_POST['twitter']) ? $_POST['twitter'] : '' );
    update_user_meta( $user_id, 'facebook', isset($_POST['facebook']) ? $_POST['facebook'] : '' );
    update_user_meta( $user_id, 'bandcamp', isset($_POST['bandcamp']) ? $_POST['bandcamp'] : '' );
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

    // Save Top Venues (New)
    if (isset($_POST['top_venues'])) {
        update_user_meta($user_id, 'top_venues', sanitize_textarea_field($_POST['top_venues']));
    }
}

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
    // if (isset($_POST['top_local_venues'])) {
    //	update_user_meta($user_id, 'top_local_venues', sanitize_textarea_field($_POST['top_local_venues']));
    // }

    // Save Top Local Artists
    // if (isset($_POST['top_local_artists'])) {
    //	update_user_meta($user_id, 'top_local_artists', sanitize_textarea_field($_POST['top_local_artists']));
    // }
}

add_action( 'personal_options_update', 'save_bbp_user_local_scene_details' );
add_action( 'edit_user_profile_update', 'save_bbp_user_local_scene_details' );

function save_bbp_user_artist_fields($user_id) {
    // Check for permissions
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }


    // Save Instruments Played
     if (isset($_POST['instruments_played'])) {
            update_user_meta($user_id, 'instruments_played', sanitize_text_field($_POST['instruments_played']));
    }
}

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

function display_main_site_post_count_on_profile() {
    // Only proceed if bbPress functions are available
    if (!function_exists('bbp_get_displayed_user_id')) {
        return;
    }

    $user_id = bbp_get_displayed_user_id();

    // Get main site post count
    switch_to_blog(1);
    $post_count = count_user_posts($user_id, 'post', true);
    restore_current_blog();

    if ($post_count > 0) {
        // Get author nicename
        $user_info = get_userdata($user_id);
        $author_slug = $user_info ? $user_info->user_nicename : null;
        $author_url = "https://extrachill.com/author/{$author_slug}/"; // Adjust URL structure as needed

        echo "<p><b>Extra Chill Articles:</b> $post_count <a href='" . esc_url($author_url) . "'>(View All)</a></p>";
    }
}

// Function to display music fan details - only when bbPress is available
function display_music_fan_details() {
    // Only proceed if bbPress functions are available
    if (!function_exists('bbp_get_displayed_user_id')) {
        return;
    }

    // Music Fan Section variables
    $favorite_artists = get_user_meta(bbp_get_displayed_user_id(), 'favorite_artists', true);
    $top_concerts = get_user_meta(bbp_get_displayed_user_id(), 'top_concerts', true);
    $top_venues = get_user_meta(bbp_get_displayed_user_id(), 'top_venues', true);

    // Wrap the existing conditional block in a card
    if ($favorite_artists || $top_concerts || $top_venues ) :
        ?>
        <div class="card">
            <div class="card-header">
                <h3><?php esc_html_e('Music Fan Details', 'extra-chill-community'); ?></h3>
            </div>
            <div class="card-body">
                <?php if ($favorite_artists) : ?>
                    <p><strong><?php esc_html_e('Favorite Artists:', 'extra-chill-community'); ?></strong> <?php echo nl2br(esc_html($favorite_artists)); ?></p>
                <?php endif; ?>

                <?php if ($top_concerts) : ?>
                    <p><strong><?php esc_html_e('Top Concerts:', 'extra-chill-community'); ?></strong> <?php echo nl2br(esc_html($top_concerts)); ?></p>
                <?php endif; ?>

                <?php if ($top_venues) : ?>
                    <p><strong><?php esc_html_e('Top Venues:', 'extra-chill-community'); ?></strong> <?php echo nl2br(esc_html($top_venues)); ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif;
}

// Hook the display functions to run after bbPress is loaded
add_action('bbp_init', 'display_music_fan_details');

// =============================================================================
// bbPress User Role & Title Customization (moved from bbpress-customization.php)
// =============================================================================

// Utility function to get the edit profile URL
function extrachill_get_edit_profile_url($user_id, $profile_type) {
    // This function should return the URL for editing the specified profile type.
    return home_url("/edit-profile/?profile_type={$profile_type}&user_id={$user_id}");
}

// Load the function after bbPress is fully loaded
add_action( 'after_setup_theme', 'override_bbp_user_role_after_bbp_load' );

function override_bbp_user_role_after_bbp_load() {
    // Hook into bbPress filter after it's available
    add_filter( 'bbp_get_user_display_role', 'override_bbp_user_forum_role', 10, 2 );
}

function override_bbp_user_forum_role( $role, $user_id ) {
    // Ensure bbPress functions are available
    if ( function_exists( 'bbp_is_user_keymaster' ) && function_exists( 'bbp_get_user_display_role' ) ) {

        // Get the custom title if it exists
        $custom_title = get_user_meta( $user_id, 'ec_custom_title', true );

        // Return custom title if set, otherwise return "Extra Chillian" for regular users
        return ! empty( $custom_title ) ? $custom_title : 'Extra Chillian';
    }

    // Fallback if bbPress is not loaded properly
    return $role;
}

function save_ec_custom_title( $user_id ) {
    if ( isset( $_POST['ec_custom_title'] ) ) {
        update_user_meta( $user_id, 'ec_custom_title', sanitize_text_field( wp_unslash( $_POST['ec_custom_title'] ) ) );
    }
}
add_action( 'personal_options_update', 'save_ec_custom_title' );
add_action( 'edit_user_profile_update', 'save_ec_custom_title' );