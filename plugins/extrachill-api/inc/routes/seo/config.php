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
 * @return WP_REST_Response Config data.
 */
function extrachill_api_get_seo_config() {
	$default_og_image_id = function_exists( 'ExtraChill\SEO\Core\ec_seo_get_default_og_image_id' )
		? \ExtraChill\SEO\Core\ec_seo_get_default_og_image_id()
		: 0;

	$indexnow_key = function_exists( 'ExtraChill\SEO\Core\ec_seo_get_indexnow_key' )
		? \ExtraChill\SEO\Core\ec_seo_get_indexnow_key()
		: '';

	$default_og_image_url = $default_og_image_id
		? wp_get_attachment_image_url( $default_og_image_id, 'medium' )
		: '';

	return rest_ensure_response(
		array(
			'default_og_image_id'  => $default_og_image_id,
			'default_og_image_url' => $default_og_image_url,
			'indexnow_key'         => $indexnow_key,
		)
	);
}

/**
 * Updates SEO config.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Updated config or error.
 */
function extrachill_api_update_seo_config( $request ) {
	$default_og_image_id = $request->get_param( 'default_og_image_id' );
	$indexnow_key        = $request->get_param( 'indexnow_key' );

	if ( null !== $default_og_image_id ) {
		if ( function_exists( 'ExtraChill\SEO\Core\ec_seo_set_default_og_image_id' ) ) {
			\ExtraChill\SEO\Core\ec_seo_set_default_og_image_id( $default_og_image_id );
		}
	}

	if ( null !== $indexnow_key ) {
		if ( function_exists( 'ExtraChill\SEO\Core\ec_seo_set_indexnow_key' ) ) {
			\ExtraChill\SEO\Core\ec_seo_set_indexnow_key( $indexnow_key );
		}
	}

	return extrachill_api_get_seo_config();
}
