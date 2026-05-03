<?php
/**
 * SEO Config REST API Endpoint
 *
 * Get and update SEO configuration settings.
 *
 * @endpoint GET/PUT /wp-json/extrachill/v1/seo/config
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_seo_config_route' );

/**
 * Registers the SEO config endpoint.
 */
function extrachill_api_register_seo_config_route() {
	register_rest_route(
		'extrachill/v1',
		'/seo/config',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_get_seo_config',
				'permission_callback' => 'extrachill_api_seo_config_permission_check',
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'extrachill_api_update_seo_config',
				'permission_callback' => 'extrachill_api_seo_config_permission_check',
				'args'                => array(
					'default_og_image_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'indexnow_key'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		)
	);
}

/**
 * Permission check for SEO config endpoints.
 *
 * @return bool|WP_Error True if authorized, WP_Error otherwise.
 */
function extrachill_api_seo_config_permission_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage SEO settings.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Gets current SEO config.
 *
 * Wraps the extrachill/get-seo-config ability from extrachill-seo.
 *
 * @return WP_REST_Response|WP_Error Config data or error.
 */
function extrachill_api_get_seo_config() {
	$ability = wp_get_ability( 'extrachill/get-seo-config' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-seo plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Updates SEO config.
 *
 * Wraps the extrachill/update-seo-config ability from extrachill-seo.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Updated config or error.
 */
function extrachill_api_update_seo_config( $request ) {
	$ability = wp_get_ability( 'extrachill/update-seo-config' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-seo plugin is required.', array( 'status' => 500 ) );
	}

	$params = array();
	if ( null !== $request->get_param( 'default_og_image_id' ) ) {
		$params['default_og_image_id'] = $request->get_param( 'default_og_image_id' );
	}
	if ( null !== $request->get_param( 'indexnow_key' ) ) {
		$params['indexnow_key'] = $request->get_param( 'indexnow_key' );
	}

	$result = $ability->execute( $params );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
