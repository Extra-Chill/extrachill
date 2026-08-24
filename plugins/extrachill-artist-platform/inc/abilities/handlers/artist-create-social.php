<?php
/**
 * Handler: extrachill/artist-create-social
 *
 * Adds a single social link to an artist profile.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Create (append) a social link for an artist.
 *
 * @param array $input Ability input.
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

	return ec_artist_with_link_page_lock(
		$artist_id,
		static function ( $link_page_id ) use ( $artist_id, $type, $url ) {
			$social_manager = extrachill_artist_platform_social_links();
			$existing       = $social_manager->get( $artist_id );
			$existing       = is_array( $existing ) ? $existing : array();
			$previous       = $existing;
			$existing[]     = array(
				'id'   => '',
				'type' => $type,
				'url'  => $url,
			);
			$sanitized      = extrachill_artist_platform_sanitize_socials( $existing, $link_page_id );
			$result         = $social_manager->save( $artist_id, $sanitized );
			if ( is_wp_error( $result ) || false === $result ) {
				$social_manager->save( $artist_id, $previous );
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
			if ( $link_page_id ) {
				do_action( 'ec_link_page_save', $link_page_id );
			}
			return array( 'social_links' => $enriched );
		}
	);
}
