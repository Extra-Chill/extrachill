<?php
/**
 * Sparse Taxonomy Terms
 *
 * One rule for "this term archive is too thin to index": fewer than
 * EC_SEO_MIN_INDEXABLE_TERM_COUNT published posts, except festival hubs.
 * robots.php noindexes these archives; this file keeps them out of the core
 * taxonomy sitemaps too, so the sitemap never lists a URL the page itself
 * tells Google not to index.
 *
 * Core builds a taxonomy sitemap page and its page count from the same
 * WP_Term_Query args (`wp_sitemaps_taxonomies_query_args`), so one flag plus
 * a `terms_clauses` condition keeps the URL list and pagination consistent.
 *
 * The `terms_clauses` callback is registered only once a sitemap term query
 * is being built. `terms_clauses` runs for every term query on the network,
 * and consumers such as data-machine-events treat any registered callback
 * there as a term-query customization that disables their grouped SQL fast
 * path. A request-wide registration pushed event filter counts onto an
 * unbounded fallback and exhausted memory on past-event archives.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EC_SEO_MIN_INDEXABLE_TERM_COUNT = 2;

/**
 * Term query var carrying the minimum term count for sitemap queries.
 */
const EC_SEO_SITEMAP_MIN_COUNT_ARG = 'ec_seo_min_count';

/**
 * Taxonomies whose term archives stay indexable regardless of post count.
 *
 * @return string[]
 */
function ec_seo_sparse_exempt_taxonomies(): array {
	return array( 'festival' );
}

/**
 * Whether a term archive is too sparse to index.
 *
 * @param object $term Term with `taxonomy` and `count` properties.
 * @return bool
 */
function ec_seo_is_sparse_term( $term ): bool {
	if ( ! is_object( $term ) || ! isset( $term->count, $term->taxonomy ) ) {
		return false;
	}

	if ( in_array( (string) $term->taxonomy, ec_seo_sparse_exempt_taxonomies(), true ) ) {
		return false;
	}

	return (int) $term->count < EC_SEO_MIN_INDEXABLE_TERM_COUNT;
}

/**
 * Restrict core taxonomy sitemaps to indexable terms.
 *
 * @param array  $args     WP_Term_Query args.
 * @param string $taxonomy Taxonomy name.
 * @return array
 */
function ec_seo_sitemap_exclude_sparse_terms( $args, $taxonomy ) {
	if ( ! is_array( $args ) || in_array( (string) $taxonomy, ec_seo_sparse_exempt_taxonomies(), true ) ) {
		return $args;
	}

	$args[ EC_SEO_SITEMAP_MIN_COUNT_ARG ] = EC_SEO_MIN_INDEXABLE_TERM_COUNT;

	ec_seo_register_sitemap_min_count_clause();

	return $args;
}
add_filter( 'wp_sitemaps_taxonomies_query_args', __NAMESPACE__ . '\\ec_seo_sitemap_exclude_sparse_terms', 10, 2 );

/**
 * Apply the sitemap minimum count to the term query SQL.
 *
 * @param array $clauses    Term query clauses.
 * @param array $taxonomies Taxonomies.
 * @param array $args       Term query vars.
 * @return array
 */
function ec_seo_sitemap_min_count_clause( $clauses, $taxonomies, $args ) {
	if ( ! is_array( $clauses ) || ! is_array( $args ) || empty( $args[ EC_SEO_SITEMAP_MIN_COUNT_ARG ] ) ) {
		return $clauses;
	}

	$clauses['where'] = ( isset( $clauses['where'] ) && '' !== $clauses['where'] ? $clauses['where'] . ' AND ' : '' )
		. sprintf( 'tt.count >= %d', (int) $args[ EC_SEO_SITEMAP_MIN_COUNT_ARG ] );

	return $clauses;
}

/**
 * Register the sitemap minimum-count clause for the current request.
 *
 * Called only from the sitemap query-args filter so non-sitemap requests
 * never carry a `terms_clauses` callback.
 *
 * @return bool True when the clause was registered by this call.
 */
function ec_seo_register_sitemap_min_count_clause(): bool {
	static $registered = false;

	if ( $registered ) {
		return false;
	}

	$registered = true;
	add_filter( 'terms_clauses', __NAMESPACE__ . '\\ec_seo_sitemap_min_count_clause', 10, 3 );

	return true;
}
