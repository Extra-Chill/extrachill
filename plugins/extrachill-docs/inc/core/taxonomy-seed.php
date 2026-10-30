<?php
/**
 * Documentation Platform Taxonomy Seeding
 *
 * Seeds ec_doc_platform terms on plugin activation, derived from the
 * canonical network site map in extrachill-multisite.
 *
 * Source of truth: ec_get_blog_ids() in extrachill-multisite/inc/core/blog-ids.php.
 * Each slug maps to ec_docs/{slug}/ for markdown source and the corresponding
 * network site for docs-info API calls.
 *
 * Sites excluded from seeding:
 * - docs (this site doesn't document itself)
 *
 * Custom terms (e.g. 'chat' for AI chat docs) can be created manually
 * outside the seed function.
 *
 * @package ExtraChillDocs
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the platform display name for a canonical blog slug.
 *
 * @since 0.4.0
 *
 * @param string $slug Canonical blog slug from ec_get_blog_ids().
 * @return string Human-readable platform name.
 */
function extrachill_docs_get_platform_label( string $slug ): string {
	$labels = array(
		'main'       => 'Blog',
		'community'  => 'Community',
		'shop'       => 'Shop',
		'artist'     => 'Artist Platform',
		'events'     => 'Events',
		'newsletter' => 'Newsletter',
		'wire'       => 'News Wire',
		'studio'     => 'Studio',
	);

	return $labels[ $slug ] ?? ucfirst( $slug );
}

/**
 * Get the list of blog slugs to seed as platform terms.
 *
 * Derives from ec_get_blog_ids(), excluding sites that shouldn't have
 * their own platform term (e.g. docs itself).
 *
 * @since 0.4.0
 *
 * @return string[] Array of canonical blog slugs.
 */
function extrachill_docs_get_seed_slugs(): array {
	if ( ! function_exists( 'ec_get_blog_ids' ) ) {
		// Fallback: no multisite plugin, seed nothing.
		return array();
	}

	$exclude = array(
		'docs', // This site doesn't document itself.
	);

	$slugs = array_keys( ec_get_blog_ids() );

	return array_values( array_diff( $slugs, $exclude ) );
}

/**
 * Seeds default platform terms on plugin activation.
 *
 * Derives terms from the canonical network site map (ec_get_blog_ids)
 * rather than maintaining its own hardcoded list.
 *
 * @since 0.1.0
 * @since 0.4.0 Source from ec_get_blog_ids() instead of hardcoded array.
 *
 * @return void
 */
function extrachill_docs_seed_platforms() {
	// Ensure taxonomy is registered before seeding.
	extrachill_docs_register_platform_taxonomy();

	$slugs = extrachill_docs_get_seed_slugs();

	foreach ( $slugs as $slug ) {
		$name = extrachill_docs_get_platform_label( $slug );

		if ( ! term_exists( $slug, 'ec_doc_platform' ) ) {
			wp_insert_term( $name, 'ec_doc_platform', array( 'slug' => $slug ) );
		}
	}
}
