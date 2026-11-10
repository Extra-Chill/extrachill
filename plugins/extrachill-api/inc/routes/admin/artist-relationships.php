<?php
/**
 * Artist-User Relationships REST API Endpoints
 *
 * Thin REST wrappers that delegate to the admin artist-relationships abilities
 * registered by extrachill-artist-platform (eap#29). The REST callbacks build an
 * input array from the request and invoke the ability via wp_get_ability()->execute().
 *
 * Architecture note (no recursion):
 * The artist-platform abilities are implemented as wrappers around the
 * extrachill_api_* implementation functions in THIS file. To keep the REST
 * callbacks thin while avoiding an infinite loop, the route callbacks are
 * dedicated shims (extrachill_api_artist_relationships_*_handler) that call the
 * abilities, while the extrachill_api_* functions remain the shared
 * implementation the abilities delegate to. Request flow:
 *
 *   HTTP request -> thin route shim -> ability->execute() -> implementation fn
 *   CLI / agent  -> ability->execute() -> implementation fn (same path, no HTTP)
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_relationships_routes' );

/**
 * Register artist relationships REST routes.
 */
function extrachill_api_register_artist_relationships_routes() {
	register_rest_route(
		'extrachill/v1',
		'/admin/artist-relationships',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_artist_relationships_list_handler',
			'permission_callback' => 'extrachill_api_artist_relationships_permission_check',
			'args'                => array(
				'view'   => array(
					'default' => 'artists',
					'type'    => 'string',
					'enum'    => array( 'artists', 'users' ),
				),
				'search' => array(
					'default' => '',
					'type'    => 'string',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/artist-relationships/link',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_artist_relationships_link_handler',
			'permission_callback' => 'extrachill_api_artist_relationships_permission_check',
			'args'                => array(
				'user_id'   => array(
					'required' => true,
					'type'     => 'integer',
				),
				'artist_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/artist-relationships/unlink',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_artist_relationships_unlink_handler',
			'permission_callback' => 'extrachill_api_artist_relationships_permission_check',
			'args'                => array(
				'user_id'   => array(
					'required' => true,
					'type'     => 'integer',
				),
				'artist_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/artist-relationships/orphans',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_artist_relationships_orphans_handler',
			'permission_callback' => 'extrachill_api_artist_relationships_permission_check',
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/artist-relationships/cleanup',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_artist_relationships_cleanup_handler',
			'permission_callback' => 'extrachill_api_artist_relationships_permission_check',
			'args'                => array(
				'user_id'   => array(
					'required' => true,
					'type'     => 'integer',
				),
				'artist_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		)
	);
}

/**
 * Permission check for artist relationships endpoints.
 *
 * @return bool|WP_Error
 */
function extrachill_api_artist_relationships_permission_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage artist relationships.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Thin REST handler: list artist-user relationships.
 *
 * Delegates to the extrachill/admin-list-artist-relationships ability.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_relationships_list_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/admin-list-artist-relationships' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'view'   => $request->get_param( 'view' ),
			'search' => $request->get_param( 'search' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Thin REST handler: link a user to an artist profile.
 *
 * Delegates to the extrachill/admin-link-artist-relationship ability.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_relationships_link_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/admin-link-artist-relationship' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id'   => absint( $request->get_param( 'user_id' ) ),
			'artist_id' => absint( $request->get_param( 'artist_id' ) ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Thin REST handler: unlink a user from an artist profile.
 *
 * Delegates to the extrachill/admin-unlink-artist-relationship ability.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_relationships_unlink_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/admin-unlink-artist-relationship' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id'   => absint( $request->get_param( 'user_id' ) ),
			'artist_id' => absint( $request->get_param( 'artist_id' ) ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Thin REST handler: list orphaned relationships.
 *
 * Delegates to the extrachill/admin-list-orphan-artist-relationships ability.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_relationships_orphans_handler( $request ) {
	unset( $request );
	$ability = wp_get_ability( 'extrachill/admin-list-orphan-artist-relationships' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Thin REST handler: clean up an orphaned relationship.
 *
 * Delegates to the extrachill/admin-cleanup-artist-relationships ability, which
 * routes through the canonical ec_remove_artist_membership remover so both
 * sides of the relationship stay in sync (api#66 fix preserved).
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_relationships_cleanup_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/admin-cleanup-artist-relationships' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id'   => absint( $request->get_param( 'user_id' ) ),
			'artist_id' => absint( $request->get_param( 'artist_id' ) ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
