<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-update-links
 *
 * Updates link page data (links, styles, settings, socials) for an artist.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Update link page data for an artist. Supports partial updates.
 *
 * @param array $input {
 *     @type int    $id       Artist profile post ID.
 *     @type array  $links    Link sections with nested links.
 *     @type array  $css_vars CSS variables.
 *     @type array  $settings Advanced settings.
 *     @type array  $socials  Social link objects.
 *     @type int    $background_image_id Background image attachment ID.
 *     @type int    $profile_image_id    Profile image attachment ID.
 *     @type string $bio      Short bio for the link page.
 * }
 * @return array|WP_Error Fresh link page data on success.
 */
function extrachill_artist_platform_ability_artist_update_links( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist', 'Artist not found.' );
	}

	// Save links.
	if ( isset( $input['links'] ) ) {
		if ( ! is_array( $input['links'] ) ) {
			return new WP_Error( 'invalid_format', 'links must be an array.' );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/save-link-page-links' ) : null;
		if ( $ability ) {
			$result = $ability->execute( array(
				'artist_id' => $artist_id,
				'links'     => $input['links'],
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	// Save CSS vars.
	if ( isset( $input['css_vars'] ) ) {
		if ( ! is_array( $input['css_vars'] ) ) {
			return new WP_Error( 'invalid_format', 'css_vars must be an object.' );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/save-link-page-styles' ) : null;
		if ( $ability ) {
			$result = $ability->execute( array(
				'artist_id' => $artist_id,
				'css_vars'  => $input['css_vars'],
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	// Save settings.
	$has_settings = isset( $input['settings'] ) || isset( $input['background_image_id'] ) || isset( $input['profile_image_id'] ) || isset( $input['bio'] );
	if ( $has_settings ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/save-link-page-settings' ) : null;
		if ( $ability ) {
			$settings_input = array( 'artist_id' => $artist_id );
			if ( isset( $input['settings'] ) ) {
				$settings_input['settings'] = $input['settings'];
			}
			if ( isset( $input['background_image_id'] ) ) {
				$settings_input['background_image_id'] = (int) $input['background_image_id'];
			}
			if ( isset( $input['profile_image_id'] ) ) {
				$settings_input['profile_image_id'] = (int) $input['profile_image_id'];
			}
			if ( isset( $input['bio'] ) ) {
				$settings_input['bio'] = $input['bio'];
			}

			$result = $ability->execute( $settings_input );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	// Save socials.
	if ( isset( $input['socials'] ) ) {
		if ( ! is_array( $input['socials'] ) ) {
			return new WP_Error( 'invalid_format', 'socials must be an array.' );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/save-social-links' ) : null;
		if ( $ability ) {
			$result = $ability->execute( array(
				'artist_id'    => $artist_id,
				'social_links' => $input['socials'],
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	// Return fresh data.
	if ( ! function_exists( 'ec_get_link_page_data' ) ) {
		return new WP_Error( 'dependency_missing', 'Link page data function not available.' );
	}

	$fresh_data = ec_get_link_page_data( $artist_id );

	if ( empty( $fresh_data ) || empty( $fresh_data['link_page_id'] ) ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	return $fresh_data;
}
