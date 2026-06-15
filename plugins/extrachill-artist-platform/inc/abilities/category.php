<?php
/**
 * Ability category registration.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register artist platform ability categories.
 */
function extrachill_artist_platform_register_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_has_ability_category' ) ) {
		return;
	}

	if ( ! wp_has_ability_category( 'extrachill-artist-platform' ) ) {
		wp_register_ability_category(
			'extrachill-artist-platform',
			array(
				'label'       => __( 'Artist Platform', 'extrachill-artist-platform' ),
				'description' => __( 'Artist profile and link page management for Extra Chill.', 'extrachill-artist-platform' ),
			)
		);
	}

	if ( ! wp_has_ability_category( 'extrachill-artists' ) ) {
		wp_register_ability_category(
			'extrachill-artists',
			array(
				'label'       => __( 'Artists', 'extrachill-artist-platform' ),
				'description' => __( 'Artist-domain abilities: profiles, links, socials, subscribers, analytics.', 'extrachill-artist-platform' ),
			)
		);
	}

	if ( ! wp_has_ability_category( 'extrachill-admin-artist-relationships' ) ) {
		wp_register_ability_category(
			'extrachill-admin-artist-relationships',
			array(
				'label'       => __( 'Admin: Artist Relationships', 'extrachill-artist-platform' ),
				'description' => __( 'Network-admin abilities for managing artist-user relationships.', 'extrachill-artist-platform' ),
			)
		);
	}
}
