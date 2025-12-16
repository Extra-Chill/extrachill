<?php
/**
 * Blog Archive Breadcrumb Integration
 *
 * Provides breadcrumb trail for the main blog archive (/blog) on extrachill.com.
 * Enables network dropdown for cross-site navigation consistency.
 *
 * @package ExtraChillBlog
 * @since 0.2.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Override breadcrumb trail for blog archive
 *
 * Displays "Blog" on the blog archive page to enable network dropdown.
 * Only applies on Blog ID 1 (extrachill.com) blog archive.
 *
 * @param string $custom_trail Existing custom trail from other plugins.
 *
 * @return string Breadcrumb trail HTML
 */
function extrachill_blog_breadcrumb_trail( $custom_trail ) {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id || get_current_blog_id() !== $main_blog_id ) {
		return $custom_trail;
	}

	if ( get_query_var( 'extrachill_blog_archive' ) ) {
		return '<span class="network-dropdown-target">Blog</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_blog_breadcrumb_trail', 5 );
