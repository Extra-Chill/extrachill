<?php
/**
 * Handler: extrachill/artist-get-roster
 *
 * Lists linked members and pending invites for an artist.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Get roster (members + pending invites) for an artist.
 *
 * @param array $input { @type int $id Artist profile post ID. }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_get_roster( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist', 'Invalid artist specified.' );
	}

	// Build members list.
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
						'profile_url'  => function_exists( 'extrachill_get_user_profile_url' )
							? extrachill_get_user_profile_url( $user_info->ID, $user_info->user_email )
							: '',
					);
				}
			}
		}
	}

	// Build pending invites list.
	$invites = array();
	$adapter = \ExtraChill\Users\Teams\TeamOnboardingAdapters::get( 'artist' );
	$service = new \ExtraChill\Users\Teams\TeamOnboardingService( $adapter );
	$pending = $service->list_invitations( get_current_user_id(), $artist_id );
	if ( is_wp_error( $pending ) ) {
		return $pending;
	}
	foreach ( $pending as $invite ) {
		if ( 'pending' !== $invite['status'] ) {
			continue;
		}
		$invite     = ec_artist_roster_invitation_response( $invite );
		$invited_on = (int) $invite['invited_on'];
		$invites[]  = array(
			'id'                   => $invite['id'],
			// Empty unless the inviter supplied the address (see ec_artist_roster_invitation_shows_email()).
			'email'                => $invite['email'],
			'display_name'         => $invite['display_name'],
			'of_existing_user'     => null !== $invite['existing_user'],
			'status'               => $invite['status'],
			'invited_on'           => $invited_on,
			'invited_on_formatted' => $invite['invited_on_formatted'],
		);
	}

	return array(
		'members' => $members,
		'invites' => $invites,
	);
}
