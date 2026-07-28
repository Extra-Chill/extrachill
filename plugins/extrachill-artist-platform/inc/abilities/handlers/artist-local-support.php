<?php
/**
 * Local support availability ability handlers.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Get local support availability for an exactly managed artist. */
function extrachill_artist_platform_ability_get_local_support_availability( $input ) {
	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', __( 'You are not allowed to manage this artist.', 'extrachill-artist-platform' ) );
	}

	return extrachill_artist_platform_get_local_support_availability( absint( $input['id'] ?? 0 ) );
}

/** Update local support availability for an exactly managed artist. */
function extrachill_artist_platform_ability_update_local_support_availability( $input ) {
	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', __( 'You are not allowed to manage this artist.', 'extrachill-artist-platform' ) );
	}

	return extrachill_artist_platform_update_local_support_availability(
		absint( $input['id'] ?? 0 ),
		! empty( $input['available'] ),
		array_key_exists( 'scene_slug', $input ) ? (string) $input['scene_slug'] : null,
		extrachill_artist_platform_ability_acting_user_id()
	);
}

/** Query privacy-safe candidates through the private producer contract. */
function extrachill_artist_platform_ability_query_local_support_candidates( $input ) {
	return extrachill_artist_platform_resolve_local_support_candidates(
		$input['producer'] ?? '',
		$input['scene_slug'] ?? '',
		$input['genre'] ?? '',
		$input['exclude_artist_ids'] ?? array()
	);
}
