<?php
/**
 * Wire Breadcrumbs
 *
 * Handles breadcrumb overrides for wire.extrachill.com.
 *
 * @package ExtraChillNewsWire
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customize breadcrumb root for wire site
 *
 * Produces "Extra Chill" root on homepage, "Extra Chill → News Wire" on other pages.
 * Only applies on blog ID 11 (wire.extrachill.com).
 *
 * @hook extrachill_breadcrumbs_root
 * @param string $root_link Default root breadcrumb link HTML from theme
 * @return string Modified root link with wire context
 * @since 0.1.0
 */
function ec_wire_breadcrumb_root( $root_link ) {
	if ( get_current_blog_id() !== EC_BLOG_ID_WIRE ) {
		return $root_link;
	}

	if ( is_front_page() ) {
		$main_site_url = ec_get_site_url( 'main' );
		return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a>';
	}

	$main_site_url = ec_get_site_url( 'main' );
	return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">News Wire</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'ec_wire_breadcrumb_root' );

/**
 * Override breadcrumb trail for homepage
 *
 * Produces "News Wire" trail on homepage. Root function provides "Extra Chill" link.
 * Only applies on blog ID 11 (wire.extrachill.com) homepage.
 *
 * @hook extrachill_breadcrumbs_override_trail
 * @param string|false $custom_trail Existing custom trail from other filters
 * @return string|false Custom trail for homepage, unchanged otherwise
 * @since 0.1.0
 */
function ec_wire_breadcrumb_trail_homepage( $custom_trail ) {
	if ( get_current_blog_id() !== EC_BLOG_ID_WIRE || ! is_front_page() ) {
		return $custom_trail;
	}

	return '<span class="network-dropdown-target">News Wire</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_wire_breadcrumb_trail_homepage' );

/**
 * Override breadcrumb trail for archive pages
 *
 * Produces taxonomy-specific or post type archive trails. Root function provides
 * "Extra Chill → News Wire" prefix. Only applies on blog ID 11 (wire.extrachill.com).
 *
 * Output patterns:
 * - Taxonomy: "Extra Chill → News Wire → [Term Name]"
 * - Post type: "Extra Chill → News Wire"
 *
 * @hook extrachill_breadcrumbs_override_trail
 * @param string|false $custom_trail Custom breadcrumb trail from other filters
 * @return string|false Custom trail for archives, unchanged otherwise
 * @since 0.1.0
 */
function ec_wire_breadcrumb_trail_archives( $custom_trail ) {
	if ( get_current_blog_id() !== EC_BLOG_ID_WIRE ) {
		return $custom_trail;
	}

	if ( is_tax() ) {
		$term = get_queried_object();
		if ( $term && isset( $term->name ) ) {
			return '<span>' . esc_html( $term->name ) . '</span>';
		}
	}

	if ( is_post_type_archive( 'festival_wire' ) ) {
		return '<span>News Wire</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_wire_breadcrumb_trail_archives' );

/**
 * Override breadcrumb trail for single festival wire posts
 *
 * Produces post title trail. Root function provides "Extra Chill → News Wire" prefix.
 * Only applies on blog ID 11 (wire.extrachill.com).
 *
 * Output pattern: "Extra Chill → News Wire → [Post Title]"
 *
 * @hook extrachill_breadcrumbs_override_trail
 * @param string|false $custom_trail Custom breadcrumb trail from other filters
 * @return string|false Custom trail for single posts, unchanged otherwise
 * @since 0.1.0
 */
function ec_wire_breadcrumb_trail_single( $custom_trail ) {
	if ( get_current_blog_id() !== EC_BLOG_ID_WIRE || ! is_singular( 'festival_wire' ) ) {
		return $custom_trail;
	}

	return '<span class="breadcrumb-title">' . get_the_title() . '</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_wire_breadcrumb_trail_single' );

/**
 * Override back-to-home link label for wire pages
 *
 * Produces "Back to News Wire" on non-homepage pages. Homepage retains default
 * "Back to Extra Chill" label pointing to main site.
 * Only applies on blog ID 11 (wire.extrachill.com).
 *
 * @hook extrachill_back_to_home_label
 * @param string $label Default back-to-home link label from theme
 * @param string $url Back-to-home link URL
 * @return string Modified label for wire pages, unchanged for homepage
 * @since 0.1.0
 */
function ec_wire_back_to_home_label( $label, $url ) {
	if ( get_current_blog_id() !== EC_BLOG_ID_WIRE || is_front_page() ) {
		return $label;
	}

	return '← Back to News Wire';
}
add_filter( 'extrachill_back_to_home_label', 'ec_wire_back_to_home_label', 10, 2 );