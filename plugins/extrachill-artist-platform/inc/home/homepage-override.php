<?php
/**
 * Artist Platform Homepage Override
 *
 * Overrides the homepage template for artist.extrachill.com (site #4 in multisite network).
 * Uses WordPress native template_include filter via theme's universal routing system.
 *
 * @package ExtraChillArtistPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register homepage template override via theme's universal routing system.
 *
 * The extrachill_template_homepage filter is provided by theme's template-router.php
 * and enables plugins to completely override homepage templates at the routing level.
 *
 * Uses domain-based site detection with get_blog_id_from_url() for maintainable code.
 * WordPress blog-id-cache provides automatic performance optimization.
 */
add_filter( 'extrachill_template_homepage', 'ec_artist_platform_override_homepage' );

/**
 * Override homepage template for artist.extrachill.com
 *
 * @param string $template Default template path from theme
 * @return string Modified template path
 */
function ec_artist_platform_override_homepage( $template ) {
	// Only override on artist.extrachill.com using domain-based detection
	$artist_blog_id = get_blog_id_from_url( 'artist.extrachill.com', '/' );

	if ( $artist_blog_id && get_current_blog_id() === $artist_blog_id ) {
		return EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/home/templates/homepage.php';
	}

	return $template;
}
