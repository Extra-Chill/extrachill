<?php
/**
 * BreadcrumbList Schema
 *
 * Outputs BreadcrumbList schema for non-homepage pages.
 * Generates breadcrumb trail based on page context.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add BreadcrumbList schema to graph
 */
add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		// No breadcrumbs on homepage
		if ( is_front_page() ) {
			return $graph;
		}

		$items = ec_seo_get_breadcrumb_items();

		if ( empty( $items ) ) {
			return $graph;
		}

		$item_list = array();
		$position  = 1;

		foreach ( $items as $item ) {
			$list_item = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $item['name'],
			);

			// Add URL for all except last item
			if ( ! empty( $item['url'] ) ) {
				$list_item['item'] = $item['url'];
			}

			$item_list[] = $list_item;
			$position++;
		}

		// Get current page URL for breadcrumb ID
		$current_url = ec_seo_get_current_url();

		$breadcrumb = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $current_url . '#breadcrumb',
			'itemListElement' => $item_list,
		);

		$graph[] = $breadcrumb;

		return $graph;
	}
);

/**
 * Generate breadcrumb items based on current page context
 *
 * @return array Breadcrumb items with name and optional url
 */
function ec_seo_get_breadcrumb_items() {
	$items = array();

	// Always start with Home
	$items[] = array(
		'name' => 'Home',
		'url'  => home_url( '/' ),
	);

	// Singular posts
	if ( is_singular( 'post' ) ) {
		$post = get_queried_object();

		// Add primary category if available
		$categories = get_the_category( $post->ID );
		if ( ! empty( $categories ) ) {
			$primary_cat = $categories[0];
			$items[]     = array(
				'name' => $primary_cat->name,
				'url'  => get_category_link( $primary_cat->term_id ),
			);
		}

		// Current post (no URL for last item)
		$items[] = array(
			'name' => get_the_title( $post->ID ),
			'url'  => '',
		);
	}

	// Singular pages
	if ( is_singular( 'page' ) ) {
		$page = get_queried_object();

		// Add parent pages if hierarchical
		$ancestors = get_post_ancestors( $page->ID );
		if ( ! empty( $ancestors ) ) {
			$ancestors = array_reverse( $ancestors );
			foreach ( $ancestors as $ancestor_id ) {
				$items[] = array(
					'name' => get_the_title( $ancestor_id ),
					'url'  => get_permalink( $ancestor_id ),
				);
			}
		}

		// Current page
		$items[] = array(
			'name' => get_the_title( $page->ID ),
			'url'  => '',
		);
	}

	// Category archive
	if ( is_category() ) {
		$category = get_queried_object();

		// Add parent categories
		$ancestors = get_ancestors( $category->term_id, 'category' );
		if ( ! empty( $ancestors ) ) {
			$ancestors = array_reverse( $ancestors );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_category( $ancestor_id );
				$items[]  = array(
					'name' => $ancestor->name,
					'url'  => get_category_link( $ancestor_id ),
				);
			}
		}

		$items[] = array(
			'name' => $category->name,
			'url'  => '',
		);
	}

	// Tag archive
	if ( is_tag() ) {
		$tag     = get_queried_object();
		$items[] = array(
			'name' => $tag->name,
			'url'  => '',
		);
	}

	// Author archive
	if ( is_author() ) {
		$author  = get_queried_object();
		$items[] = array(
			'name' => $author->display_name,
			'url'  => '',
		);
	}

	// Blog archive (posts page)
	if ( is_home() && ! is_front_page() ) {
		$items[] = array(
			'name' => 'Blog',
			'url'  => '',
		);
	}

	// Search results
	if ( is_search() ) {
		$items[] = array(
			'name' => 'Search Results',
			'url'  => '',
		);
	}

	return $items;
}

/**
 * Get current page URL
 *
 * @return string Current URL
 */
function ec_seo_get_current_url() {
	if ( is_search() ) {
		return home_url( '/?s=' . rawurlencode( get_search_query() ) );
	}

	global $wp;
	return home_url( add_query_arg( array(), $wp->request ) );
}
