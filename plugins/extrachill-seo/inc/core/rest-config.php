<?php
/**
 * Config REST API
 *
 * REST endpoints for managing network-level SEO settings.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\ec_seo_register_config_routes' );

/**
 * Registers REST routes for managing SEO config.
 */
function ec_seo_register_config_routes() {
	register_rest_route(
		'extrachill/v1',
		'/seo/config',
		array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => __NAMESPACE__ . '\\ec_seo_update_config',
				'permission_callback' => __NAMESPACE__ . '\\ec_seo_can_manage_network_seo',
				'args'                => array(
					'default_og_image_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'indexnow_key'        => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => __NAMESPACE__ . '\\ec_seo_sanitize_indexnow_key',
					),
				),
			),
		)
	);
}

/**
 * Permission callback for SEO config management.
 *
 * @return bool Whether the current user can manage network SEO options.
 */
function ec_seo_can_manage_network_seo() {
	return is_user_logged_in() && current_user_can( 'manage_network_options' );
}

/**
 * Sanitizes IndexNow key value.
 *
 * @param string $value Raw request value.
 * @return string Sanitized key.
 */
function ec_seo_sanitize_indexnow_key( $value ) {
	return sanitize_text_field( (string) $value );
}

/**
 * Updates SEO config settings.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response with saved config.
 */
function ec_seo_update_config( WP_REST_Request $request ) {
	$default_og_image_id = absint( $request->get_param( 'default_og_image_id' ) );
	$indexnow_key        = ec_seo_sanitize_indexnow_key( $request->get_param( 'indexnow_key' ) );

	ec_seo_set_default_og_image_id( $default_og_image_id );
	ec_seo_set_indexnow_key( $indexnow_key );

	$default_og_image_url = $default_og_image_id
		? wp_get_attachment_image_url( $default_og_image_id, 'medium' )
		: '';

	return new WP_REST_Response(
		array(
			'default_og_image_id'  => $default_og_image_id,
			'default_og_image_url' => $default_og_image_url,
			'indexnow_key'         => $indexnow_key,
		),
		200
	);
}
