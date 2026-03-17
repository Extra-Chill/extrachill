<?php
/**
 * Handler: extrachill/save-social-links
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Save social links for an artist profile. Full replacement.
 *
 * @param array $input { artist_id: int, social_links: array }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_save_social_links( $input ) {
	$artist_id    = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	$social_links = isset( $input['social_links'] ) ? $input['social_links'] : null;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}

	if ( ! is_array( $social_links ) ) {
		return new WP_Error( 'invalid_social_links', 'social_links must be an array.' );
	}

	if ( ! function_exists( 'extrachill_artist_platform_social_links' ) ) {
		return new WP_Error( 'dependency_missing', 'Social links manager not available.' );
	}

	$link_page_id = ec_get_link_page_for_artist( $artist_id );
	if ( ! $link_page_id ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	$sanitized_socials = extrachill_artist_platform_sanitize_socials( $social_links, $link_page_id );

	$social_manager = extrachill_artist_platform_social_links();
	$result         = $social_manager->save( $artist_id, $sanitized_socials );

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
