<?php
/**
 * Documentation Rewrite Rules
 *
 * Custom URL structure: /{platform-slug}/{doc-slug}/
 *
 * @package ExtraChillDocs
 * @since 0.2.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add custom rewrite rules for ec_doc posts and ec_doc_platform taxonomy
 */
function extrachill_docs_add_rewrite_rules() {
	// Platform archive: /{platform-slug}/
	add_rewrite_rule(
		'^([^/]+)/?$',
		'index.php?ec_doc_platform=$matches[1]',
		'bottom'
	);

	// Single doc: /{platform-slug}/{doc-slug}/
	add_rewrite_rule(
		'^([^/]+)/([^/]+)/?$',
		'index.php?ec_doc=$matches[2]&ec_doc_platform=$matches[1]',
		'bottom'
	);
}
add_action( 'init', 'extrachill_docs_add_rewrite_rules', 20 );

/**
 * Filter ec_doc permalinks to use /{platform-slug}/{doc-slug}/ structure
 *
 * @param string  $post_link The post's permalink.
 * @param WP_Post $post      The post object.
 * @return string Modified permalink.
 */
function extrachill_docs_post_type_link( $post_link, $post ) {
	if ( $post->post_type !== 'ec_doc' ) {
		return $post_link;
	}

	$terms = get_the_terms( $post->ID, 'ec_doc_platform' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return $post_link;
	}

	$platform = reset( $terms );
	return home_url( '/' . $platform->slug . '/' . $post->post_name . '/' );
}
add_filter( 'post_type_link', 'extrachill_docs_post_type_link', 10, 2 );

/**
 * Filter ec_doc_platform term links to use /{platform-slug}/ structure
 *
 * @param string  $termlink Term link URL.
 * @param WP_Term $term     Term object.
 * @param string  $taxonomy Taxonomy slug.
 * @return string Modified term link.
 */
function extrachill_docs_term_link( $termlink, $term, $taxonomy ) {
	if ( $taxonomy !== 'ec_doc_platform' ) {
		return $termlink;
	}

	return home_url( '/' . $term->slug . '/' );
}
add_filter( 'term_link', 'extrachill_docs_term_link', 10, 3 );
