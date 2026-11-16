<?php
/**
 * Search Result Ordering for ExtraChill Search Plugin
 *
 * Owns how a front-end search request orders its results. The search plugin
 * renders results itself (the theme's archive sorting never touches them), so
 * the shared filter-bar "Sort by" control only works on search pages when this
 * layer resolves the request's `sort` value and passes it to the search
 * primitive, which applies it via extrachill_order_search_results().
 *
 * Supported orderings are the ones the network search can honor:
 * - 'relevance' (default): weighted relevance score, newest first on ties.
 * - 'recent':  matching results ordered newest first.
 * - 'oldest':  matching results ordered oldest first.
 *
 * Random and popularity ordering are archive-only and are removed from the
 * sort control on search results rather than offered without effect.
 *
 * This file also keeps the active search scope inside the filter-bar form so
 * re-searching or changing the sort does not silently fall back to the
 * default scope.
 *
 * @package ExtraChill\Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query var carrying the requested search ordering.
 *
 * Shared with the theme's filter-bar sort control.
 *
 * @return string
 */
function extrachill_search_sort_query_var() {
	return 'sort';
}

/**
 * Return the valid search orderings.
 *
 * @return string[] Ordering identifiers, default first.
 */
function extrachill_search_sort_choices() {
	return array( 'relevance', 'recent', 'oldest' );
}

/**
 * Resolve the requested search ordering from the current request.
 *
 * @return string One of extrachill_search_sort_choices().
 */
function extrachill_resolve_search_sort() {
	$query_var = extrachill_search_sort_query_var();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display ordering.
	$raw = isset( $_GET[ $query_var ] ) ? sanitize_key( wp_unslash( $_GET[ $query_var ] ) ) : '';

	return in_array( $raw, extrachill_search_sort_choices(), true ) ? $raw : 'relevance';
}

/**
 * Restrict the filter-bar sort control to orderings search can honor.
 *
 * Hooked to the theme's `extrachill_filter_bar_sort_options` filter.
 *
 * @param array<string, string> $options Sort options keyed by value.
 * @return array<string, string> Sort options.
 */
function extrachill_search_filter_bar_sort_options( $options ) {
	if ( ! is_search() ) {
		return $options;
	}

	return array(
		'relevance' => __( 'Sort by Relevance', 'extrachill-search' ),
		'recent'    => __( 'Sort by Recent', 'extrachill-search' ),
		'oldest'    => __( 'Sort by Oldest', 'extrachill-search' ),
	);
}

/**
 * Carry the active search scope through the filter-bar form.
 *
 * Hooked to the theme's `extrachill_filter_bar_start` action so re-searching
 * or changing the sort keeps the scope the visitor chose.
 *
 * @return void
 */
function extrachill_search_filter_bar_scope_field() {
	if ( ! is_search() || ! function_exists( 'extrachill_resolve_search_scope' ) ) {
		return;
	}

	printf(
		'<input type="hidden" name="%s" value="%s">',
		esc_attr( extrachill_search_scope_query_var() ),
		esc_attr( extrachill_resolve_search_scope() )
	);
}
