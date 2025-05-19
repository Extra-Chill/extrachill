<?php
/**
 * Band Following Feature
 *
 * Handles the logic for users following band profiles.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Follow a band.
 *
 * @param int $user_id User ID initiating the follow.
 * @param int $band_id Band Profile Post ID to follow.
 * @param bool $share_email_consent Whether to share email consent.
 * @return bool True on success, false on failure.
 */
function bp_follow_band( $user_id, $band_id, $share_email_consent = false ) {
    $user_id = absint( $user_id );
    $band_id = absint( $band_id );

    if ( ! $user_id || ! $band_id || get_post_type( $band_id ) !== 'band_profile' ) {
        return false;
    }

    $followed_bands = get_user_meta( $user_id, '_followed_band_profile_ids', true );
    if ( ! is_array( $followed_bands ) ) {
        $followed_bands = array();
    }

    // Already following
    if ( in_array( $band_id, $followed_bands ) ) {
        bp_maybe_update_band_follower_count( $band_id, true );
        do_action('bp_user_followed_band', $user_id, $band_id);
        return true;
    }

    $followed_bands[] = $band_id;
    $followed_bands = array_unique( $followed_bands );

    update_user_meta( $user_id, '_followed_band_profile_ids', $followed_bands );
    
    // Store email sharing consent
    $email_permissions = get_user_meta( $user_id, '_band_follow_email_permissions', true );
    if ( ! is_array( $email_permissions ) ) {
        $email_permissions = array();
    }
    $email_permissions[ $band_id ] = (bool) $share_email_consent;
    update_user_meta( $user_id, '_band_follow_email_permissions', $email_permissions );

    clean_user_cache($user_id);
    bp_maybe_update_band_follower_count( $band_id, true );
    do_action('bp_user_followed_band', $user_id, $band_id);

    // Double-check: is the user now following?
    return in_array( $band_id, get_user_meta( $user_id, '_followed_band_profile_ids', true ) );
}

/**
 * Unfollow a band.
 *
 * @param int $user_id User ID initiating the unfollow.
 * @param int $band_id Band Profile Post ID to unfollow.
 * @return bool True on success, false on failure.
 */
function bp_unfollow_band( $user_id, $band_id ) {
    $user_id = absint( $user_id );
    $band_id = absint( $band_id );

    if ( ! $user_id || ! $band_id ) {
        return false;
    }

    $followed_bands = get_user_meta( $user_id, '_followed_band_profile_ids', true );
    if ( ! is_array( $followed_bands ) ) {
        $followed_bands = array();
    }

    // Not following
    if ( ! in_array( $band_id, $followed_bands ) ) {
        bp_maybe_update_band_follower_count( $band_id, true );
        do_action('bp_user_unfollowed_band', $user_id, $band_id);
        return true;
    }

    $followed_bands = array_diff( $followed_bands, array( $band_id ) );
    update_user_meta( $user_id, '_followed_band_profile_ids', $followed_bands );

    // Remove email sharing consent for this band
    $email_permissions = get_user_meta( $user_id, '_band_follow_email_permissions', true );
    if ( is_array( $email_permissions ) && isset( $email_permissions[ $band_id ] ) ) {
        unset( $email_permissions[ $band_id ] );
        if ( empty( $email_permissions ) ) {
            delete_user_meta( $user_id, '_band_follow_email_permissions' );
        } else {
            update_user_meta( $user_id, '_band_follow_email_permissions', $email_permissions );
        }
    }

    clean_user_cache($user_id);
    bp_maybe_update_band_follower_count( $band_id, true );
    do_action('bp_user_unfollowed_band', $user_id, $band_id);

    // Double-check: is the user now NOT following?
    return ! in_array( $band_id, get_user_meta( $user_id, '_followed_band_profile_ids', true ) );
}

/**
 * Check if a user is following a specific band.
 *
 * @param int $user_id User ID.
 * @param int $band_id Band Profile Post ID.
 * @return bool True if following, false otherwise.
 */
function bp_is_user_following_band( $user_id, $band_id ) {
    $user_id = absint( $user_id );
    $band_id = absint( $band_id );

    if ( ! $user_id || ! $band_id ) {
        return false;
    }

    $followed_bands = get_user_meta( $user_id, '_followed_band_profile_ids', true );
    if ( ! is_array( $followed_bands ) ) {
        $followed_bands = array();
    }

    return in_array( $band_id, $followed_bands );
}

