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
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with sync report or error.
 */
function extrachill_api_sync_team_members( $request ) {
	if ( ! function_exists( 'ec_has_main_site_account' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Required function ec_has_main_site_account() not available.',
			array( 'status' => 500 )
		);
	}

	$report = array(
		'total_users'                   => 0,
		'users_updated'                 => 0,
		'users_skipped_override'        => 0,
		'users_with_main_site_account'  => 0,
	);

	$users = get_users(
		array(
			'blog_id' => 0,
			'fields'  => 'ID',
		)
	);

	$report['total_users'] = count( $users );

	foreach ( $users as $user_id ) {
		$manual_override = get_user_meta( $user_id, 'extrachill_team_manual_override', true );

		if ( 'add' === $manual_override || 'remove' === $manual_override ) {
			$report['users_skipped_override']++;
			continue;
		}

		$has_main_account = ec_has_main_site_account( $user_id );

		if ( $has_main_account ) {
			$report['users_with_main_site_account']++;

			$current_status = get_user_meta( $user_id, 'extrachill_team', true );
			if ( 1 != $current_status ) {
				update_user_meta( $user_id, 'extrachill_team', 1 );
				$report['users_updated']++;
			}
		} else {
			$current_status = get_user_meta( $user_id, 'extrachill_team', true );
			if ( 1 == $current_status ) {
				update_user_meta( $user_id, 'extrachill_team', 0 );
				$report['users_updated']++;
			}
		}
	}

	return rest_ensure_response( $report );
}

/**
 * Manages team member status for a single user.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with result or error.
 */
function extrachill_api_manage_team_member( $request ) {
	$user_id = $request->get_param( 'user_id' );
	$action  = $request->get_param( 'action' );

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	switch ( $action ) {
		case 'force_add':
			update_user_meta( $user_id, 'extrachill_team_manual_override', 'add' );
			update_user_meta( $user_id, 'extrachill_team', 1 );
			return rest_ensure_response(
				array(
					'message'        => 'User forced to team member.',
					'user_id'        => $user_id,
					'is_team_member' => true,
					'source'         => 'Manual: Add',
				)
			);

		case 'force_remove':
			update_user_meta( $user_id, 'extrachill_team_manual_override', 'remove' );
			update_user_meta( $user_id, 'extrachill_team', 0 );
			return rest_ensure_response(
				array(
					'message'        => 'User forced to non-team member.',
					'user_id'        => $user_id,
					'is_team_member' => false,
					'source'         => 'Manual: Remove',
				)
			);

		case 'reset_auto':
			delete_user_meta( $user_id, 'extrachill_team_manual_override' );
			$has_main_account = function_exists( 'ec_has_main_site_account' ) ? ec_has_main_site_account( $user_id ) : false;
			update_user_meta( $user_id, 'extrachill_team', $has_main_account ? 1 : 0 );
			return rest_ensure_response(
				array(
					'message'        => 'User reset to auto sync.',
					'user_id'        => $user_id,
					'is_team_member' => $has_main_account,
					'source'         => 'Auto',
				)
			);

		default:
			return new WP_Error(
				'invalid_action',
				'Invalid action specified.',
				array( 'status' => 400 )
			);
	}
}
