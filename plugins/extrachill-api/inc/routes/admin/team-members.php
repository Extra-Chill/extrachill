<?php
/**
 * Team Member Management REST API Endpoints
 *
 * Provides REST endpoints for syncing and managing team member status.
 * Requires manage_options capability (network administrators only).
 *
 * @endpoint POST /wp-json/extrachill/v1/admin/team-members/sync
 * @endpoint PUT /wp-json/extrachill/v1/admin/team-members/{user_id}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_team_member_routes' );

/**
 * Registers team member management endpoints.
 */
function extrachill_api_register_team_member_routes() {
	register_rest_route(
		'extrachill/v1',
		'/admin/team-members',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_team_members',
			'permission_callback' => 'extrachill_api_team_member_admin_permission_check',
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
		'/admin/team-members/sync',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_sync_team_members',
			'permission_callback' => 'extrachill_api_team_member_admin_permission_check',
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/team-members/(?P<user_id>\d+)',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'extrachill_api_manage_team_member',
			'permission_callback' => 'extrachill_api_team_member_admin_permission_check',
			'args'                => array(
				'user_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'action'  => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $value ) {
						return in_array( $value, array( 'force_add', 'force_remove', 'reset_auto' ), true );
					},
				),
			),
		)
	);
}

/**
 * Permission check for team member management endpoints.
 *
 * @return bool|WP_Error True if authorized, WP_Error otherwise.
 */
function extrachill_api_team_member_admin_permission_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage team members.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Gets team members with search and pagination.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with user list or error.
 */
function extrachill_api_get_team_members( $request ) {
	$search = $request->get_param( 'search' );
	$page   = $request->get_param( 'page' );
	$per_page = 20;

	$args = array(
		'blog_id' => 0, // Network-wide
		'number'  => $per_page,
		'paged'   => $page,
		'fields'  => 'all',
		'orderby' => 'display_name',
		'order'   => 'ASC',
	);

	if ( ! empty( $search ) ) {
		$args['search']         = '*' . $search . '*';
		$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
	}

	$user_query = new WP_User_Query( $args );
	$users      = $user_query->get_results();
	$total      = $user_query->get_total();

	$formatted_users = array();
	foreach ( $users as $user ) {
		$user_id         = $user->ID;
		$manual_override = get_user_meta( $user_id, 'extrachill_team_manual_override', true );
		$is_team_member  = false;
		$source          = 'Auto';

		if ( 'add' === $manual_override ) {
			$is_team_member = true;
			$source         = 'Manual: Add';
		} elseif ( 'remove' === $manual_override ) {
			$is_team_member = false;
			$source         = 'Manual: Remove';
		} else {
			$is_team_member = get_user_meta( $user_id, 'extrachill_team', true ) == 1;
			$source         = 'Auto';
		}

		$formatted_users[] = array(
			'ID'             => $user_id,
			'user_login'     => $user->user_login,
			'user_email'     => $user->user_email,
			'is_team_member' => $is_team_member,
			'source'         => $source,
		);
	}

	return rest_ensure_response(
		array(
			'users'       => $formatted_users,
			'total'       => $total,
			'total_pages' => ceil( $total / $per_page ),
		)
	);
}

/**
 * Syncs team member status for all network users.
 *
 * Wraps the extrachill/sync-team-members ability.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with sync report or error.
 */
function extrachill_api_sync_team_members( $request ) {
	$ability = wp_get_ability( 'extrachill/sync-team-members' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'Team members ability is not available.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Manages team member status for a single user.
 *
 * Wraps the extrachill/manage-team-member ability.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with result or error.
 */
function extrachill_api_manage_team_member( $request ) {
	$ability = wp_get_ability( 'extrachill/manage-team-member' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'Team members ability is not available.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id' => absint( $request->get_param( 'user_id' ) ),
			'action'  => $request->get_param( 'action' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
