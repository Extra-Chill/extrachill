<?php
/**
 * REST routes for artist roster management
 *
 * GET    /artists/{id}/roster                      - List roster members and pending invites
 * POST   /artists/{id}/roster                      - Invite member by email or user ID
 * DELETE /artists/{id}/roster/{user_id}            - Remove roster member
 * DELETE /artists/{id}/roster/invites/{invite_id}  - Cancel pending invite
 *
 * Delegates to abilities:
 * - extrachill/artist-get-roster (GET list)
 *
 * Handlers without matching abilities (server-side gap):
 * - POST   invite member
 * - DELETE remove member
 * - DELETE cancel invite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_roster_routes' );

function extrachill_api_register_artist_roster_routes() {
	// Roster endpoints under the artist resource
	register_rest_route(
		'extrachill/v1',
		'/artists/(?P<id>\d+)/roster',
		array(
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
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/artists/(?P<id>\d+)/roster',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_artist_roster_invite_handler',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'id'      => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'email'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				// Invite a member picked from user search without the client
				// ever handling their email address. Resolved server-side.
				'user_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/artists/(?P<id>\d+)/roster/(?P<user_id>\d+)',
		array(
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
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/artists/(?P<id>\d+)/roster/invites/(?P<invite_id>[A-Za-z0-9_-]+)',
		array(
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
		)
	);
}

/**
 * Handles artist roster invitation requests
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_roster_invite_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$email     = (string) $request->get_param( 'email' );
	$user_id   = absint( $request->get_param( 'user_id' ) );

	if ( ! $user_id && ! is_email( $email ) ) {
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

	$invite_context = array();
	if ( $user_id ) {
		// Picked from user search: resolve the address server-side so the
		// inviter never receives it. Handlers must not echo it back.
		$invitee = get_userdata( $user_id );
		if ( ! $invitee || ! is_email( $invitee->user_email ) ) {
			return new WP_Error(
				'invalid_user',
				__( 'Invalid user specified.', 'extrachill-api' ),
				array( 'status' => 400 )
			);
		}
		$email          = $invitee->user_email;
		$invite_context = array( 'user_id' => (int) $invitee->ID );
	}

	$result = apply_filters( 'extrachill_artist_invite_member', null, $artist_id, $email, $invite_context );

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

	return rest_ensure_response(
		array(
			'message'    => __( 'Invitation successfully sent.', 'extrachill-api' ),
			'invitation' => $result,
		)
	);
}

/**
 * Lists linked members and pending invites for an artist via ability.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_roster_list_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/artist-get-roster' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'id' => $request->get_param( 'id' ) ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
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

	return rest_ensure_response(
		array(
			'removed'   => true,
			'user_id'   => (int) $user_id,
			'artist_id' => (int) $artist_id,
		)
	);
}

/**
 * Cancels a pending invitation for an artist.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_roster_cancel_invite_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$invite_id = $request->get_param( 'invite_id' );

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

	if ( ! function_exists( 'ec_cancel_artist_roster_invitation' ) ) {
		return new WP_Error(
			'not_supported',
			__( 'Invite cancellation is not available.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$removed = ec_cancel_artist_roster_invitation( (int) $artist_id, (string) $invite_id );
	if ( is_wp_error( $removed ) ) {
		return $removed;
	}

	if ( ! $removed ) {
		return new WP_Error(
			'cancel_failed',
			__( 'Could not cancel the invitation.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response(
		array(
			'cancelled' => true,
			'invite_id' => $invite_id,
			'artist_id' => (int) $artist_id,
		)
	);
}
