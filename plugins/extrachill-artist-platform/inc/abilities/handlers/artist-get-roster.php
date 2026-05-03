<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-get-roster
 *
 * Lists linked members and pending invites for an artist.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

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
	if ( function_exists( 'ec_get_pending_invitations' ) ) {
		$pending = ec_get_pending_invitations( $artist_id );
		if ( is_array( $pending ) ) {
			foreach ( $pending as $invite ) {
				$invited_on = isset( $invite['invited_on'] ) ? (int) $invite['invited_on'] : 0;
				$invites[]  = array(
					'id'                   => $invite['id'] ?? '',
					'email'                => $invite['email'] ?? '',
					'of_existing_user'     => email_exists( $invite['email'] ?? '' ) ? true : false,
					'status'               => $invite['status'] ?? '',
					'invited_on'           => $invited_on,
					'invited_on_formatted' => $invited_on ? date_i18n( get_option( 'date_format' ), $invited_on ) : '',
				);
			}
		}
	}

	return array(
		'members' => $members,
		'invites' => $invites,
	);
}