/**
 * Get the follower count for a band.
 *
 * @param int $band_id Band Profile Post ID.
 * @return int Follower count.
 */
function bp_get_band_follower_count( $band_id ) {
    $band_id = absint( $band_id );
    if ( ! $band_id ) {
        return 0;
    }
    $count = get_post_meta( $band_id, '_band_follower_count', true );
    return absint( $count ); // Return 0 if meta not set or not numeric
}

/**
 * Update the follower count meta for a band.
 * This function queries users to get the accurate count.
 *
 * @param int $band_id Band Profile Post ID.
 * @param bool $force Whether to force the update even if recently updated (future use).
 * @return bool True if count was updated, false otherwise.
 */
function bp_maybe_update_band_follower_count( $band_id, $force = false ) {
    // TODO: Add throttling later if needed (e.g., using transients)
    // For now, always update when called with $force = true
    if ( ! $force ) {
        // return false; // Example: Don't update unless forced
    }
    
    $band_id = absint( $band_id );
    if ( ! $band_id || get_post_type( $band_id ) !== 'band_profile' ) {
        return false;
    }

    global $wpdb;
    // Efficiently count users who have this band_id in their meta array
    // Note: This query assumes the meta value is stored as a serialized PHP array.
    $count = $wpdb->get_var( $wpdb->prepare( 
        "SELECT COUNT(user_id) FROM {$wpdb->usermeta} WHERE meta_key = '_followed_band_profile_ids' AND meta_value LIKE %s",
        '%i:' . $band_id . ';%'
    ) );

    $new_count = absint( $count );
    $updated = update_post_meta( $band_id, '_band_follower_count', $new_count );

    // error_log("Updated follower count for band $band_id to $new_count. Update status: " . ($updated ? 'Success' : 'Fail/Same'));

    return $updated;
}

// --- AJAX Handler for Follow/Unfollow --- 

add_action( 'wp_ajax_bp_toggle_follow_band', 'bp_ajax_toggle_follow_band_handler' );

function bp_ajax_toggle_follow_band_handler() {
    // Check nonce
    check_ajax_referer( 'bp_follow_nonce', 'nonce' );

    // Check user logged in
    if ( ! is_user_logged_in() ) {
        error_log('Follow AJAX: User not logged in');
        wp_send_json_error( array( 'message' => __( 'Please log in to follow bands.', 'generatepress_child' ) ) );
    }

    // Get data
    $user_id = get_current_user_id();
    $band_id = isset( $_POST['band_id'] ) ? absint( $_POST['band_id'] ) : 0;

    error_log("Follow AJAX: user_id={$user_id}, band_id={$band_id}");

    if ( ! $band_id || get_post_type( $band_id ) !== 'band_profile' ) {
        error_log('Follow AJAX: Invalid band specified');
        wp_send_json_error( array( 'message' => __( 'Invalid band specified.', 'generatepress_child' ) ) );
    }

    // Determine action
    $is_currently_following = bp_is_user_following_band( $user_id, $band_id );
    error_log("Follow AJAX: is_currently_following=" . ($is_currently_following ? 'true' : 'false'));
    $action_success = false;
    $share_email_consent = false; // Default for unfollow or if not provided

    if ( ! $is_currently_following ) { // Action is to follow
        // Only look for consent if the action is to follow
        $share_email_consent = isset( $_POST['share_email_consent'] ) && $_POST['share_email_consent'] === 'true';
        error_log("Follow AJAX: Attempting to follow. Share email consent: " . ($share_email_consent ? 'true' : 'false'));
        $action_success = bp_follow_band( $user_id, $band_id, $share_email_consent );
        error_log("Follow AJAX: Called bp_follow_band, result=" . ($action_success ? 'true' : 'false'));
    } else { // Action is to unfollow
        error_log("Follow AJAX: Attempting to unfollow.");
        $action_success = bp_unfollow_band( $user_id, $band_id );
        error_log("Follow AJAX: Called bp_unfollow_band, result=" . ($action_success ? 'true' : 'false'));
    }

    if ( $action_success ) {
        // Force a fresh update of the follower count meta
        bp_maybe_update_band_follower_count( $band_id, true );
        $new_follow_status = bp_is_user_following_band( $user_id, $band_id ); // Re-check status after action
        $new_count = bp_get_band_follower_count( $band_id );
        error_log("Follow AJAX: Success. new_follow_status=" . ($new_follow_status ? 'following' : 'not_following') . ", new_count={$new_count}");
        wp_send_json_success( array(
            'new_state' => $new_follow_status ? 'following' : 'not_following',
            'new_count' => $new_count,
            'new_count_formatted' => sprintf( _n( '%s follower', '%s followers', $new_count, 'generatepress_child' ), number_format_i18n( $new_count ) )
        ) );
    } else {
        error_log('Follow AJAX: Could not update follow status.');
        wp_send_json_error( array( 'message' => __( 'Could not update follow status. Please try again.', 'generatepress_child' ) ) );
    }
}

