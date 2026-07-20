<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-delete-social
 *
 * Removes a single social link from an artist profile.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delete a single social link by social_id.
 *
 * @param array $input {
 *     @type int    $id        Artist profile post ID.
 *     @type string $social_id Social link ID to delete.
 * }
 * @return array|WP_Error Updated social links.
 */
function extrachill_artist_platform_ability_artist_delete_social( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$social_id = isset( $input['social_id'] ) ? sanitize_text_field( $input['social_id'] ) : '';

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist', 'Artist not found.' );
	}

	if ( empty( $social_id ) ) {
		return new WP_Error( 'missing_social_id', 'social_id is required.' );
	}

	if ( ! function_exists( 'extrachill_artist_platform_social_links' ) ) {
		return new WP_Error( 'dependency_missing', 'Social links manager not available.' );
	}

	$social_manager = extrachill_artist_platform_social_links();
	$existing       = $social_manager->get( $artist_id );

	if ( ! is_array( $existing ) ) {
		return new WP_Error( 'not_found', 'Social link not found.' );
	}

	$filtered = array();
	$found    = false;
	foreach ( $existing as $social_link ) {
		if ( is_array( $social_link ) && isset( $social_link['id'] ) && $social_link['id'] === $social_id ) {
			$found = true;
			continue; // Skip this one (delete it).
		}
		$filtered[] = $social_link;
	}

	if ( ! $found ) {
		return new WP_Error( 'not_found', 'Social link not found.' );
	}

	$result = $social_manager->save( $artist_id, $filtered );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Build enriched response.
	$saved_socials = $social_manager->get( $artist_id );
	$enriched      = array();

	if ( is_array( $saved_socials ) ) {
		foreach ( $saved_socials as $s ) {
			if ( ! is_array( $s ) || empty( $s['type'] ) || empty( $s['id'] ) ) {
				continue;
			}
			$s['icon_class'] = $social_manager->get_icon_class( $s['type'], $s );
			$enriched[]      = $s;
		}
	}

	return array(
		'deleted'      => true,
		'social_id'    => $social_id,
		'social_links' => $enriched,
	);
}
