<?php
/**
 * REST route: auth me.
 *
 * GET /wp-json/extrachill/v1/auth/me
 *
 * Returns authenticated user data. Requires bearer token.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_me_route' );

/**
 * Registers the auth me route.
 */
function extrachill_api_register_auth_me_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/me',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_auth_me_handler',
			'permission_callback' => 'is_user_logged_in',
		)
	);
}

/**
 * Handles the auth me request.
 *
 * @param WP_REST_Request $request Request data.
 * @return array|WP_Error
 */
function extrachill_api_auth_me_handler( WP_REST_Request $request ) {
	$user = wp_get_current_user();

	if ( ! $user || ! $user->exists() ) {
		return new WP_Error(
			'not_authenticated',
			'User not authenticated.',
			array( 'status' => 401 )
		);
	}

	$onboarding_completed = function_exists( 'ec_is_onboarding_complete' )
		? ec_is_onboarding_complete( $user->ID )
		: true;

	$response = array(
		'id'                   => (int) $user->ID,
		'username'             => $user->user_login,
		'email'                => $user->user_email,
		'display_name'         => $user->display_name,
		'avatar_url'           => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
		'profile_url'          => function_exists( 'extrachill_get_user_profile_url' )
			? extrachill_get_user_profile_url( $user->ID, $user->user_email )
			: '',
		'registered'           => $user->user_registered,
		'onboarding_completed' => $onboarding_completed,
	);

	if ( function_exists( 'ec_get_artists_for_user' ) ) {
		$artist_ids             = ec_get_artists_for_user( $user->ID );
		$response['artist_ids'] = is_array( $artist_ids )
			? array_values( array_map( 'absint', $artist_ids ) )
			: array();
	}

	if ( function_exists( 'ec_get_latest_artist_for_user' ) ) {
		$response['latest_artist_id'] = (int) ec_get_latest_artist_for_user( $user->ID );
	}

	if ( function_exists( 'ec_get_link_page_count_for_user' ) ) {
		$response['link_page_count'] = (int) ec_get_link_page_count_for_user( $user->ID );
	}

	if ( function_exists( 'ec_can_create_artist_profiles' ) ) {
		$response['can_create_artists'] = (bool) ec_can_create_artist_profiles( $user->ID );
	}

	if ( function_exists( 'ec_can_manage_shop' ) ) {
		$response['can_manage_shop'] = (bool) ec_can_manage_shop( $user->ID );
	}

	if ( function_exists( 'ec_get_shop_product_count_for_user' ) ) {
		$response['shop_product_count'] = (int) ec_get_shop_product_count_for_user( $user->ID );
	}

	if ( function_exists( 'ec_get_site_url' ) ) {
		$response['site_urls'] = array(
			'community' => (string) ec_get_site_url( 'community' ),
			'artist'    => (string) ec_get_site_url( 'artist' ),
			'shop'      => (string) ec_get_site_url( 'shop' ),
		);
	}

	$response = apply_filters( 'extrachill_auth_me_response', $response, $user );

	return rest_ensure_response( $response );
}