// --- Functions to get follower/following lists (implement as needed) ---

/**
 * Get users following a specific band.
 *
 * @param int $band_id Band Profile Post ID.
 * @param array $args WP_User_Query arguments.
 * @return array Array of WP_User objects.
 * @return WP_User_Query WP_User_Query object.
 */
function bp_get_band_followers( $band_id, $args = array() ) {
    $band_id = absint( $band_id );
    if ( ! $band_id ) {
        return new WP_User_Query(); // Return an empty query object if no band_id
    }

    $defaults = array(
        'meta_query' => array(
            array(
                'key'     => '_followed_band_profile_ids',
                'value'   => sprintf('"%d"', $band_id), // Check within serialized array string
                'compare' => 'LIKE'
            )
        ),
        'fields' => 'all', // Return full WP_User objects
        // Add pagination args etc. as needed from $args
        'number' => 20, 
        'paged' => 1,
    );
    $query_args = wp_parse_args( $args, $defaults );
    
    $user_query = new WP_User_Query( $query_args );
    
    return $user_query; // Return the full query object
}

/**
 * Get bands followed by a specific user.
 *
 * @param int $user_id User ID.
 * @param array $args WP_Query arguments.
 * @return array Array of WP_Post objects (band profiles).
 */
function bp_get_user_followed_bands( $user_id, $args = array() ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return array();
    }

    $followed_band_ids = get_user_meta( $user_id, '_followed_band_profile_ids', true );
    if ( ! is_array( $followed_band_ids ) || empty( $followed_band_ids ) ) {
        return array();
    }

    // Ensure IDs are integers
    $followed_band_ids = array_map('absint', $followed_band_ids);

    $defaults = array(
        'post_type'      => 'band_profile',
        'post__in'       => $followed_band_ids,
        'posts_per_page' => -1, // Get all followed bands for now
        'orderby'        => 'title', // Or 'post__in' to keep order, or date etc.
        'order'          => 'ASC',
        'ignore_sticky_posts' => 1,
    );
    $query_args = wp_parse_args( $args, $defaults );

    $query = new WP_Query( $query_args );

    return $query->get_posts();
}

// --- CSV Export Handler ---

add_action( 'admin_post_export_band_followers_csv', 'bp_handle_export_band_followers_csv' );

function bp_handle_export_band_followers_csv() {
    // Verify nonce
    $band_id = isset( $_POST['band_id'] ) ? absint( $_POST['band_id'] ) : 0;
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key($_POST['_wpnonce']), 'export_band_followers_csv_' . $band_id ) ) {
        wp_die( esc_html__( 'Security check failed.', 'generatepress_child' ) );
    }

    // Check permissions: user must be able to manage this specific band's members/followers
    if ( ! current_user_can( 'manage_band_members', $band_id ) ) {
        wp_die( esc_html__( 'You do not have permission to export followers for this band.', 'generatepress_child' ) );
    }

    // Get band details (for filename)
    $band_post = get_post( $band_id );
    if ( ! $band_post || $band_post->post_type !== 'band_profile' ) {
        wp_die( esc_html__( 'Invalid band profile.', 'generatepress_child' ) );
    }
    $band_name_slug = sanitize_title( $band_post->post_title );

    // Fetch all followers for the band
    $followers_query = bp_get_band_followers( $band_id, array( 'number' => -1 ) ); // -1 to get all followers
    $followers = $followers_query->get_results();

    if ( empty( $followers ) ) {
        // Optionally, you could still download an empty CSV or show a message.
        // For now, let's redirect back with a notice, or just die.
        wp_die( esc_html__( 'This band has no followers to export.', 'generatepress_child' ) );
    }

    $filename = 'band-followers-' . $band_name_slug . '-' . date('Y-m-d') . '.csv';

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $output = fopen( 'php://output', 'w' );

    // Add BOM to fix UTF-8 in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 

    // Add headers to CSV
    fputcsv( $output, array( 
        esc_html__( 'Username', 'generatepress_child' ), 
        esc_html__( 'Display Name', 'generatepress_child' ), 
        esc_html__( 'Email Address', 'generatepress_child' ),
        esc_html__( 'Email Contact Consent', 'generatepress_child' )
    ) );

    // Add data rows
    foreach ( $followers as $follower_user ) {
        $email_permissions = get_user_meta( $follower_user->ID, '_band_follow_email_permissions', true );
        $has_consented_for_this_band = is_array( $email_permissions ) && isset( $email_permissions[ $band_id ] ) && $email_permissions[ $band_id ] === true;
        
        $email_to_export = $has_consented_for_this_band ? $follower_user->user_email : esc_html__( 'Not Shared', 'generatepress_child' );
        $consent_status_text = $has_consented_for_this_band ? esc_html__( 'Yes', 'generatepress_child' ) : esc_html__( 'No', 'generatepress_child' );

        fputcsv( $output, array(
            $follower_user->user_login,
            $follower_user->display_name,
            $email_to_export,
            $consent_status_text
        ) );
    }

    fclose( $output );
    exit;
}

