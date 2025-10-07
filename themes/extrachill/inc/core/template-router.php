<?php
/**
 * Template Router - WordPress Core Integration
 *
 * Uses WordPress native template_include filter to route templates based on page type.
 * Provides plugin override capability via extrachill_template_* filters.
 *
 * @package ExtraChill
 * @since 69.58
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'template_include', 'extrachill_route_templates' );

/**
 * Route templates based on WordPress conditional tags
 *
 * @param string $template Default template path from WordPress
 * @return string Modified template path
 */
function extrachill_route_templates( $template ) {

	// Let bbPress handle its own templates for ALL bbPress page types
	if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
		return $template;
	}

	if ( is_front_page() || is_home() ) {
		$template = apply_filters( 'extrachill_template_homepage',
			get_template_directory() . '/inc/home/templates/front-page.php'
		);

	} elseif ( is_single() ) {
		$template = apply_filters( 'extrachill_template_single_post',
			get_template_directory() . '/inc/single/single-post.php'
		);

	} elseif ( is_page() ) {
		$template = apply_filters( 'extrachill_template_page',
			get_template_directory() . '/inc/single/single-page.php'
		);

	} elseif ( is_archive() || is_category() || is_tag() || is_author() || is_date() ) {
		$template = apply_filters( 'extrachill_template_archive',
			get_template_directory() . '/inc/archives/archive.php'
		);

	} elseif ( is_search() ) {
		$template = apply_filters( 'extrachill_template_search',
			get_template_directory() . '/inc/archives/search/search.php'
		);

	} elseif ( is_404() ) {
		$template = apply_filters( 'extrachill_template_404',
			get_template_directory() . '/inc/core/templates/404.php'
		);

	} else {
		$template = apply_filters( 'extrachill_template_fallback',
			get_template_directory() . '/inc/core/templates/404.php'
		);
	}

	return $template;
}
