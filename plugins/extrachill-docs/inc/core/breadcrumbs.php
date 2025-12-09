<?php
/**
 * Docs Breadcrumb Integration
 *
 * Integrates with theme's breadcrumb system to provide docs-specific
 * breadcrumbs with "Extra Chill → Documentation" root link.
 *
 * @package ExtraChillDocs
 * @since 0.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change breadcrumb root to "Extra Chill → Documentation" on docs pages
 *
 * Uses theme's extrachill_breadcrumbs_root filter to override the root link.
 * Only applies on docs.extrachill.com (blog ID 10).
 *
 * @param string $root_link Default root breadcrumb link HTML.
 * @return string Modified root link.
 * @since 0.2.1
 */
function extrachill_docs_breadcrumb_root( $root_link ) {
	$docs_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'docs' ) : null;
	if ( ! $docs_blog_id || get_current_blog_id() !== $docs_blog_id ) {
		return $root_link;
	}

	// On homepage, just "Extra Chill" (trail will add "Documentation").
	if ( is_front_page() ) {
		$main_site_url = ec_get_site_url( 'main' );
		return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a>';
	}

	// On other pages, include "Documentation" in root.
	$main_site_url = ec_get_site_url( 'main' );
	return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Documentation</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'extrachill_docs_breadcrumb_root' );

/**
 * Override breadcrumb trail for docs homepage
 *
 * Displays "Documentation" with network dropdown on the homepage.
 *
 * @param string $custom_trail Existing custom trail from other plugins.
 * @return string Breadcrumb trail HTML.
 * @since 0.2.1
 */
function extrachill_docs_breadcrumb_trail_homepage( $custom_trail ) {
	$docs_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'docs' ) : null;
	if ( ! $docs_blog_id || get_current_blog_id() !== $docs_blog_id ) {
		return $custom_trail;
	}

	// Only on front page (homepage).
	if ( is_front_page() ) {
		return '<span class="network-dropdown-target">Documentation</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_docs_breadcrumb_trail_homepage', 5 );

/**
 * Override breadcrumb trail for single ec_doc posts
 *
 * Displays "Platform Name › Doc Title" for individual documentation articles.
 *
 * @param string $custom_trail Existing custom trail from other plugins.
 * @return string Breadcrumb trail HTML.
 * @since 0.2.5
 */
function extrachill_docs_breadcrumb_trail_single( $custom_trail ) {
	if ( ! empty( $custom_trail ) ) {
		return $custom_trail;
	}

	$docs_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'docs' ) : null;
	if ( ! $docs_blog_id || get_current_blog_id() !== $docs_blog_id ) {
		return $custom_trail;
	}

	if ( ! is_singular( 'ec_doc' ) ) {
		return $custom_trail;
	}

	$post  = get_queried_object();
	$terms = get_the_terms( $post->ID, 'ec_doc_platform' );

	if ( $terms && ! is_wp_error( $terms ) ) {
		$platform      = reset( $terms );
		$platform_link = get_term_link( $platform );
		$trail         = '<a href="' . esc_url( $platform_link ) . '">' . esc_html( $platform->name ) . '</a>';
		$trail        .= ' › <span class="breadcrumb-title">' . esc_html( get_the_title() ) . '</span>';
		return $trail;
	}

	return '<span class="breadcrumb-title">' . esc_html( get_the_title() ) . '</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_docs_breadcrumb_trail_single', 10 );

/**
 * Override breadcrumb trail for ec_doc_platform taxonomy archives
 *
 * @param string $custom_trail Existing custom trail from other plugins.
 * @return string Breadcrumb trail HTML.
 * @since 0.2.5
 */
function extrachill_docs_breadcrumb_trail_platform( $custom_trail ) {
	if ( ! empty( $custom_trail ) ) {
		return $custom_trail;
	}

	$docs_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'docs' ) : null;
	if ( ! $docs_blog_id || get_current_blog_id() !== $docs_blog_id ) {
		return $custom_trail;
	}

	if ( ! is_tax( 'ec_doc_platform' ) ) {
		return $custom_trail;
	}

	$term = get_queried_object();
	return '<span>' . esc_html( $term->name ) . '</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_docs_breadcrumb_trail_platform', 10 );
