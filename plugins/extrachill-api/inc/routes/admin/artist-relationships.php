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

/*
 * ---------------------------------------------------------------------------
 * Implementation functions
 * ---------------------------------------------------------------------------
 *
 * The functions below are the shared implementation the admin
 * artist-relationships abilities (extrachill-artist-platform, eap#29) delegate
 * to by name. They are intentionally NOT wired as REST route callbacks — the
 * thin handlers above invoke the abilities, and the abilities invoke these.
 * Keeping the names stable preserves the ability contract; do not rename
 * without coordinating with the artist-platform ability handlers.
 */

/**
 * Get artist relationships (artists view or users view).
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_get_artist_relationships( $request ) {
	$view   = $request->get_param( 'view' );
	$search = $request->get_param( 'search' );

	// Switch to artist site.
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'no_artist_site', 'Artist site not configured.', array( 'status' => 400 ) );
	}

	switch_to_blog( $artist_blog_id );

	$items = array();

	if ( 'artists' === $view ) {
		$args = array(
			'post_type'      => 'artist_profile',
			'post_status'    => 'any',
			'posts_per_page' => 50,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$artists = get_posts( $args );

		foreach ( $artists as $artist ) {
			$members = array();
			if ( function_exists( 'ec_get_linked_members' ) ) {
				$members = ec_get_linked_members( $artist->ID );
			}

			$members_data = array();
			foreach ( $members as $member ) {
				$members_data[] = array(
					'ID'           => $member->ID,
					'user_login'   => $member->user_login,
					'display_name' => $member->display_name,
				);
			}

			$items[] = array(
				'id'      => $artist->ID,
				'title'   => $artist->post_title,
				'members' => $members_data,
			);
		}
	} else {
		// Users view.
		$args = array(
			'number'     => 50,
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => 'user_is_artist',
					'value'   => '1',
					'compare' => '=',
				),
				array(
					'key'     => 'user_is_professional',
					'value'   => '1',
					'compare' => '=',
				),
			),
		);

		if ( ! empty( $search ) ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$user_query = new WP_User_Query( $args );
		$users      = $user_query->get_results();

		foreach ( $users as $user ) {
			$artist_profiles = array();
			if ( function_exists( 'ec_get_user_artist_memberships' ) ) {
				$artist_profiles = ec_get_user_artist_memberships( $user->ID );
			}

			$artists_data = array();
			foreach ( $artist_profiles as $artist ) {
				$artists_data[] = array(
					'ID'         => $artist->ID,
					'post_title' => $artist->post_title,
				);
			}

			$items[] = array(
				'ID'           => $user->ID,
				'user_login'   => $user->user_login,
				'user_email'   => $user->user_email,
				'display_name' => $user->display_name,
				'artists'      => $artists_data,
			);
		}
	}

	restore_current_blog();

	return rest_ensure_response( array( 'items' => $items ) );
}

/**
 * Link a user to an artist profile.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_link_user_to_artist( $request ) {
	$user_id   = $request->get_param( 'user_id' );
	$artist_id = $request->get_param( 'artist_id' );

	// Switch to artist site.
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'no_artist_site', 'Artist site not configured.', array( 'status' => 400 ) );
	}

	switch_to_blog( $artist_blog_id );

	if ( ! function_exists( 'ec_add_member_to_artist' ) ) {
		restore_current_blog();
		return new WP_Error( 'function_missing', 'Artist platform functions not available.', array( 'status' => 500 ) );
	}

	$result = ec_add_member_to_artist( $artist_id, $user_id );

	restore_current_blog();

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( array( 'success' => true ) );
}

/**
 * Unlink a user from an artist profile.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_unlink_user_from_artist( $request ) {
	$user_id   = $request->get_param( 'user_id' );
	$artist_id = $request->get_param( 'artist_id' );

	// Switch to artist site.
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'no_artist_site', 'Artist site not configured.', array( 'status' => 400 ) );
	}

	switch_to_blog( $artist_blog_id );

	if ( ! function_exists( 'ec_remove_member_from_artist' ) ) {
		restore_current_blog();
		return new WP_Error( 'function_missing', 'Artist platform functions not available.', array( 'status' => 500 ) );
	}

	$result = ec_remove_member_from_artist( $artist_id, $user_id );

	restore_current_blog();

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( array( 'success' => true ) );
}

/**
 * Get orphaned relationships.
 *
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_get_orphaned_relationships() {
	// Switch to artist site.
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'no_artist_site', 'Artist site not configured.', array( 'status' => 400 ) );
	}

	switch_to_blog( $artist_blog_id );

	$all_users      = get_users( array( 'meta_key' => '_artist_profile_ids' ) );
	$orphaned_users = array();

	foreach ( $all_users as $user ) {
		$artist_ids = get_user_meta( $user->ID, '_artist_profile_ids', true );
		if ( ! is_array( $artist_ids ) ) {
			continue;
		}

		foreach ( $artist_ids as $artist_id ) {
			$post = get_post( $artist_id );
			if ( ! $post || 'artist_profile' !== get_post_type( $artist_id ) ) {
				$orphaned_users[] = array(
					'user'              => array(
						'ID'           => $user->ID,
						'user_login'   => $user->user_login,
						'display_name' => $user->display_name,
					),
					'invalid_artist_id' => $artist_id,
				);
			}
		}
	}

	restore_current_blog();

	return rest_ensure_response( array( 'orphans' => $orphaned_users ) );
}

/**
 * Clean up an orphaned relationship.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function extrachill_api_cleanup_orphan( $request ) {
	$user_id   = (int) $request->get_param( 'user_id' );
	$artist_id = (int) $request->get_param( 'artist_id' );

	// Route through the canonical two-sided remover so both sides of the
	// relationship stay in sync: _artist_profile_ids (user meta) AND
	// _artist_member_ids (artist post meta). Editing only the user side
	// here would leave a stale link on the artist roster — a new orphan.
	if ( function_exists( 'ec_remove_artist_membership' ) ) {
		ec_remove_artist_membership( $user_id, $artist_id );
	}

	return rest_ensure_response( array( 'success' => true ) );
}
