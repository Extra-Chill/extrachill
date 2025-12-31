<?php
/**
 * Theme Integration Filters
 *
 * Modifies theme behavior for documentation posts (ec_doc post type).
 * Hides author meta, enables platform-based related posts, overrides sidebar with TOC.
 *
 * @package ExtraChillDocs
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'extrachill_post_meta_parts',
	function( $parts, $post_id, $post_type ) {
		if ( $post_type === 'ec_doc' ) {
			return array( 'published', 'updated' );
		}
		return $parts;
	},
	10,
	3
);

// Allow ec_doc_platform taxonomy for related posts on ec_doc.
add_filter(
	'extrachill_related_posts_allowed_taxonomies',
	function( $allowed, $post_type ) {
		if ( $post_type === 'ec_doc' ) {
			return [ 'ec_doc_platform' ];
		}
		return $allowed;
	},
	10,
	2
);

// Use ec_doc_platform taxonomy for related posts on ec_doc.
add_filter(
	'extrachill_related_posts_taxonomies',
	function( $taxonomies, $post_id, $post_type ) {
		if ( $post_type === 'ec_doc' ) {
			return [ 'ec_doc_platform' ];
		}
		return $taxonomies;
	},
	10,
	3
);

// Query ec_doc post type for related posts on ec_doc.
add_filter(
	'extrachill_related_posts_query_args',
	function( $args, $taxonomy, $post_id, $post_type ) {
		if ( $post_type === 'ec_doc' ) {
			$args['post_type'] = 'ec_doc';
		}
		return $args;
	},
	10,
	4
);

// Override sidebar content for documentation posts.
add_filter(
	'extrachill_sidebar_content',
	function( $sidebar_content ) {
		if ( is_singular( 'ec_doc' ) ) {
			return extrachill_docs_generate_sidebar( get_the_ID() );
		}
		return $sidebar_content;
	}
);

// Override back-to-home label for documentation site.
add_filter(
	'extrachill_back_to_home_label',
	function( $label, $url ) {
		if ( is_singular( 'ec_doc' ) || is_post_type_archive( 'ec_doc' ) || is_tax( 'ec_doc_platform' ) ) {
			return '← Back to Documentation';
		}
		return $label;
	},
	10,
	2
);
