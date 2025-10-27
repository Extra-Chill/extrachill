<?php
/**
 * Template Router
 *
 * Routes templates via template_include filter with plugin override via extrachill_template_* filters.
 * Plugins use direct blog ID numbers for conditional overrides.
 *
 * @package ExtraChill
 * @since 69.58
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'template_include', 'extrachill_route_templates' );

/**
 * Routes templates via WordPress conditional tags with extrachill_template_* filters for plugin override.
 * bbPress and WooCommerce templates bypass routing for native template systems.
 */
function extrachill_route_templates( $template ) {

	if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
		return $template;
	}

	// WooCommerce bypass - allow WooCommerce to handle its own templates
	if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) ) {
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
		$page_template = get_page_template_slug();

		if ( $page_template && locate_template( $page_template ) ) {
			return $template;
		}

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
