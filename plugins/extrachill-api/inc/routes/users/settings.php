<?php
/**
 * User Settings REST API Endpoints
 *
 * GET  /wp-json/extrachill/v1/users/me/settings       - Get settings
 * POST /wp-json/extrachill/v1/users/me/settings       - Update settings
 * POST /wp-json/extrachill/v1/users/me/email           - Change email
 * POST /wp-json/extrachill/v1/users/me/password        - Change password
 *
 * Delegates to extrachill-users abilities.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_settings_routes' );

function extrachill_api_register_user_settings_routes() {
	// Settings (GET + POST).
	register_rest_route(
		'extrachill/v1',
		'/users/me/settings',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_user_settings_get',
				'permission_callback' => 'extrachill_api_user_settings_permission',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_user_settings_update',
				'permission_callback' => 'extrachill_api_user_settings_permission',
				'args'                => array(
					'first_name'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'last_name'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'display_name' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		)
	);

	// Email change.
	register_rest_route(
		'extrachill/v1',
		'/users/me/email',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_user_email_change',
			'permission_callback' => 'extrachill_api_user_settings_permission',
			'args'                => array(
				'new_email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
			),
		)
	);

	// Password change.
	register_rest_route(
		'extrachill/v1',
		'/users/me/password',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_user_password_change',
			'permission_callback' => 'extrachill_api_user_settings_permission',
			'args'                => array(
				'current_password' => array(
					'required' => true,
					'type'     => 'string',
				),
				'new_password'     => array(
					'required' => true,
					'type'     => 'string',
				),
				'confirm_password' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
}

/**
 * Permission check — must be logged in.
 */
function extrachill_api_user_settings_permission( WP_REST_Request $request ) {
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
 * GET /users/me/settings
 */
function extrachill_api_user_settings_get( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-user-settings' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'user_id' => get_current_user_id() ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * POST /users/me/settings
 */
function extrachill_api_user_settings_update( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/update-user-settings' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$input = array( 'user_id' => get_current_user_id() );

	if ( null !== $request->get_param( 'first_name' ) ) {
		$input['first_name'] = $request->get_param( 'first_name' );
	}
	if ( null !== $request->get_param( 'last_name' ) ) {
		$input['last_name'] = $request->get_param( 'last_name' );
	}
	if ( null !== $request->get_param( 'display_name' ) ) {
		$input['display_name'] = $request->get_param( 'display_name' );
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( $result );
}

/**
 * POST /users/me/email
 */
function extrachill_api_user_email_change( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/change-user-email' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id'   => get_current_user_id(),
			'new_email' => $request->get_param( 'new_email' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( $result );
}

/**
 * POST /users/me/password
 */
function extrachill_api_user_password_change( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/change-user-password' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id'          => get_current_user_id(),
			'current_password' => $request->get_param( 'current_password' ),
			'new_password'     => $request->get_param( 'new_password' ),
			'confirm_password' => $request->get_param( 'confirm_password' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( $result );
}
