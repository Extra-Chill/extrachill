<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-get-permissions
 *
 * Checks current user permissions for an artist profile.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check current user permissions for an artist.
 *
 * @param array $input { @type int $id Artist profile post ID. }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_get_permissions( array $input ): array|WP_Error {
	$artist_id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$current_user_id = get_current_user_id();
	$can_edit        = false;
	$manage_url      = '';

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( $artist_id && $current_user_id && function_exists( 'ec_can_manage_artist' ) && ec_can_manage_artist( $current_user_id, $artist_id ) ) {
		$can_edit   = true;
		$manage_url = home_url( '/manage-link-page/' );
	}

	return array(
		'can_edit'   => $can_edit,
		'manage_url' => $manage_url,
		'user_id'    => $current_user_id,
	);
}
