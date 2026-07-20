<?php
declare(strict_types=1);
/**
 * Handler: extrachill/admin-unlink-artist-relationship
 *
 * Unlinks a user from an artist profile (admin-only).
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Unlink a user from an artist profile.
 *
 * @param array $input {
 *     @type int $user_id   WordPress user ID.
 *     @type int $artist_id Artist profile post ID.
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_admin_unlink_artist_relationship( array $input ): array|WP_Error {
	if ( ! extrachill_artist_platform_ability_admin_permission() ) {
		return new WP_Error(
			'admin_access_denied',
			__( 'You are not allowed to manage artist relationships.', 'extrachill-artist-platform' ),
			array( 'status' => 403 )
		);
	}

	if ( empty( $input['user_id'] ) || empty( $input['artist_id'] ) ) {
		return new WP_Error(
			'missing_params',
			__( 'Both user_id and artist_id are required.', 'extrachill-artist-platform' ),
			array( 'status' => 400 )
		);
	}

	if ( ! ec_remove_artist_membership( (int) $input['user_id'], (int) $input['artist_id'] ) ) {
		$membership_failure = ec_get_artist_membership_failure();
		return $membership_failure ? $membership_failure : new WP_Error( 'relationship_update_failed', __( 'Artist membership could not be fully removed. Retry to reconcile the relationship.', 'extrachill-artist-platform' ), array( 'status' => 500 ) );
	}

	return array( 'success' => true );
}
