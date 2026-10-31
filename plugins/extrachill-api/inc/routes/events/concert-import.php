<?php
/**
 * REST routes: Concert import framework.
 *
 * Thin REST wrappers over the concert-import abilities in extrachill-users.
 *
 * Endpoints (all under /extrachill/v1):
 *   GET  /concert-import/sources           — list registered sources
 *   POST /concert-import/preview           — username probe + total count
 *   POST /concert-import/start             — queue a background import
 *   GET  /concert-import/status            — list current user's runs
 *
 * @package ExtraChillAPI
 * @since 0.17.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_concert_import_routes' );

function extrachill_api_register_concert_import_routes() {

	register_rest_route(
		'extrachill/v1',
		'/concert-import/sources',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_handle_concert_import_sources',
			'permission_callback' => 'is_user_logged_in',
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/concert-import/preview',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_concert_import_preview',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'source'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'username' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/concert-import/start',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_concert_import_start',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'source'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'username' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/concert-import/status',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_handle_concert_import_status',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'limit' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 20,
				),
			),
		)
	);
}

function extrachill_api_handle_concert_import_sources( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/list-concert-import-sources' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result );
}

function extrachill_api_handle_concert_import_preview( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/preview-concert-import' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'source'   => (string) $request->get_param( 'source' ),
			'username' => (string) $request->get_param( 'username' ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result );
}

function extrachill_api_handle_concert_import_start( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/start-concert-import' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'source'   => (string) $request->get_param( 'source' ),
			'username' => (string) $request->get_param( 'username' ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result );
}

function extrachill_api_handle_concert_import_status( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-concert-import-status' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'limit' => (int) $request->get_param( 'limit' ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result );
}
