<?php
/**
 * Theme Integration Filters
 * 
 * Modifies theme behavior for documentation posts (ec_doc post type).
 * Hides author meta and related posts sections.
 *
 * @package ExtraChillDocs
 * @since 0.2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hide author meta for documentation posts.
add_filter(
	'extrachill_post_meta',
	function( $meta_html, $post_id, $post_type ) {
		if ( $post_type === 'ec_doc' ) {
			return ''; // Return empty string to hide all meta.
		}
		return $meta_html;
	},
	10,
	3
);

// Hide related posts for documentation posts.
add_filter(
	'extrachill_related_posts_taxonomies',
	function( $taxonomies, $post_id, $post_type ) {
		if ( $post_type === 'ec_doc' ) {
			return []; // Return empty array to disable related posts.
		}
		return $taxonomies;
	},
	10,
	3
);
