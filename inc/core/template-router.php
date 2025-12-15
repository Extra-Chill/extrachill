<?php
/**
 * Template Router
 *
 * WordPress native routing via template_include filter with plugin extensibility.
 * Homepage uses extrachill_homepage_content action for plugin content injection.
 * Other templates use extrachill_template_* filters for complete template override.
 * Bypasses bbPress/WooCommerce for native template systems, respects custom page templates.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'template_include', 'extrachill_route_templates' );

/**
 * Route templates with plugin override filters
 *
 * @param string $template Default template path from WordPress
 * @return string Template path to use
 */
function extrachill_route_templates( $template ) {

	if ( function_exists( 'is_embed' ) && is_embed() ) {
		return $template;
	}

	if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
		return $template;
	}

	if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) ) {
		return $template;
	}

	if ( is_front_page() || is_home() ) {
		$template = get_template_directory() . '/inc/home/templates/front-page.php';

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
