<?php
/**
 * Template Router
 *
 * Routes templates via WordPress native template_include filter with
 * plugin override capability via extrachill_template_* filters.
 *
 * This routing system enables plugins to completely override templates at the routing level
 * without modifying theme files. Each template type supports a dedicated filter for customization.
 *
 * Integration Pattern:
 * - Plugins use extrachill_template_* filters to override specific template types
 * - Domain-based site detection via get_blog_id_from_url() for conditional overrides
 * - WordPress blog-id-cache provides automatic performance optimization
 *
 * Plugin Examples:
 * - extrachill-chat: Overrides homepage on chat.extrachill.com
 * - extrachill-events: Overrides homepage on events.extrachill.com
 * - extrachill-artist-platform: Overrides homepage on artist.extrachill.com
 *
 * @package ExtraChill
 * @since 69.58
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'template_include', 'extrachill_route_templates' );

/**
 * Route templates based on WordPress conditional tags.
 *
 * Provides filter-based template override system for plugin customization.
 * bbPress templates bypass routing to use their native template system.
 *
 * @param string $template Default template path from WordPress.
 * @return string Modified template path.
 */
function extrachill_route_templates( $template ) {

	// Let bbPress handle its own templates for ALL bbPress page types
	if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
		return $template;
	}

	if ( is_front_page() || is_home() ) {
		/**
		 * Filter homepage template path.
		 *
		 * Allows plugins to completely override homepage template for site-specific functionality.
		 *
		 * Current plugin overrides:
		 * - extrachill-chat: Homepage on chat.extrachill.com
		 * - extrachill-events: Homepage on events.extrachill.com
		 * - extrachill-artist-platform: Homepage on artist.extrachill.com
		 *
		 * @param string $template Default homepage template path
		 */
		$template = apply_filters( 'extrachill_template_homepage',
			get_template_directory() . '/inc/home/templates/front-page.php'
		);

	} elseif ( is_single() ) {
		/**
		 * Filter single post template path.
		 *
		 * @param string $template Default single post template path
		 */
		$template = apply_filters( 'extrachill_template_single_post',
			get_template_directory() . '/inc/single/single-post.php'
		);

	} elseif ( is_page() ) {
		// Check if page has a custom template assigned
		$page_template = get_page_template_slug();

		// If custom template exists, let WordPress handle it
		if ( $page_template && locate_template( $page_template ) ) {
			return $template; // Use WordPress's natural template selection
		}

		/**
		 * Filter page template path.
		 *
		 * Only applied when page has no custom template assigned.
		 *
		 * @param string $template Default page template path
		 */
		$template = apply_filters( 'extrachill_template_page',
			get_template_directory() . '/inc/single/single-page.php'
		);

	} elseif ( is_archive() || is_category() || is_tag() || is_author() || is_date() ) {
		/**
		 * Filter archive template path.
		 *
		 * Applies to: category, tag, author, date, and custom taxonomy archives.
		 *
		 * @param string $template Default archive template path
		 */
		$template = apply_filters( 'extrachill_template_archive',
			get_template_directory() . '/inc/archives/archive.php'
		);

	} elseif ( is_search() ) {
		/**
		 * Filter search results template path.
		 *
		 * Uses multisite search template with cross-site results via extrachill-search plugin.
		 *
		 * @param string $template Default search template path
		 */
		$template = apply_filters( 'extrachill_template_search',
			get_template_directory() . '/inc/archives/search/search.php'
		);

	} elseif ( is_404() ) {
		/**
		 * Filter 404 error page template path.
		 *
		 * @param string $template Default 404 template path
		 */
		$template = apply_filters( 'extrachill_template_404',
			get_template_directory() . '/inc/core/templates/404.php'
		);

	} else {
		/**
		 * Filter fallback template path for unknown page types.
		 *
		 * Applied when no other conditional matches. Defaults to 404 template.
		 *
		 * @param string $template Default fallback template path
		 */
		$template = apply_filters( 'extrachill_template_fallback',
			get_template_directory() . '/inc/core/templates/404.php'
		);
	}

	return $template;
}
