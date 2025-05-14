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
 * @return bool True on success, false on failure.
 */
function bp_follow_band( $user_id, $band_id ) {
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

    if ( $is_currently_following ) {
        $action_success = bp_unfollow_band( $user_id, $band_id );
        error_log("Follow AJAX: Called bp_unfollow_band, result=" . ($action_success ? 'true' : 'false'));
    } else {
        $action_success = bp_follow_band( $user_id, $band_id );
        error_log("Follow AJAX: Called bp_follow_band, result=" . ($action_success ? 'true' : 'false'));
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
 */
function bp_get_band_followers( $band_id, $args = array() ) {
    $band_id = absint( $band_id );
    if ( ! $band_id ) {
        return array();
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
    
    return $user_query->get_results();
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

?> 