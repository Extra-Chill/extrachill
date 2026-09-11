<?php
/**
 * Test doubles for Extra Chill platform functions owned by other plugins.
 *
 * The analytics plugin consumes a small platform contract from the
 * extrachill-network plugin (site registry, domain map, batch path resolver).
 * The managed harness loads only the plugin under test, so these doubles keep
 * that contract observable in tests. Every definition is guarded: when the
 * real platform plugin is loaded, its implementations always win.
 *
 * These are NOT WordPress core functions and this file intentionally defines
 * no WordPress core symbols.
 *
 * @package ExtraChill\Analytics
 */

if ( ! function_exists( 'ec_get_all_site_ids' ) ) {
	/**
	 * Return active network site IDs supplied by a test fixture.
	 *
	 * @return int[] Active site IDs.
	 */
	function ec_get_all_site_ids() {
		return isset( $GLOBALS['extrachill_analytics_test_active_site_ids'] ) ? $GLOBALS['extrachill_analytics_test_active_site_ids'] : array();
	}
}

if ( ! function_exists( 'ec_get_domain_map' ) ) {
	/**
	 * Return the mapped-domain fixture (domain => blog_id).
	 *
	 * @return array<string,int>
	 */
	function ec_get_domain_map() {
		return isset( $GLOBALS['extrachill_analytics_test_domain_map'] ) ? $GLOBALS['extrachill_analytics_test_domain_map'] : array();
	}
}

if ( ! function_exists( 'ec_get_blog_slug_by_id' ) ) {
	/**
	 * Return the fixture site slug for a blog ID.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string Site slug.
	 */
	function ec_get_blog_slug_by_id( $blog_id ) {
		$slugs = isset( $GLOBALS['extrachill_analytics_test_blog_slugs'] ) ? $GLOBALS['extrachill_analytics_test_blog_slugs'] : array( 1 => 'main' );
		return isset( $slugs[ (int) $blog_id ] ) ? (string) $slugs[ (int) $blog_id ] : '';
	}
}

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	/**
	 * Return the fixture blog ID for a site slug.
	 *
	 * @param string $slug Site slug.
	 * @return int|null Blog ID, or null when unknown.
	 */
	function ec_get_blog_id( $slug ) {
		$slugs = isset( $GLOBALS['extrachill_analytics_test_blog_slugs'] ) ? $GLOBALS['extrachill_analytics_test_blog_slugs'] : array( 1 => 'main' );
		$found = array_search( (string) $slug, $slugs, true );
		return false === $found ? null : (int) $found;
	}
}

if ( ! function_exists( 'ec_resolve_frontend_paths' ) ) {
	/**
	 * Test double for the Network batch path resolver contract.
	 *
	 * @param array $paths Host-relative paths.
	 * @param array $args Resolver arguments.
	 * @return array Contract-shaped fixture response.
	 */
	function ec_resolve_frontend_paths( array $paths, array $args = array() ) {
		unset( $args );
		$GLOBALS['extrachill_network_resolver_calls'][] = $paths;
		if ( ! empty( $GLOBALS['extrachill_network_resolver_incomplete'] ) ) {
			return array(
				'scan'    => array( 'status' => 'incomplete' ),
				'results' => array(),
			);
		}
		$fixtures = isset( $GLOBALS['extrachill_network_resolver_results'] ) ? $GLOBALS['extrachill_network_resolver_results'] : array();
		$results  = array();
		foreach ( $paths as $path ) {
			$results[] = isset( $fixtures[ $path ] ) ? $fixtures[ $path ] : array(
				'path'   => $path,
				'status' => 'unresolved',
			);
		}
		return array(
			'scan'    => array( 'status' => 'complete' ),
			'results' => $results,
		);
	}
}
