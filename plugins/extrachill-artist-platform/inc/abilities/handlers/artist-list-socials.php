<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-list-socials
 *
 * Lists social links for an artist profile.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * List social links for an artist.
 *
 * @param array $input { @type int $id Artist profile post ID. }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_list_socials( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist', 'Artist not found.' );
	}

	$social_links = array();

	if ( function_exists( 'extrachill_artist_platform_social_links' ) ) {
		$social_manager = extrachill_artist_platform_social_links();
		$social_links   = $social_manager->get( $artist_id );

		if ( is_array( $social_links ) ) {
			foreach ( $social_links as $index => $social_link ) {
				if ( ! is_array( $social_link ) || empty( $social_link['type'] ) || empty( $social_link['id'] ) ) {
					continue;
				}
				$social_links[ $index ]['icon_class'] = $social_manager->get_icon_class( $social_link['type'], $social_link );
			}
		}
	}

	return array(
		'social_links' => is_array( $social_links ) ? $social_links : array(),
	);
}
