<?php
/**
 * Artist Roster Filter Handlers
 * 
 * Handles REST API filter requests for artist member invitation system.
 * Provides invitation creation, email sending, and roster operations.
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/roster-data-functions.php';
require_once dirname( __FILE__ ) . '/artist-invitation-emails.php';

/**
 * Handle artist member invitation via REST API filter
 *
 * Hooked into 'extrachill_artist_invite_member' filter from extrachill-api plugin.
 * Creates invitation, sends email, and returns invitation data with existing user info.
 *
 * @param mixed  $result    Previous filter result (null if no handler has run).
 * @param int    $artist_id The artist profile post ID.
 * @param string $email     The invitee's sanitized email address.
 * @return array|WP_Error Invitation data on success, WP_Error on failure.
 */
function extrachill_handle_invite_member( $result, $artist_id, $email ) {
	// If a previous handler already processed this, pass through
	if ( is_wp_error( $result ) || ( is_array( $result ) && isset( $result['id'] ) ) ) {
		return $result;
	}

	// Check if email is already linked to a member
	$linked_members = bp_get_linked_members( $artist_id );
	if ( is_array( $linked_members ) ) {
		foreach ( $linked_members as $linked_member_obj ) {
			if ( isset( $linked_member_obj->ID ) ) {
				$user_info = get_userdata( $linked_member_obj->ID );
				if ( $user_info && strtolower( $user_info->user_email ) === strtolower( $email ) ) {
					return new WP_Error(
						'already_member',
						__( 'This email address is already linked to a member of this artist.', 'extrachill-artist-platform' ),
						array( 'status' => 409 )
					);
				}
			}
		}
	}

	// Create the invitation
	$new_invitation_result = bp_add_pending_invitation( $artist_id, '', $email );

	if ( is_string( $new_invitation_result ) ) {
		switch ( $new_invitation_result ) {
			case 'error_already_pending':
				return new WP_Error(
					'already_pending',
					__( 'An invitation has already been sent to this email address for this artist.', 'extrachill-artist-platform' ),
					array( 'status' => 409 )
				);
		}
		return new WP_Error(
			'invitation_failed',
			__( 'Could not create a pending invitation.', 'extrachill-artist-platform' ),
			array( 'status' => 500 )
		);
	}

	if ( ! is_array( $new_invitation_result ) || ! isset( $new_invitation_result['id'] ) ) {
		return new WP_Error(
			'invitation_failed',
			__( 'Could not create a pending invitation.', 'extrachill-artist-platform' ),
			array( 'status' => 500 )
		);
	}

	$invite = $new_invitation_result;

	// Send invitation email
	$artist_post = get_post( $artist_id );
	$artist_name = $artist_post ? $artist_post->post_title : 'the artist';

	bp_send_artist_invitation_email(
		$invite['email'],
		$artist_name,
		'',
		$invite['token'],
		$artist_id,
		$invite['status']
	);

	// Build response data
	$invited_on_formatted = date_i18n( get_option( 'date_format' ), $invite['invited_on'] );

	$response = array(
		'id'                   => $invite['id'],
		'email'                => $invite['email'],
		'status'               => $invite['status'],
		'invited_on'           => $invite['invited_on'],
		'invited_on_formatted' => $invited_on_formatted,
		'existing_user'        => null,
	);

	// Check if this is an existing user
	$existing_user_id = email_exists( $invite['email'] );
	if ( $existing_user_id ) {
		$user_info = get_userdata( $existing_user_id );
		if ( $user_info ) {
			$profile_url = '';
			$avatar_url  = get_avatar_url( $user_info->ID, array( 'size' => 60 ) );

			$response['existing_user'] = array(
				'id'           => $user_info->ID,
				'display_name' => $user_info->display_name,
				'username'     => $user_info->user_login,
				'avatar_url'   => $avatar_url,
				'profile_url'  => $profile_url,
			);
		}
	}

	return $response;
}
add_filter( 'extrachill_artist_invite_member', 'extrachill_handle_invite_member', 10, 3 );
