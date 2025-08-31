<?php
/**
 * Centralized Join Flow Action Handlers for ExtraChill Artist Platform
 * 
 * Handles all join flow operations using WordPress native action hooks.
 * Provides one-way action flow: user registration → artist profile creation → link page creation.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects if the current user registration came from the join flow
 *
 * @return bool True if join flow registration, false otherwise
 */
function ec_is_join_flow_registration() {
    return isset( $_POST['from_join'] ) && $_POST['from_join'] === 'true';
}

/**
 * Main join flow handler - processes user registration for join flow users
 * 
 * Creates artist profile and link page automatically when users register via join flow.
 * Hooked to 'user_register' action.
 *
 * @param int $user_id The ID of the newly registered user
 */
function ec_handle_join_flow_user_registration( $user_id ) {
    // Only handle join flow registrations
    if ( ! ec_is_join_flow_registration() ) {
        return;
    }

    // Validate user exists
    $user = get_user_by( 'ID', $user_id );
    if ( ! $user ) {
        return;
    }

    // Create artist profile using the user's display name or username
    $artist_title = ! empty( $user->display_name ) ? $user->display_name : $user->user_login;
    
    $artist_data = array(
        'post_type'   => 'artist_profile',
        'post_status' => 'publish',
        'post_author' => $user_id,
        'post_title'  => sanitize_text_field( $artist_title ),
        'post_content' => '', // Empty bio initially
    );

    $artist_id = wp_insert_post( $artist_data, true );

    if ( is_wp_error( $artist_id ) || ! $artist_id ) {
        error_log( '[Join Flow] Failed to create artist profile for user ID: ' . $user_id );
        return;
    }

    // Link user as member of their own artist profile
    if ( function_exists( 'bp_add_artist_membership' ) ) {
        bp_add_artist_membership( $user_id, $artist_id );
    }

    // Create forum for the artist
    if ( function_exists( 'bp_create_artist_forum_on_save' ) ) {
        $artist_post = get_post( $artist_id );
        if ( $artist_post ) {
            bp_create_artist_forum_on_save( $artist_id, $artist_post, false );
        }
    }

    // Create link page using centralized creation system
    $link_page_result = ec_create_link_page( $artist_id );
    
    if ( is_wp_error( $link_page_result ) ) {
        error_log( '[Join Flow] Link page creation failed for artist ID: ' . $artist_id . ', Error: ' . $link_page_result->get_error_message() );
        $link_page_id = 0;
    } else {
        $link_page_id = $link_page_result;
        error_log( '[Join Flow] Successfully created artist profile and link page for user ID: ' . $user_id . ', Artist ID: ' . $artist_id . ', Link Page ID: ' . $link_page_id );
    }

    // Store join flow data for post-registration redirect
    set_transient( 'join_flow_completion_' . $user_id, array(
        'artist_id' => $artist_id,
        'link_page_id' => $link_page_id,
        'completed_at' => time()
    ), HOUR_IN_SECONDS );
}
add_action( 'user_register', 'ec_handle_join_flow_user_registration', 10, 1 );

/**
 * Get the appropriate redirect URL after join flow completion
 *
 * @param int $user_id The user ID
 * @param int $artist_id The created artist profile ID
 * @return string The redirect URL
 */
function ec_get_join_flow_redirect_url( $user_id, $artist_id ) {
    $link_page = get_page_by_path( 'manage-link-page' );
    $manage_link_page_url = $link_page ? get_permalink( $link_page ) : home_url( '/manage-link-page/' );
    
    $redirect_url = add_query_arg( array(
        'artist_id' => $artist_id,
        'from_join' => 'true'
    ), $manage_link_page_url );

    /**
     * Filters the redirect URL after join flow completion
     *
     * @param string $redirect_url The default redirect URL
     * @param int    $user_id      The user ID
     * @param int    $artist_id    The created artist profile ID
     */
    return apply_filters( 'ec_join_flow_redirect_url', $redirect_url, $user_id, $artist_id );
}

/**
 * Validates join flow registration requirements
 * 
 * Currently a placeholder for future validation needs.
 * Theme handles checkbox validation via JavaScript.
 *
 * @param WP_Error $errors               Registration error object
 * @param string   $sanitized_user_login User login after sanitization
 * @param string   $user_email           User email
 * @return WP_Error Modified errors object
 */
function ec_validate_join_flow_requirements( $errors, $sanitized_user_login, $user_email ) {
    // Join flow validation is currently handled by theme JavaScript
    // This function is available for future server-side validation needs
    
    if ( ec_is_join_flow_registration() ) {
        // Future: Add any server-side validation for join flow registrations
        
        /**
         * Allows plugins to add join flow validation errors
         *
         * @param WP_Error $errors               Current registration errors
         * @param string   $sanitized_user_login Sanitized user login
         * @param string   $user_email           User email
         */
        $errors = apply_filters( 'ec_join_flow_validation_errors', $errors, $sanitized_user_login, $user_email );
    }
    
    return $errors;
}
add_filter( 'registration_errors', 'ec_validate_join_flow_requirements', 10, 3 );

/**
 * Get join flow completion data for a user
 *
 * @param int $user_id The user ID
 * @return array|false Join flow completion data or false if not found
 */
function ec_get_join_flow_completion_data( $user_id ) {
    return get_transient( 'join_flow_completion_' . $user_id );
}

/**
 * Clear join flow completion data for a user
 *
 * @param int $user_id The user ID
 */
function ec_clear_join_flow_completion_data( $user_id ) {
    delete_transient( 'join_flow_completion_' . $user_id );
}

/**
 * Handle post-registration redirect for join flow users
 * 
 * Intercepts the default registration redirect and routes join flow users
 * to the manage-link-page with their newly created artist profile.
 * Hooked to 'registration_redirect' filter.
 *
 * @param string $redirect_to Default redirect URL
 * @param object $requested_redirect_to Requested redirect
 * @param object $user WP_User object of the logged-in user
 * @return string Modified redirect URL for join flow users, original URL otherwise
 */
function ec_handle_join_flow_registration_redirect( $redirect_to, $requested_redirect_to, $user ) {
    // Only handle if we have a valid user
    if ( ! $user || is_wp_error( $user ) ) {
        return $redirect_to;
    }

    // Check if this user has join flow completion data
    $completion_data = ec_get_join_flow_completion_data( $user->ID );
    
    if ( ! $completion_data || empty( $completion_data['artist_id'] ) ) {
        // Not a join flow registration, return original redirect
        return $redirect_to;
    }

    // Get the redirect URL for join flow users
    $join_flow_redirect = ec_get_join_flow_redirect_url( $user->ID, $completion_data['artist_id'] );
    
    // Clear the transient data after successful redirect
    ec_clear_join_flow_completion_data( $user->ID );
    
    // Return the join flow redirect URL
    return $join_flow_redirect;
}
add_filter( 'registration_redirect', 'ec_handle_join_flow_registration_redirect', 10, 3 );