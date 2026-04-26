<?php
/**
 * Network Media REST routes.
 *
 * Thin REST wrapper over the `extrachill/network-media-list` and
 * `extrachill/network-media-upload` abilities registered in
 * extrachill-multisite. The abilities own all business logic — these
 * handlers parse params, call the ability, and return the result.
 *
 *   GET  /wp-json/extrachill/v1/network-media   list main-site media
 *   POST /wp-json/extrachill/v1/network-media   upload to main-site media
 *
 * Phase 1 of the network-wide unified media library tracked in
 * extrachill-multisite#2 — currently scoped to blog 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_network_media_routes' );

function extrachill_api_register_network_media_routes() {
	register_rest_route(
		'extrachill/v1',
		'/network-media',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_network_media_list',
				'permission_callback' => 'extrachill_api_network_media_permission',
				'args'                => array(
					'media_type' => array(
						'type'              => 'string',
						'enum'              => array( 'image', 'video', 'audio' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page'   => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'       => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_network_media_upload',
				'permission_callback' => 'extrachill_api_network_media_permission',
			),
		)
	);
}

/**
 * Permission check.
 *
 * Defers to the ability's own permission_callback so the rule lives in
 * one place. The ability requires `upload_files` on blog 1.
 */
function extrachill_api_network_media_permission( WP_REST_Request $request ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return new WP_Error( 'abilities_unavailable', 'Abilities API not available.', array( 'status' => 500 ) );
	}

	$ability = wp_get_ability( 'extrachill/network-media-list' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'Network media ability not registered.', array( 'status' => 500 ) );
	}

	return $ability->has_permission( $request->get_params() );
}

/**
 * GET handler — list main-site media.
 */
function extrachill_api_network_media_list( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/network-media-list' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'Network media list ability not registered.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'media_type' => (string) $request->get_param( 'media_type' ),
			'search'     => (string) $request->get_param( 'search' ),
			'per_page'   => (int) $request->get_param( 'per_page' ),
			'page'       => (int) $request->get_param( 'page' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * POST handler — upload to main-site media library.
 *
 * Marshals `$_FILES['file']` into the ability's input shape. The ability
 * runs the actual `wp_handle_upload` + `wp_insert_attachment` in blog 1's
 * context.
 */
function extrachill_api_network_media_upload( WP_REST_Request $request ) {
	$files = $request->get_file_params();

	if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
		return new WP_Error( 'no_file', 'No file uploaded.', array( 'status' => 400 ) );
	}

	$file = $files['file'];

	if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
		return new WP_Error( 'upload_error', 'PHP reported an upload error.', array( 'status' => 400, 'php_error' => $file['error'] ) );
	}

	$ability = wp_get_ability( 'extrachill/network-media-upload' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'Network media upload ability not registered.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'tmp_name' => (string) $file['tmp_name'],
			'name'     => (string) $file['name'],
			'type'     => (string) $file['type'],
			'size'     => (int) $file['size'],
			'title'    => (string) $request->get_param( 'title' ),
			'alt'      => (string) $request->get_param( 'alt' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
