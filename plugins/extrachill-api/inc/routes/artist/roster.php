<?php
/**
 * REST route: POST /wp-json/extrachill/v1/artist/roster/invite
 *
 * Authenticated endpoint for inviting members to an artist roster.
 * Validates input and fires filter hook for invitation handling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_roster_routes' );

function extrachill_api_register_artist_roster_routes() {
	register_rest_route( 'extrachill/v1', '/artist/roster/invite', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_artist_roster_invite_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'artist_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'email'     => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
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
	$artist_id = $request->get_param( 'artist_id' );
	$email     = $request->get_param( 'email' );

	// Validate email format
	if ( ! is_email( $email ) ) {
		return new WP_Error(
			'invalid_email',
			__( 'Please enter a valid email address.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	// Validate artist exists and is correct post type
	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			__( 'Invalid artist specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	// Check permission
	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to manage members for this artist.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Fires when a roster invitation request is received.
	 *
	 * Handlers should return WP_Error on failure, or invitation data array on success.
	 *
	 * @param mixed  $result    Previous filter result (null if no handler has run).
	 * @param int    $artist_id The artist profile post ID.
	 * @param string $email     The invitee's email address.
	 */
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
