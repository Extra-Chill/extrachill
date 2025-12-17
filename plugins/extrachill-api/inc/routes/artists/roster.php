<?php
/**
 * REST routes for artist roster management
 *
 * GET    /artists/{id}/roster                      - List roster members and pending invites
 * POST   /artists/{id}/roster                      - Invite member by email
 * DELETE /artists/{id}/roster/{user_id}            - Remove roster member
 * DELETE /artists/{id}/roster/invites/{invite_id}  - Cancel pending invite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_roster_routes' );

function extrachill_api_register_artist_roster_routes() {
	// Roster endpoints under the artist resource
	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/roster', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_artist_roster_list_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/roster', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_artist_roster_invite_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id'    => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'email' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			),
		),
	) );

	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/roster/(?P<user_id>\d+)', array(
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'extrachill_api_artist_roster_remove_member_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id'      => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'user_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/roster/invites/(?P<invite_id>[A-Za-z0-9_-]+)', array(
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'extrachill_api_artist_roster_cancel_invite_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id'        => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'invite_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}

/**
 * Handles artist roster invitation requests
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_roster_invite_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$email     = $request->get_param( 'email' );

	if ( ! is_email( $email ) ) {
		return new WP_Error(
			'invalid_email',
			__( 'Please enter a valid email address.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			__( 'Invalid artist specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to manage members for this artist.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	$result = apply_filters( 'extrachill_artist_invite_member', null, $artist_id, $email );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) || ! isset( $result['id'] ) ) {
		return new WP_Error(
			'invitation_failed',
			__( 'Could not create invitation. Please try again.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array(
		'message'    => __( 'Invitation successfully sent.', 'extrachill-api' ),
		'invitation' => $result,
	) );
}

/**
 * Lists linked members and pending invites for an artist.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_roster_list_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			__( 'Invalid artist specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to view the roster for this artist.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	$members = array();
	if ( function_exists( 'ec_get_linked_members' ) ) {
		$linked_members = ec_get_linked_members( $artist_id );
		if ( is_array( $linked_members ) ) {
			foreach ( $linked_members as $member ) {
				$user_info = get_userdata( $member->ID );
				if ( $user_info ) {
					$members[] = array(
						'id'           => (int) $user_info->ID,
						'display_name' => $user_info->display_name,
						'username'     => $user_info->user_login,
						'email'        => $user_info->user_email,
						'avatar_url'   => get_avatar_url( $user_info->ID, array( 'size' => 60 ) ),
						'profile_url'  => function_exists( 'ec_get_user_profile_url' ) ? ec_get_user_profile_url( $user_info->ID, $user_info->user_email ) : '',
					);
				}
			}
		}
	}

	$invites = array();
	if ( function_exists( 'ec_get_pending_invitations' ) ) {
		$pending = ec_get_pending_invitations( $artist_id );
		if ( is_array( $pending ) ) {
			foreach ( $pending as $invite ) {
				$invited_on = isset( $invite['invited_on'] ) ? (int) $invite['invited_on'] : 0;
				$invites[]  = array(
					'id'                   => isset( $invite['id'] ) ? $invite['id'] : '',
					'email'                => isset( $invite['email'] ) ? $invite['email'] : '',
					'of_existing_user'     => email_exists( isset( $invite['email'] ) ? $invite['email'] : '' ) ? true : false,
					'status'               => isset( $invite['status'] ) ? $invite['status'] : '',
					'invited_on'           => $invited_on,
					'invited_on_formatted' => $invited_on ? date_i18n( get_option( 'date_format' ), $invited_on ) : '',
				);
			}
		}
	}

	return rest_ensure_response( array(
		'members' => $members,
		'invites' => $invites,
	) );
}

/**
 * Removes a linked member from the artist roster.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_roster_remove_member_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$user_id   = $request->get_param( 'user_id' );

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			__( 'Invalid artist specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to manage members for this artist.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	if ( ! function_exists( 'ec_remove_artist_membership' ) ) {
		return new WP_Error(
			'not_supported',
			__( 'Roster removal is not available.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$removed = ec_remove_artist_membership( $user_id, $artist_id );

	if ( ! $removed ) {
		return new WP_Error(
			'remove_failed',
			__( 'Could not remove the member from the artist.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array(
		'removed'  => true,
		'user_id'  => (int) $user_id,
		'artist_id'=> (int) $artist_id,
	) );
}

/**
 * Cancels a pending invitation for an artist.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_roster_cancel_invite_handler( WP_REST_Request $request ) {
	$artist_id  = $request->get_param( 'id' );
	$invite_id  = $request->get_param( 'invite_id' );

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			__( 'Invalid artist specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to manage members for this artist.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	if ( ! function_exists( 'ec_remove_pending_invitation' ) ) {
		return new WP_Error(
			'not_supported',
			__( 'Invite cancellation is not available.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$removed = ec_remove_pending_invitation( $artist_id, $invite_id );

	if ( ! $removed ) {
		return new WP_Error(
			'cancel_failed',
			__( 'Could not cancel the invitation.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array(
		'cancelled' => true,
		'invite_id' => $invite_id,
		'artist_id' => (int) $artist_id,
	) );
}
