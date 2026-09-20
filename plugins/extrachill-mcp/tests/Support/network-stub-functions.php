<?php
/**
 * Global-namespace declarations of the extrachill-network functions
 * `NetworkDispatch` depends on.
 *
 * Deliberately a separate, namespace-free file: a `function` written inside
 * a namespaced file is declared *in that namespace* — `Foo\Bar\stub_fn()`,
 * never the global `stub_fn()` — so it would never satisfy an unqualified
 * `ec_get_blog_ids()` call from production code nor a global
 * `function_exists( 'ec_get_blog_ids' )` guard. Loaded lazily by
 * `NetworkStub::install()` so `NetworkAbilityAbsenceTest` can assert the
 * genuinely-absent degraded path before this file is ever required.
 *
 * @package ExtraChillMcp\Tests\Support
 */

declare( strict_types = 1 );

use ExtraChillMcp\Tests\Support\NetworkStub;

if ( ! function_exists( 'ec_get_blog_ids' ) ) {
	/**
	 * @return array<string,int>
	 */
	function ec_get_blog_ids(): array { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Deliberate test-only stand-in for the extrachill-network global.
		return NetworkStub::$blog_ids;
	}
}

if ( ! function_exists( 'ec_get_blog_slug_by_id' ) ) {
	/**
	 * @param mixed $blog_id
	 * @return string|null
	 */
	function ec_get_blog_slug_by_id( $blog_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Deliberate test-only stand-in for the extrachill-network global.
		foreach ( NetworkStub::$blog_ids as $slug => $id ) {
			if ( (int) $id === (int) $blog_id ) {
				return $slug;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'ec_get_ability_site_affinity' ) ) {
	/**
	 * @return string|null
	 */
	function ec_get_ability_site_affinity( string $ability_name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Deliberate test-only stand-in for the extrachill-network global.
		return is_callable( NetworkStub::$affinity_handler )
			? ( NetworkStub::$affinity_handler )( $ability_name )
			: null;
	}
}

if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return mixed
	 */
	function ec_cross_site_rest_request( string $site_key, string $method, string $path, array $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Deliberate test-only stand-in for the extrachill-network global.
		if ( ! is_callable( NetworkStub::$cross_site_handler ) ) {
			return new \WP_Error( 'stub_not_configured', 'No stub handler configured for this test.' );
		}

		return ( NetworkStub::$cross_site_handler )( $site_key, $method, $path, $args );
	}
}
