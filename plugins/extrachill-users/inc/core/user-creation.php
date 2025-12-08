<?php
/**
 * Community User Creation Handler
 *
 * Centralized user creation on community.extrachill.com for network-wide registration.
 * Only switches blogs when necessary (i.e., when not already on community site).
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'extrachill_create_community_user', 'ec_multisite_create_community_user', 10, 2 );

/**
 * Create user account on community.extrachill.com (single source of truth)
 *
 * @param mixed $user_id Default false, will be replaced with actual user_id
 * @param array $registration_data Array containing username, password, email, user_is_artist, user_is_professional
 * @return int|WP_Error User ID on success, WP_Error on failure
 */
function ec_multisite_create_community_user( $user_id, $registration_data ) {
	// Extract registration data
	$username             = isset( $registration_data['username'] ) ? $registration_data['username'] : '';
	$password             = isset( $registration_data['password'] ) ? $registration_data['password'] : '';
	$email                = isset( $registration_data['email'] ) ? $registration_data['email'] : '';
	$user_is_artist       = isset( $registration_data['user_is_artist'] ) ? $registration_data['user_is_artist'] : false;
	$user_is_professional = isset( $registration_data['user_is_professional'] ) ? $registration_data['user_is_professional'] : false;
	$registration_page    = isset( $registration_data['registration_page'] ) ? $registration_data['registration_page'] : '';

	// Validate required fields
	if ( empty( $username ) || empty( $password ) || empty( $email ) ) {
		return new WP_Error( 'missing_fields', 'Username, password, and email are required.' );
	}

	// Get community blog ID and check if we need to switch
	$current_blog_id   = get_current_blog_id();
	$switched          = false;

	// Switch to community site only if we're not already there
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( $community_blog_id && $current_blog_id !== $community_blog_id ) {
		switch_to_blog( $community_blog_id );
		$switched = true;
	}

	// Create user on community site
	$user_id = wp_create_user( $username, $password, $email );

    // If user creation successful, set user meta before any hooks rely on it
    if ( ! is_wp_error( $user_id ) ) {
        update_user_meta( $user_id, 'registration_page', $registration_page );
        update_user_meta( $user_id, 'user_is_artist', $user_is_artist ? '1' : '0' );
        update_user_meta( $user_id, 'user_is_professional', $user_is_professional ? '1' : '0' );
    }


    // Restore original blog context if we switched
    if ( $switched ) {
        restore_current_blog();
    }

    if ( ! is_wp_error( $user_id ) ) {
        do_action( 'extrachill_new_user_registered', $user_id, $registration_page );
    }

    return $user_id;
}

