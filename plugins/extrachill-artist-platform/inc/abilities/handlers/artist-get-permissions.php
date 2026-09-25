<?php
/**
 * Handler: extrachill/artist-get-permissions
 *
 * Checks current user permissions for an artist profile.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Check current user permissions for an artist.
 *
 * @param array $input { @type int $id Artist profile post ID. }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_get_permissions( array $input ): array|WP_Error {
	$artist_id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$current_user_id = extrachill_artist_platform_ability_acting_user_id();
	$can_edit        = false;
	$manage_url      = '';

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( $artist_id && $current_user_id && function_exists( 'ec_user_can' ) && ec_user_can(
		'manage_artist',
		array(
			'artist_id' => $artist_id,
			'user_id'   => $current_user_id,
		)
	) ) {
		$can_edit   = true;
		$manage_url = home_url( '/manage-link-page/' );
		// Prefer the owner-neutral editor on the public Link Page host
		// (extrachill-link-pages#27) when the runtime serves one.
		$link_page_id = function_exists( 'ec_get_link_page_for_artist' ) ? (int) ec_get_link_page_for_artist( $artist_id ) : 0;
		$base         = function_exists( 'ec_link_page_public_base_url' ) && function_exists( 'ec_get_link_page_edit_endpoints' ) ? ec_link_page_public_base_url() : '';
		if ( $link_page_id && '' !== $base && '' !== ( ec_get_link_page_edit_endpoints()['configuration_url'] ?? '' ) ) {
			$manage_url = add_query_arg( 'link_page', $link_page_id, $base . 'edit' );
		}
	}

	return array(
		'can_edit'   => $can_edit,
		'manage_url' => $manage_url,
		'user_id'    => $current_user_id,
	);
}
