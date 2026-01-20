<?php
/**
 * Community User Creation Handler
 *
 * Centralized user creation on community.extrachill.com for network-wide registration.
 * Only switches blogs when necessary (i.e., when not already on community site).
 * Sets onboarding meta for new users to complete username/flags selection.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'extrachill_create_community_user', 'ec_multisite_create_community_user', 10, 2 );

/**
 * Create user account on community.extrachill.com (single source of truth)
 *
 * @param mixed $user_id          Default false, will be replaced with actual user_id.
 * @param array $registration_data Array containing username, password, email, from_join, registration_page.
 * @return int|WP_Error User ID on success, WP_Error on failure.
 */
function ec_multisite_create_community_user( $user_id, $registration_data ) {
	$username            = isset( $registration_data['username'] ) ? $registration_data['username'] : '';
	$password            = isset( $registration_data['password'] ) ? $registration_data['password'] : '';
	$email               = isset( $registration_data['email'] ) ? $registration_data['email'] : '';
	$from_join           = isset( $registration_data['from_join'] ) ? (bool) $registration_data['from_join'] : false;
	$registration_page   = isset( $registration_data['registration_page'] ) ? $registration_data['registration_page'] : '';
	$registration_source = isset( $registration_data['registration_source'] ) ? $registration_data['registration_source'] : '';
	$registration_method = isset( $registration_data['registration_method'] ) ? $registration_data['registration_method'] : '';

	if ( empty( $username ) || empty( $password ) || empty( $email ) ) {
		return new WP_Error( 'missing_fields', 'Username, password, and email are required.' );
	}

	$current_blog_id = get_current_blog_id();
	$switched        = false;

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( $community_blog_id && $current_blog_id !== $community_blog_id ) {
		switch_to_blog( $community_blog_id );
		$switched = true;
	}

	$user_id = wp_create_user( $username, $password, $email );

	if ( ! is_wp_error( $user_id ) ) {
		if ( ! empty( $registration_page ) ) {
			update_user_meta( $user_id, 'registration_page', esc_url_raw( (string) $registration_page ) );
		}

		if ( ! empty( $registration_source ) ) {
			update_user_meta( $user_id, 'registration_source', sanitize_text_field( (string) $registration_source ) );
		}

		if ( ! empty( $registration_method ) ) {
			update_user_meta( $user_id, 'registration_method', sanitize_text_field( (string) $registration_method ) );
		}

		if ( function_exists( 'ec_mark_user_for_onboarding' ) ) {
			ec_mark_user_for_onboarding( $user_id, $from_join );
		} else {
			update_user_meta( $user_id, 'onboarding_completed', '0' );
			update_user_meta( $user_id, 'onboarding_from_join', $from_join ? '1' : '0' );
		}
	}

	if ( $switched ) {
		restore_current_blog();
	}

	if ( ! is_wp_error( $user_id ) ) {
		do_action( 'extrachill_new_user_registered', $user_id, $registration_page, $registration_source, $registration_method );
	}

	return $user_id;
}
