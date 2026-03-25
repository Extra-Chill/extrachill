<?php
/**
 * User Profile REST API Endpoints
 *
 * GET  /wp-json/extrachill/v1/users/me/profile    - Get profile
 * POST /wp-json/extrachill/v1/users/me/profile    - Update profile
 * POST /wp-json/extrachill/v1/users/me/links      - Update links
 *
 * Delegates to extrachill-users abilities.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_profile_routes' );

function extrachill_api_register_user_profile_routes() {
	// Profile (GET + POST).
	register_rest_route(
		'extrachill/v1',
		'/users/me/profile',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_user_profile_get',
				'permission_callback' => 'extrachill_api_user_profile_permission',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_user_profile_update',
				'permission_callback' => 'extrachill_api_user_profile_permission',
				'args'                => array(
					'custom_title' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'bio'          => array(
						'type' => 'string',
					),
					'local_city'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		)
	);

	// Links (POST — replaces all links).
	register_rest_route(
		'extrachill/v1',
		'/users/me/links',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_user_links_update',
			'permission_callback' => 'extrachill_api_user_profile_permission',
			'args'                => array(
				'links' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'type_key'     => array( 'type' => 'string' ),
							'url'          => array( 'type' => 'string' ),
							'custom_label' => array( 'type' => 'string' ),
						),
					),
				),
			),
		)
	);
}

/**
 * Permission check — must be logged in.
 */
function extrachill_api_user_profile_permission( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'You must be logged in.', 'extrachill-api' ),
			array( 'status' => 401 )
		);
	}
	return true;
}

/**
 * GET /users/me/profile
 */
function extrachill_api_user_profile_get( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-user-profile' );

	if ( ! $ability ) {
		return new WP_Error( 'service_unavailable', 'User profile service not available.', array( 'status' => 503 ) );
	}

	$result = $ability->execute( array( 'user_id' => get_current_user_id() ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * POST /users/me/profile
 */
function extrachill_api_user_profile_update( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/update-user-profile' );

	if ( ! $ability ) {
		return new WP_Error( 'service_unavailable', 'User profile service not available.', array( 'status' => 503 ) );
	}

	$input = array( 'user_id' => get_current_user_id() );

	if ( null !== $request->get_param( 'custom_title' ) ) {
		$input['custom_title'] = $request->get_param( 'custom_title' );
	}
	if ( null !== $request->get_param( 'bio' ) ) {
		$input['bio'] = $request->get_param( 'bio' );
	}
	if ( null !== $request->get_param( 'local_city' ) ) {
		$input['local_city'] = $request->get_param( 'local_city' );
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( $result );
}

/**
 * POST /users/me/links
 */
function extrachill_api_user_links_update( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/update-user-links' );

	if ( ! $ability ) {
		return new WP_Error( 'service_unavailable', 'User links service not available.', array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'user_id' => get_current_user_id(),
			'links'   => $request->get_param( 'links' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( $result );
}
