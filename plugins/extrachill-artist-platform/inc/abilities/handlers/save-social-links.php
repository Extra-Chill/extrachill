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
 * @param array $input { artist_id: int, social_links: array }.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_save_social_links( $input ) {
	$artist_id    = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	$social_links = isset( $input['social_links'] ) ? $input['social_links'] : null;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	if ( ! is_array( $social_links ) ) {
		return new WP_Error( 'invalid_social_links', 'social_links must be an array.' );
	}

	if ( ! function_exists( 'extrachill_artist_platform_social_links' ) ) {
		return new WP_Error( 'dependency_missing', 'Social links manager not available.' );
	}

	return ec_artist_with_link_page_lock(
		$artist_id,
		static function ( $link_page_id ) use ( $artist_id, $social_links ) {
			$social_manager    = extrachill_artist_platform_social_links();
			$previous_socials  = $social_manager->get( $artist_id );
			$previous_socials  = is_array( $previous_socials ) ? $previous_socials : array();
			$sanitized_socials = extrachill_artist_platform_sanitize_socials( $social_links, $link_page_id );
			$result            = $social_manager->save( $artist_id, $sanitized_socials );
			if ( is_wp_error( $result ) || false === $result ) {
				$social_manager->save( $artist_id, $previous_socials );
				return is_wp_error( $result ) ? $result : new WP_Error( 'social_save_failed', 'Social links could not be persisted.' );
			}
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
			do_action( 'ec_link_page_save', $link_page_id );
			return array( 'social_links' => $enriched );
		},
		true
	);
}
