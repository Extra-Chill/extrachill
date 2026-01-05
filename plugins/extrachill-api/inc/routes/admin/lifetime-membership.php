<?php
/**
 * Lifetime Membership Management REST API Endpoints
 *
 * Provides REST endpoints for granting and revoking lifetime memberships.
 * Requires manage_options capability (network administrators only).
 *
 * @endpoint POST /wp-json/extrachill/v1/admin/lifetime-membership/grant
 * @endpoint DELETE /wp-json/extrachill/v1/admin/lifetime-membership/{user_id}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_lifetime_membership_routes' );

/**
 * Registers lifetime membership management endpoints.
 */
function extrachill_api_register_lifetime_membership_routes() {
	register_rest_route(
		'extrachill/v1',
		'/admin/lifetime-membership',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_lifetime_memberships',
			'permission_callback' => 'extrachill_api_lifetime_membership_admin_permission_check',
			'args'                => array(
				'search' => array(
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				),
				'page'   => array(
					'sanitize_callback' => 'absint',
					'default'           => 1,
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/lifetime-membership/grant',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_grant_lifetime_membership',
			'permission_callback' => 'extrachill_api_lifetime_membership_admin_permission_check',
			'args'                => array(
				'user_identifier' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Username or email address of user to grant membership to.',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/lifetime-membership/(?P<user_id>\d+)',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'extrachill_api_revoke_lifetime_membership',
			'permission_callback' => 'extrachill_api_lifetime_membership_admin_permission_check',
			'args'                => array(
				'user_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Permission check for lifetime membership management endpoints.
 *
 * @return bool|WP_Error True if authorized, WP_Error otherwise.
 */
function extrachill_api_lifetime_membership_admin_permission_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage lifetime memberships.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Gets lifetime memberships with search and pagination.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with member list or error.
 */
function extrachill_api_get_lifetime_memberships( $request ) {
	$search = $request->get_param( 'search' );
	$page   = $request->get_param( 'page' );
	$per_page = 20;

	$args = array(
		'blog_id'  => 0, // Network-wide
		'meta_key' => 'extrachill_lifetime_membership',
		'number'   => $per_page,
		'paged'    => $page,
		'fields'   => 'all',
		'orderby'  => 'display_name',
		'order'    => 'ASC',
	);

	if ( ! empty( $search ) ) {
		$args['search']         = '*' . $search . '*';
		$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
	}

	$user_query = new WP_User_Query( $args );
	$users      = $user_query->get_results();
	$total      = $user_query->get_total();

	$formatted_members = array();
	foreach ( $users as $user ) {
		$membership_data = get_user_meta( $user->ID, 'extrachill_lifetime_membership', true );
		if ( empty( $membership_data ) ) {
			continue;
		}

		$formatted_members[] = array(
			'ID'             => $user->ID,
			'user_login'     => $user->user_login,
			'user_email'     => $user->user_email,
			'purchased'      => isset( $membership_data['purchased'] ) ? $membership_data['purchased'] : 'N/A',
			'order_id'       => isset( $membership_data['order_id'] ) && $membership_data['order_id'] ? $membership_data['order_id'] : 'Manual',
		);
	}

	return rest_ensure_response(
		array(
			'members'     => $formatted_members,
			'total'       => $total,
			'total_pages' => ceil( $total / $per_page ),
		)
	);
}

/**
 * Grants a lifetime membership to a user.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with grant confirmation or error.
 */
function extrachill_api_grant_lifetime_membership( $request ) {
	$identifier = $request->get_param( 'user_identifier' );

	if ( empty( $identifier ) ) {
		return new WP_Error(
			'missing_identifier',
			'User identifier is required.',
			array( 'status' => 400 )
		);
	}

	$user = get_user_by( 'login', $identifier );
	if ( ! $user ) {
		$user = get_user_by( 'email', $identifier );
	}

	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	$existing = get_user_meta( $user->ID, 'extrachill_lifetime_membership', true );
	if ( $existing ) {
		return new WP_Error(
			'membership_exists',
			'User already has a lifetime membership.',
			array( 'status' => 409 )
		);
	}

	$membership_data = array(
		'purchased' => current_time( 'mysql' ),
		'order_id'  => null,
		'username'  => $user->user_login,
	);

	update_user_meta( $user->ID, 'extrachill_lifetime_membership', $membership_data );

	return rest_ensure_response(
		array(
			'message'  => "Lifetime membership granted to {$user->user_login}",
			'user_id'  => $user->ID,
			'username' => $user->user_login,
			'email'    => $user->user_email,
		)
	);
}

/**
 * Revokes a lifetime membership from a user.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with revoke confirmation or error.
 */
function extrachill_api_revoke_lifetime_membership( $request ) {
	$user_id = $request->get_param( 'user_id' );

	if ( ! $user_id ) {
		return new WP_Error(
			'missing_user_id',
			'User ID is required.',
			array( 'status' => 400 )
		);
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	$existing = get_user_meta( $user_id, 'extrachill_lifetime_membership', true );
	if ( ! $existing ) {
		return new WP_Error(
			'no_membership',
			'User does not have a lifetime membership.',
			array( 'status' => 404 )
		);
	}

	delete_user_meta( $user_id, 'extrachill_lifetime_membership' );

	return rest_ensure_response(
		array(
			'message'  => "Lifetime membership revoked for {$user->user_login}",
			'user_id'  => $user_id,
			'username' => $user->user_login,
		)
	);
}
