<?php
/**
 * Studio Breadcrumb Integration
 *
 * Integrates with theme's breadcrumb system to provide studio-specific
 * breadcrumbs with "Extra Chill → Studio" root link.
 *
 * @package ExtraChillStudio
 * @since 0.1.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change breadcrumb root to "Extra Chill → Studio" on studio pages.
 *
 * Uses theme's extrachill_breadcrumbs_root filter to override the root link.
 * Only applies on studio.extrachill.com (blog ID 12).
 *
 * @param string $root_link Default root breadcrumb link HTML.
 * @return string Modified root link.
 * @since 0.1.4
 */
function extrachill_studio_breadcrumb_root( $root_link ) {
	$studio_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'studio' ) : null;
	if ( ! $studio_blog_id || get_current_blog_id() !== $studio_blog_id ) {
		return $root_link;
	}

	$main_site_url = ec_get_site_url( 'main' );

	// On homepage, just "Extra Chill" (trail will add "Studio").
	if ( is_front_page() ) {
		return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a>';
	}

	// On other pages, include "Studio" in root.
	return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Studio</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'extrachill_studio_breadcrumb_root' );

/**
 * Override breadcrumb trail for studio homepage.
 *
 * Displays "Studio" with network dropdown on the homepage.
 *
 * @param string $custom_trail Existing custom trail from other plugins.
 * @return string Breadcrumb trail HTML.
 * @since 0.1.4
 */
function extrachill_studio_breadcrumb_trail_homepage( $custom_trail ) {
	$studio_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'studio' ) : null;
	if ( ! $studio_blog_id || get_current_blog_id() !== $studio_blog_id ) {
		return $custom_trail;
	}

	// Only on front page (homepage).
	if ( is_front_page() ) {
		return '<span class="network-dropdown-target">Studio</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_studio_breadcrumb_trail_homepage', 5 );

/**
 * Override schema breadcrumb items for studio site.
 *
 * Aligns schema breadcrumbs with visual breadcrumbs for studio.extrachill.com.
 * Only applies on blog ID 12 (studio.extrachill.com).
 *
 * Output patterns:
 * - Homepage: [Extra Chill, Studio]
 *
 * @hook extrachill_seo_breadcrumb_items
 * @param array $items Default breadcrumb items from SEO plugin.
 * @return array Modified breadcrumb items for studio context.
 * @since 0.1.4
 */
function extrachill_studio_schema_breadcrumb_items( $items ) {
	$studio_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'studio' ) : null;
	if ( ! $studio_blog_id || get_current_blog_id() !== $studio_blog_id ) {
		return $items;
	}

	$main_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : 'https://extrachill.com';

	// Homepage: Extra Chill → Studio.
	if ( is_front_page() ) {
		return array(
			array(
				'name' => 'Extra Chill',
				'url'  => $main_site_url,
			),
			array(
				'name' => 'Studio',
				'url'  => '',
			),
		);
	}

	return $items;
}
add_filter( 'extrachill_seo_breadcrumb_items', 'extrachill_studio_schema_breadcrumb_items' );
