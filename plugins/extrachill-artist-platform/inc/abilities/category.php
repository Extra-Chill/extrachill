<?php
/**
 * Ability category registration.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register artist platform ability category.
 */
function extrachill_artist_platform_register_category() {
	wp_register_ability_category(
		'extrachill-artist-platform',
		array(
			'label'       => __( 'Artist Platform', 'extrachill-artist-platform' ),
			'description' => __( 'Artist profile and link page management for Extra Chill.', 'extrachill-artist-platform' ),
		)
	);
}
