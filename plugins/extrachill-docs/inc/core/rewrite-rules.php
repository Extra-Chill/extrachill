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
	// Register exact rules for child Pages before the legacy catch-all
	// rules. Hierarchical page URLs (/{parent-slug}/{child-slug}/) would
	// otherwise be swallowed by the ec_doc catch-all below.
	$child_page_ids = get_posts(
		array(
			'post_type'           => 'page',
			'post_status'         => 'publish',
			'posts_per_page'      => -1,
			'fields'              => 'ids',
			'post_parent__not_in' => array( 0 ),
			'orderby'             => 'ID',
			'order'               => 'ASC',
			'no_found_rows'       => true,
		)
	);

	foreach ( $child_page_ids as $page_id ) {
		$page_uri = get_page_uri( $page_id );
		if ( ! is_string( $page_uri ) || '' === $page_uri ) {
			continue;
		}

		add_rewrite_rule(
			'^' . preg_quote( trim( $page_uri, '/' ), '#' ) . '/?$',
			'index.php?page_id=' . (int) $page_id,
			'top'
		);
	}

	// Platform archive: /{platform-slug}/.
	add_rewrite_rule(
		'^([^/]+)/?$',
		'index.php?ec_doc_platform=$matches[1]',
		'top'
	);

	// Single doc: /{platform-slug}/{doc-slug}/.
	add_rewrite_rule(
		'^([^/]+)/([^/]+)/?$',
		'index.php?ec_doc=$matches[2]&ec_doc_platform=$matches[1]',
		'top'
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
	if ( 'ec_doc' !== $post->post_type ) {
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
	if ( 'ec_doc_platform' !== $taxonomy ) {
		return $termlink;
	}

	return home_url( '/' . $term->slug . '/' );
}
add_filter( 'term_link', 'extrachill_docs_term_link', 10, 3 );
