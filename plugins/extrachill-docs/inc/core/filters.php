<?php
/**
 * Theme Integration Filters
 *
 * Modifies theme behavior for documentation pages.
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
	function ( $parts, $post_id, $post_type ) {
		if ( 'page' === $post_type ) {
			return array( 'published', 'updated' );
		}
		return $parts;
	},
	10,
	3
);

// Override sidebar content for documentation posts.
add_filter(
	'extrachill_sidebar_content',
	function ( $sidebar_content ) {
		if ( is_singular( 'page' ) ) {
			return extrachill_docs_generate_sidebar( get_the_ID() );
		}
		return $sidebar_content;
	}
);

// Override back-to-home label for documentation site.
add_filter(
	'extrachill_back_to_home_label',
	// $url is part of the filter signature (arity 2) but unused by this callback.
	function ( $label, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( is_page() ) {
			return '← Back to Documentation';
		}
		return $label;
	},
	10,
	2
);
