<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-update-social
 *
 * Updates a single social link on an artist profile.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Update a single social link by social_id.
 *
 * @param array $input {
 *     @type int    $id        Artist profile post ID.
 *     @type string $social_id Social link ID to update.
 *     @type string $type      Social platform type (optional).
 *     @type string $url       Social link URL (optional).
 * }
 * @return array|WP_Error Updated social links.
 */
function extrachill_artist_platform_ability_artist_update_social( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$social_id = isset( $input['social_id'] ) ? sanitize_text_field( $input['social_id'] ) : '';

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
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

	$found = false;
	foreach ( $existing as &$social_link ) {
		if ( is_array( $social_link ) && isset( $social_link['id'] ) && $social_link['id'] === $social_id ) {
			if ( isset( $input['type'] ) ) {
				$social_link['type'] = sanitize_text_field( $input['type'] );
			}
			if ( isset( $input['url'] ) ) {
				$social_link['url'] = esc_url_raw( $input['url'] );
			}
			$found = true;
			break;
		}
	}
	unset( $social_link );

	if ( ! $found ) {
		return new WP_Error( 'not_found', 'Social link not found.' );
	}

	$link_page_id = function_exists( 'ec_get_link_page_for_artist' ) ? ec_get_link_page_for_artist( $artist_id ) : 0;

	$sanitized = extrachill_artist_platform_sanitize_socials( $existing, $link_page_id ?: 0 );
	$result    = $social_manager->save( $artist_id, $sanitized );

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

	return array( 'social_links' => $enriched );
}
