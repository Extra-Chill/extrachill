<?php
/** Artist roster invitation compatibility filter and response formatting. */
defined( 'ABSPATH' ) || exit;

/** Preserve the roster API's invitation response keys without exposing bearer tokens. */
function ec_artist_roster_invitation_response( array $invite ): array {
	$user = get_userdata( $invite['user_id'] );
	$time = strtotime( $invite['created_at'] . ' UTC' );
	return array(
		'id'                   => (int) $invite['id'],
		'email'                => $user ? $user->user_email : '',
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

/** Invite through the network lifecycle for callers of the historical filter. */
function extrachill_handle_invite_member( $result, $artist_id, $email ) {
	if ( is_wp_error( $result ) || ( is_array( $result ) && isset( $result['id'] ) ) ) {
		return $result;
	}
	$adapter = \ExtraChill\Users\Teams\TeamOnboardingAdapters::get( 'artist' );
	if ( ! $adapter ) {
		return new WP_Error( 'not_supported', 'Artist invitations are unavailable.', array( 'status' => 503 ) );
	}
	$service = new \ExtraChill\Users\Teams\TeamOnboardingService( $adapter );
	$invite  = $service->invite( get_current_user_id(), (int) $artist_id, $email, false );
	return is_wp_error( $invite ) ? $invite : ec_artist_roster_invitation_response( $invite );
}
add_filter( 'extrachill_artist_invite_member', 'extrachill_handle_invite_member', 10, 3 );
