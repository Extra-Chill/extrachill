<?php
/**
 * Load the real artist identity read into a standalone network smoke.
 *
 * Network consumers of ec_get_artist_identity() are tested against the real
 * implementation, not a copy. This file supplies a per-process object cache
 * and the few constants the implementation needs, then loads
 * inc/core/artist-identity.php. Require it after the suite's own WordPress
 * stubs and after support/network-contract.php. Every primitive is guarded,
 * so a suite that already stubs one keeps its own.
 *
 * The suite still provides the data primitives: switch_to_blog(),
 * restore_current_blog(), get_current_blog_id(), get_post(), get_post_meta(),
 * get_posts(), get_term(), get_term_meta() and taxonomy_exists().
 *
 * @package ExtraChillNetwork
 */

// phpcs:disable -- Standalone WordPress primitives for host-PHP smokes.

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! isset( $GLOBALS['ec_test_identity_cache'] ) || ! is_array( $GLOBALS['ec_test_identity_cache'] ) ) {
	$GLOBALS['ec_test_identity_cache'] = array();
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		unset( $force );
		$found = array_key_exists( $group . '|' . $key, $GLOBALS['ec_test_identity_cache'] );
		return $found ? $GLOBALS['ec_test_identity_cache'][ $group . '|' . $key ] : false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		unset( $expire );
		$GLOBALS['ec_test_identity_cache'][ $group . '|' . $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $value, $group = '', $expire = 0 ) {
		if ( array_key_exists( $group . '|' . $key, $GLOBALS['ec_test_identity_cache'] ) ) {
			return false;
		}
		return wp_cache_set( $key, $value, $group, $expire );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['ec_test_identity_cache'][ $group . '|' . $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		if ( ! array_key_exists( $group . '|' . $key, $GLOBALS['ec_test_identity_cache'] ) ) {
			return false;
		}
		$GLOBALS['ec_test_identity_cache'][ $group . '|' . $key ] += $offset;
		return $GLOBALS['ec_test_identity_cache'][ $group . '|' . $key ];
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook_name, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value, ...$args ) {
		unset( $hook_name, $args );
		return $value;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( trim( (string) preg_replace( '/[^a-z0-9-]+/i', '-', (string) $title ), '-' ) );
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/core/artist-identity.php';
