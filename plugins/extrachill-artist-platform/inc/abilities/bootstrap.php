<?php
/**
 * Ability hooks bootstrap.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_artist_platform_register_category' );
add_action( 'wp_abilities_api_init', 'extrachill_artist_platform_register_abilities' );
