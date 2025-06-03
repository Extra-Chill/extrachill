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
    global $wpdb;
    $user_id = absint( $user_id );
    $band_id = absint( $band_id );

    if ( ! $user_id || ! $band_id || get_post_type( $band_id ) !== 'band_profile' ) {
        return false;
    }

    $user_data = get_userdata( $user_id );
    if ( ! $user_data ) {
        return false; // User not found
    }

    $followed_bands = get_user_meta( $user_id, '_followed_band_profile_ids', true );
    if ( ! is_array( $followed_bands ) ) {
        $followed_bands = array();
    }

    $was_already_following = in_array( $band_id, $followed_bands );

    if ( ! $was_already_following ) {
        $followed_bands[] = $band_id;
        $followed_bands = array_unique( $followed_bands );
        update_user_meta( $user_id, '_followed_band_profile_ids', $followed_bands );
    }
    
    if ( $share_email_consent ) {
        $table_name = $wpdb->prefix . 'band_subscribers';
        $data = array(
            'band_profile_id' => $band_id,
            'user_id' => $user_id,
            'subscriber_email' => $user_data->user_email,
            'username' => $user_data->user_login,
            'subscribed_at' => current_time( 'mysql', 1 ),
            'source' => 'platform_follow_consent'
        );
        $format = array( '%d', '%d', '%s', '%s', '%s', '%s' );
        
        // Use $wpdb->replace which handles INSERT or UPDATE on duplicate key
        $wpdb->replace( $table_name, $data, $format );

    } else {
        // If consent is NOT given (e.g. during initial follow without checkbox, or a direct call with false)
        // ensure no 'platform_follow_consent' record exists for this user/band.
        // This is important if a user previously consented and then re-follows without consent.
        $table_name = $wpdb->prefix . 'band_subscribers';
        $wpdb->delete(
            $table_name,
            array(
                'user_id' => $user_id,
                'band_profile_id' => $band_id,
                'source' => 'platform_follow_consent'
            ),
            array( '%d', '%d', '%s' )
        );
    }

    if ( ! $was_already_following ) {
        clean_user_cache($user_id);
        bp_maybe_update_band_follower_count( $band_id, true );
        do_action('bp_user_followed_band', $user_id, $band_id);
    } else {
        // If already following, but consent might have changed, still trigger action
        // This allows an update to consent status for an existing follower
        do_action('bp_user_follow_consent_updated', $user_id, $band_id, $share_email_consent);
    }

    // Check if the user is generally following the band (regardless of email consent)
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
    global $wpdb;
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
        // Though not strictly necessary to update count if not following,
        // it's harmless and ensures consistency if somehow out of sync.
        bp_maybe_update_band_follower_count( $band_id, true );
        do_action('bp_user_unfollowed_band', $user_id, $band_id, false); // false indicates no longer following
        return true;
    }

    $followed_bands = array_diff( $followed_bands, array( $band_id ) );
    update_user_meta( $user_id, '_followed_band_profile_ids', $followed_bands );

    // Remove from wp_band_subscribers if source is 'platform_follow_consent'
    $table_name = $wpdb->prefix . 'band_subscribers';
    $wpdb->delete(
        $table_name,
        array(
            'user_id' => $user_id,
            'band_profile_id' => $band_id,
            'source' => 'platform_follow_consent'
        ),
        array( '%d', '%d', '%s' )
    );

    clean_user_cache($user_id);
    bp_maybe_update_band_follower_count( $band_id, true );
    do_action('bp_user_unfollowed_band', $user_id, $band_id, true); // true indicates unfollow was successful

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
        // error_log('Follow AJAX: User not logged in');
        wp_send_json_error( array( 'message' => __( 'Please log in to follow bands.', 'generatepress_child' ) ) );
    }

    // Get data
    $user_id = get_current_user_id();
    $band_id = isset( $_POST['band_id'] ) ? absint( $_POST['band_id'] ) : 0;

    // error_log("Follow AJAX: user_id={$user_id}, band_id={$band_id}");

    if ( ! $band_id || get_post_type( $band_id ) !== 'band_profile' ) {
        // error_log('Follow AJAX: Invalid band specified');
        wp_send_json_error( array( 'message' => __( 'Invalid band specified.', 'generatepress_child' ) ) );
    }

    // Determine action
    $is_currently_following = bp_is_user_following_band( $user_id, $band_id );
    // error_log("Follow AJAX: is_currently_following=" . ($is_currently_following ? 'true' : 'false'));
    $action_success = false;
    $share_email_consent = false; // Default for unfollow or if not provided

    if ( ! $is_currently_following ) { // Action is to follow
        // Only look for consent if the action is to follow
        $share_email_consent = isset( $_POST['share_email_consent'] ) && $_POST['share_email_consent'] === 'true';
        // error_log("Follow AJAX: Attempting to follow. Share email consent: " . ($share_email_consent ? 'true' : 'false'));
        $action_success = bp_follow_band( $user_id, $band_id, $share_email_consent );
        // error_log("Follow AJAX: Called bp_follow_band, result=" . ($action_success ? 'true' : 'false'));
    } else { // Action is to unfollow
        // error_log("Follow AJAX: Attempting to unfollow.");
        $action_success = bp_unfollow_band( $user_id, $band_id );
        // error_log("Follow AJAX: Called bp_unfollow_band, result=" . ($action_success ? 'true' : 'false'));
    }

    if ( $action_success ) {
        // Force a fresh update of the follower count meta
        bp_maybe_update_band_follower_count( $band_id, true );
        $new_follow_status = bp_is_user_following_band( $user_id, $band_id ); // Re-check status after action
        $new_count = bp_get_band_follower_count( $band_id );
        // error_log("Follow AJAX: Success. new_follow_status=" . ($new_follow_status ? 'following' : 'not_following') . ", new_count={$new_count}");
        wp_send_json_success( array(
            'new_state' => $new_follow_status ? 'following' : 'not_following',
            'new_count' => $new_count,
            'new_count_formatted' => sprintf( _n( '%s follower', '%s followers', $new_count, 'generatepress_child' ), number_format_i18n( $new_count ) )
        ) );
    } else {
        // error_log('Follow AJAX: Could not update follow status.');
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

// --- AJAX Handler for User Band Subscription Settings ---

add_action( 'wp_ajax_update_user_band_subscriptions', 'bp_ajax_update_user_band_subscriptions_handler' );

function bp_ajax_update_user_band_subscriptions_handler() {
    global $wpdb;
    check_ajax_referer( 'bp_user_band_subscriptions_nonce', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => __( 'You must be logged in to update your subscriptions.', 'generatepress_child' ) ) );
    }

    $user_id = get_current_user_id();
    $user_data = get_userdata( $user_id );

    if ( ! $user_data ) {
        wp_send_json_error( array( 'message' => __( 'User not found.', 'generatepress_child' ) ) );
    }

    // bands_consented will be an array of band_ids the user has consented to
    $bands_consented = isset( $_POST['bands_consented'] ) && is_array( $_POST['bands_consented'] ) ? array_map( 'absint', $_POST['bands_consented'] ) : array();
    // bands_unconsented will be an array of band_ids the user has specifically unconsented from (previously consented)
    $bands_unconsented = isset( $_POST['bands_unconsented'] ) && is_array( $_POST['bands_unconsented'] ) ? array_map( 'absint', $_POST['bands_unconsented'] ) : array();

    $table_name = $wpdb->prefix . 'band_subscribers';
    $processed_bands = array();

    // Add/Update consent for selected bands
    if ( ! empty( $bands_consented ) ) {
        foreach ( $bands_consented as $band_id ) {
            if ( $band_id > 0 && get_post_type( $band_id ) === 'band_profile' ) {
                $data = array(
                    'band_profile_id' => $band_id,
                    'user_id' => $user_id,
                    'subscriber_email' => $user_data->user_email,
                    'username' => $user_data->user_login,
                    'subscribed_at' => current_time( 'mysql', 1 ),
                    'source' => 'platform_follow_consent'
                );
                $format = array( '%d', '%d', '%s', '%s', '%s', '%s' );
                $wpdb->replace( $table_name, $data, $format );
                $processed_bands[] = $band_id;
            }
        }
    }

    // Remove consent for unselected or explicitly unconsented bands
    // This includes any bands the user is following but did not have in the $bands_consented list for this submission.
    // Fetch all current 'platform_follow_consent' subscriptions for the user
    $current_consented_subscriptions = $wpdb->get_results( $wpdb->prepare(
        "SELECT band_profile_id FROM {$table_name} WHERE user_id = %d AND source = 'platform_follow_consent'",
        $user_id
    ), ARRAY_A );

    $currently_subscribed_band_ids = wp_list_pluck( $current_consented_subscriptions, 'band_profile_id' );

    foreach ( $currently_subscribed_band_ids as $subscribed_band_id ) {
        if ( ! in_array( $subscribed_band_id, $bands_consented ) ) {
            // If a currently subscribed band is not in the new list of consented bands, remove it.
            $wpdb->delete(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'band_profile_id' => $subscribed_band_id,
                    'source' => 'platform_follow_consent'
                ),
                array( '%d', '%d', '%s' )
            );
            $processed_bands[] = $subscribed_band_id; // Also mark as processed
        }
    }
    
    // Also handle any explicitly unconsented bands passed (e.g. if UI sends a separate list)
    if ( ! empty( $bands_unconsented ) ) {
        foreach ( $bands_unconsented as $band_id ) {
             if ( $band_id > 0 && get_post_type( $band_id ) === 'band_profile' ) {
                $wpdb->delete(
                    $table_name,
                    array(
                        'user_id' => $user_id,
                        'band_profile_id' => $band_id,
                        'source' => 'platform_follow_consent'
                    ),
                    array( '%d', '%d', '%s' )
                );
                $processed_bands[] = $band_id;
        }
    }
    }


    if ( ! empty( $processed_bands ) ) {
        clean_user_cache( $user_id );
        wp_send_json_success( array( 'message' => __( 'Subscription preferences updated.', 'generatepress_child' ), 'processed_bands' => array_unique($processed_bands) ) );
    } else {
        wp_send_json_success( array( 'message' => __( 'No changes to subscription preferences were made.', 'generatepress_child' ) ) );
    }
}

?> 

