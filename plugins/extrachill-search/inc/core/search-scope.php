<?php
/**
 * Search Scope Resolution for ExtraChill Search Plugin
 *
 * Owns the single decision of "which sites does a front-end search cover":
 * the current site only ('site', the default) or the whole network
 * ('network'). Everything else in the plugin asks this layer instead of
 * re-deriving scope from the request.
 *
 * The underlying primitive — extrachill_multisite_search( $term, $site_urls )
 * — is unchanged: an empty $site_urls still means "whole network". This
 * layer only decides what $site_urls the *front-end search request* should
 * resolve to, defaulting to the current site so a visitor on one site is
 * not surprised by network-wide results.
 *
 * @package ExtraChill\Search
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical query var used to carry the search scope through the request.
 *
 * @return string
 */
function extrachill_search_scope_query_var() {
	return 'search_scope';
}

/**
 * Return the list of valid scope keys.
 *
 * @return string[] List of scope identifiers.
 */
function extrachill_search_scope_choices() {
	return array( 'site', 'network' );
}

/**
 * The default scope for front-end searches.
 *
 * Defaults to the current site so searches are not surprising. Filterable
 * so a site (or future setting) can opt into network-wide by default.
 *
 * @return string Either 'site' or 'network'.
 */
function extrachill_search_default_scope() {
	$default = 'site';

	/**
	 * Filter the default search scope for front-end searches.
	 *
	 * @param string $default Either 'site' or 'network'.
	 */
	$default = apply_filters( 'extrachill_search_default_scope', $default );

	return in_array( $default, extrachill_search_scope_choices(), true ) ? $default : 'site';
}

/**
 * Resolve the active search scope from the current request.
 *
 * Reads the scope query var, validating it against the allowed choices and
 * falling back to the default scope when absent or invalid.
 *
 * @return string Either 'site' or 'network'.
 */
function extrachill_resolve_search_scope() {
	$query_var = extrachill_search_scope_query_var();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$raw = isset( $_GET[ $query_var ] ) ? sanitize_key( wp_unslash( $_GET[ $query_var ] ) ) : '';

	if ( in_array( $raw, extrachill_search_scope_choices(), true ) ) {
		$scope = $raw;
	} else {
		$scope = extrachill_search_default_scope();
	}

	/**
	 * Filter the resolved search scope for the current request.
	 *
	 * @param string $scope Either 'site' or 'network'.
	 */
	return apply_filters( 'extrachill_search_scope', $scope );
}

/**
 * Resolve a scope key into the $site_urls argument for the search primitive.
 *
 * - 'network' returns an empty array, which extrachill_multisite_search()
 *   treats as "every network site".
 * - 'site' returns the current site's host so only that blog is searched.
 *
 * @param string|null $scope Optional explicit scope. Resolves from the request when null.
 * @return array Site URL/host list to pass to extrachill_multisite_search().
 */
function extrachill_search_scope_site_urls( $scope = null ) {
	if ( null === $scope ) {
		$scope = extrachill_resolve_search_scope();
	}

	if ( 'network' === $scope ) {
		return array();
	}

	// 'site' scope: restrict to the current blog's host.
	$host = wp_parse_url( get_site_url(), PHP_URL_HOST );

	$site_urls = $host ? array( $host ) : array();

	/**
	 * Filter the site URL list resolved for the 'site' scope.
	 *
	 * @param array  $site_urls Host list passed to the search primitive.
	 * @param string $scope     The resolved scope key.
	 */
	return apply_filters( 'extrachill_search_scope_site_urls', $site_urls, $scope );
}
