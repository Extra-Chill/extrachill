<?php
/**
 * Handler: extrachill/artist-invitation
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate or accept a token-authenticated artist invitation.
 *
 * @param array $input Invitation input.
 * @return array|WP_Error Invitation status or failure.
 */
function extrachill_artist_platform_ability_artist_invitation( $input ) {
	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	$email     = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
	$token     = isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : '';
	$user_id   = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( ! $artist_id || ! is_email( $email ) || ! $token ) {
		return new WP_Error( 'invalid_artist_invitation', __( 'The artist invitation is incomplete.', 'extrachill-artist-platform' ), array( 'status' => 400 ) );
	}

	$matched = null;
	foreach ( ec_get_pending_invitations( $artist_id ) as $invite ) {
		if ( isset( $invite['id'], $invite['token'], $invite['email'], $invite['status'] )
			&& hash_equals( (string) $invite['token'], $token )
			&& strtolower( (string) $invite['email'] ) === strtolower( $email )
			&& in_array( $invite['status'], array( EC_INVITE_STATUS_EXISTING_ARTIST, EC_INVITE_STATUS_NEW_USER ), true ) ) {
			$matched = $invite;
			break;
		}
	}

	if ( ! $matched ) {
		return new WP_Error( 'invalid_artist_invitation', __( 'The artist invitation is invalid or expired.', 'extrachill-artist-platform' ), array( 'status' => 400 ) );
	}

	if ( ! $user_id ) {
		return array(
			'status'    => 'valid',
			'artist_id' => $artist_id,
		);
	}

	$user = get_userdata( $user_id );
	if ( ! $user || strtolower( (string) $user->user_email ) !== strtolower( $email ) ) {
		return new WP_Error( 'artist_invitation_user_mismatch', __( 'The invitation does not belong to this user.', 'extrachill-artist-platform' ), array( 'status' => 403 ) );
	}

	$result = ec_accept_artist_membership_invitation( $user_id, $artist_id, $matched['id'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'status'    => 'applied',
		'artist_id' => $artist_id,
	);
}
