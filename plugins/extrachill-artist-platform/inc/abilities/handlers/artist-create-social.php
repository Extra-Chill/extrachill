<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-create-social
 *
 * Adds a single social link to an artist profile.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create (append) a social link for an artist.
 *
 * @param array $input {
 *     @type int    $id   Artist profile post ID.
 *     @type string $type Social platform type.
 *     @type string $url  Social link URL.
 * }
 * @return array|WP_Error Updated social links.
 */
function extrachill_artist_platform_ability_artist_create_social( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$type      = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : '';
	$url       = isset( $input['url'] ) ? esc_url_raw( $input['url'] ) : '';

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist', 'Artist not found.' );
	}

	if ( empty( $type ) || empty( $url ) ) {
		return new WP_Error( 'missing_fields', 'type and url are required.' );
	}

	if ( ! function_exists( 'extrachill_artist_platform_social_links' ) ) {
		return new WP_Error( 'dependency_missing', 'Social links manager not available.' );
	}

	$social_manager = extrachill_artist_platform_social_links();
	$existing       = $social_manager->get( $artist_id );

	if ( ! is_array( $existing ) ) {
		$existing = array();
	}

	// Append the new social link (ID will be assigned during sanitize).
	$existing[] = array(
		'id'   => '',
		'type' => $type,
		'url'  => $url,
	);

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
		foreach ( $saved_socials as $social_link ) {
			if ( ! is_array( $social_link ) || empty( $social_link['type'] ) || empty( $social_link['id'] ) ) {
				continue;
			}
			$social_link['icon_class'] = $social_manager->get_icon_class( $social_link['type'], $social_link );
			$enriched[]                = $social_link;
		}
	}

	return array( 'social_links' => $enriched );
}
