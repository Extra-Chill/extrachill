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
 * Wraps the extrachill/grant-lifetime-membership ability.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with grant confirmation or error.
 */
function extrachill_api_grant_lifetime_membership( $request ) {
	$ability = wp_get_ability( 'extrachill/grant-lifetime-membership' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'Lifetime membership ability is not available.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_identifier' => $request->get_param( 'user_identifier' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Revokes a lifetime membership from a user.
 *
 * Wraps the extrachill/revoke-lifetime-membership ability.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with revoke confirmation or error.
 */
function extrachill_api_revoke_lifetime_membership( $request ) {
	$ability = wp_get_ability( 'extrachill/revoke-lifetime-membership' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'Lifetime membership ability is not available.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id' => absint( $request->get_param( 'user_id' ) ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