// --- AJAX Handler for User Band Subscription Settings ---

add_action( 'wp_ajax_update_user_band_subscriptions', 'bp_ajax_update_user_band_subscriptions_handler' );

function bp_ajax_update_user_band_subscriptions_handler() {
    // Check nonce: The nonce field in the form is named '_wpnonce_update_user_band_subscriptions'
    // and its value should be wp_create_nonce( 'update_user_band_subscriptions_' . $user_id );
    $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
    if ( ! $user_id || $user_id !== get_current_user_id() ) {
        wp_send_json_error( array( 'message' => __( 'Invalid user specified.', 'generatepress_child' ) ) );
        return;
    }
    check_ajax_referer( 'update_user_band_subscriptions_' . $user_id, '_wpnonce_update_user_band_subscriptions' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => __( 'You must be logged in to update settings.', 'generatepress_child' ) ) );
        return;
    }

    $band_email_consents = isset( $_POST['band_email_consent'] ) && is_array( $_POST['band_email_consent'] ) 
                             ? $_POST['band_email_consent'] 
                             : array();

    // Get all bands the user is currently following to ensure we only update permissions for those.
    $followed_bands_posts = bp_get_user_followed_bands( $user_id, array('posts_per_page' => -1, 'fields' => 'ids') ); // Get IDs only
    
    $current_email_permissions = get_user_meta( $user_id, '_band_follow_email_permissions', true );
    if ( ! is_array( $current_email_permissions ) ) {
        $current_email_permissions = array();
    }

    $updated_permissions = $current_email_permissions; // Start with existing permissions

    foreach ( $followed_bands_posts as $followed_band_id ) {
        // If the band is in the submitted form data, the user checked the box (consent given)
        if ( isset( $band_email_consents[ $followed_band_id ] ) && $band_email_consents[ $followed_band_id ] == '1' ) {
            $updated_permissions[ $followed_band_id ] = true;
        } else {
            // If the band is followed but not in the submitted consent data (or value isn't '1'), it means checkbox was unchecked (consent revoked)
            $updated_permissions[ $followed_band_id ] = false;
        }
    }
    
    // Clean up permissions for bands no longer followed (should be handled by unfollow logic, but good for robustness)
    foreach ( array_keys( $updated_permissions ) as $band_id_in_meta ) {
        if ( ! in_array( $band_id_in_meta, $followed_bands_posts ) ) {
            unset( $updated_permissions[ $band_id_in_meta ] );
        }
    }

    if ( update_user_meta( $user_id, '_band_follow_email_permissions', $updated_permissions ) ) {
        wp_send_json_success( array( 'message' => __( 'Subscription settings saved successfully.', 'generatepress_child' ) ) );
    } else {
        // This can also mean the data was the same and no update was needed.
        // For a better user experience, check if $updated_permissions is actually different from $current_email_permissions
        if ($updated_permissions === $current_email_permissions) {
             wp_send_json_success( array( 'message' => __( 'Subscription settings are already up to date.', 'generatepress_child' ) ) );
        } else {
             wp_send_json_error( array( 'message' => __( 'Could not save subscription settings. Please try again.', 'generatepress_child' ) ) );
        }
    }
}

?> 

