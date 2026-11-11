<?php
/** Artist roster invitation compatibility filter and response formatting. */
defined( 'ABSPATH' ) || exit;

/**
 * Whether roster managers may see the address bound to an invitation.
 *
 * Network invitations are always bound to a user account and do not record
 * whether the inviter typed the address or picked the member from user
 * search. The address is only shown when the account was created by this
 * invitation and is still unclaimed: then the inviter supplied the address
 * themselves. Invitations of any other existing account show the member's
 * display name instead, so picking a member never reveals their email.
 *
 * @param array $invite Public network invitation record.
 * @return bool
 */
function ec_artist_roster_invitation_shows_email( array $invite ): bool {
	$user_id   = (int) ( $invite['user_id'] ?? 0 );
	$public_id = (string) ( $invite['public_id'] ?? '' );
	if ( ! $user_id || '' === $public_id ) {
		return false;
	}
	// Provenance meta written by TeamOnboardingService for accounts it creates.
	return '1' === (string) get_user_meta( $user_id, 'ec_unclaimed', true )
		&& hash_equals( $public_id, (string) get_user_meta( $user_id, 'ec_team_invitation_account', true ) );
}

/**
 * Preserve the roster API's invitation response keys without exposing bearer tokens.
 *
 * @param array  $invite      Public network invitation record.
 * @param string $typed_email Optional. Address the inviter typed for this request;
 *                            echoed back because the inviter already knows it.
 * @return array
 */
function ec_artist_roster_invitation_response( array $invite, string $typed_email = '' ): array {
	$user  = get_userdata( $invite['user_id'] );
	$time  = strtotime( $invite['created_at'] . ' UTC' );
	$email = '';
	if ( '' !== $typed_email ) {
		$email = $typed_email;
	} elseif ( $user && ec_artist_roster_invitation_shows_email( $invite ) ) {
		$email = $user->user_email;
	}
	return array(
		'id'                   => (int) $invite['id'],
		'email'                => $email,
		'display_name'         => '' !== $email ? $email : ( $user ? (string) $user->display_name : '' ),
		'status'               => $invite['status'],
		'invited_on'           => $time,
		'invited_on_formatted' => date_i18n( get_option( 'date_format' ), $time ),
		'existing_user'        => $user && empty( $invite['account_created'] ) ? array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'username'     => $user->user_login,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 60 ) ),
			'profile_url'  => '',
		) : null,
	);
}

/**
 * Invite through the network lifecycle for callers of the historical filter.
 *
 * @param mixed  $result    Previous filter result (null if no handler has run).
 * @param mixed  $artist_id The artist profile post ID.
 * @param mixed  $email     The invitee's sanitized email address.
 * @param mixed  $context   Optional. `user_id` when the invitee was picked from
 *                          user search; their address is then withheld from
 *                          the response.
 * @return array|WP_Error Invitation data on success, WP_Error on failure.
 */
function extrachill_handle_invite_member( $result, $artist_id, $email, $context = array() ) {
	if ( is_wp_error( $result ) || ( is_array( $result ) && isset( $result['id'] ) ) ) {
		return $result;
	}
	$adapter = \ExtraChill\Users\Teams\TeamOnboardingAdapters::get( 'artist' );
	if ( ! $adapter ) {
		return new WP_Error( 'not_supported', __( 'Artist invitations are unavailable.', 'extrachill-artist-platform' ), array( 'status' => 503 ) );
	}
	$picked_user_id = is_array( $context ) && ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
	$service        = new \ExtraChill\Users\Teams\TeamOnboardingService( $adapter );
	$invite         = $service->invite( get_current_user_id(), (int) $artist_id, (string) $email, false );
	if ( is_wp_error( $invite ) ) {
		return $invite;
	}
	return ec_artist_roster_invitation_response( $invite, $picked_user_id ? '' : strtolower( sanitize_email( (string) $email ) ) );
}
add_filter( 'extrachill_artist_invite_member', 'extrachill_handle_invite_member', 10, 4 );

/**
 * Cancel a pending artist roster invitation by its numeric network id.
 *
 * @param int    $artist_id Artist profile post ID.
 * @param string $invite_id Invitation id from the roster API path.
 * @return array|bool|WP_Error Cancelled invitation, false when no pending invite matches, or WP_Error.
 */
function ec_cancel_artist_roster_invitation( int $artist_id, string $invite_id ) {
	$adapter = \ExtraChill\Users\Teams\TeamOnboardingAdapters::get( 'artist' );
	if ( ! $adapter ) {
		return new WP_Error( 'not_supported', __( 'Artist invitations are unavailable.', 'extrachill-artist-platform' ), array( 'status' => 503 ) );
	}
	$service = new \ExtraChill\Users\Teams\TeamOnboardingService( $adapter );
	$invites = $service->list_invitations( get_current_user_id(), $artist_id );
	if ( is_wp_error( $invites ) ) {
		return $invites;
	}
	foreach ( $invites as $invite ) {
		if ( (string) $invite['id'] === $invite_id && 'pending' === $invite['status'] ) {
			return $service->cancel_invitation( get_current_user_id(), (int) $invite['id'], (int) $invite['version'] );
		}
	}
	return false;
}
