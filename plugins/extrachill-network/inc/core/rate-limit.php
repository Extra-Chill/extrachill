<?php
/**
 * Atomic fixed-window rate limits shared by all feature plugins.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return the common fail-closed store error. */
function extrachill_rate_limit_unavailable() {
	return new WP_Error( 'rate_limiter_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-network' ), array( 'status' => 503 ) );
}

/**
 * Persistent-cache implementation, ported from API admission.
 *
 * Add and increment must be atomic across workers; local caches cannot enforce caps.
 */
function extrachill_rate_limit_cache_operation( $key, $ttl, $operation = 'increment' ) {
	if ( ! wp_using_ext_object_cache() ) {
		return extrachill_rate_limit_unavailable();
	}
	$group = 'extrachill_rate_limits';
	if ( 'get' === $operation ) {
		$count = wp_cache_get( $key, $group );
		return false === $count ? 0 : (int) $count;
	}
	if ( 'reset' === $operation ) {
		// Delete only this window; an absent counter is already reset.
		wp_cache_delete( $key, $group );
		return true;
	}
	if ( wp_cache_add( $key, 1, $group, $ttl ) ) {
		return 1;
	}
	$count = wp_cache_incr( $key, 1, $group );
	return false === $count ? extrachill_rate_limit_unavailable() : (int) $count;
}

/** Dispatch one operation through the shared, replaceable atomic store. */
function extrachill_rate_limit_store( $key, $ttl, $operation = 'increment' ) {
	/**
	 * Filters the atomic rate-limit store.
	 *
	 * @param callable $store Receives window key, remaining TTL and operation
	 *                        (increment, get or reset). Returns count, reset success or WP_Error.
	 */
	/** @var mixed $store Filters may supply an invalid callable; fail closed. */
	$store = apply_filters( 'extrachill_rate_limit_store', 'extrachill_rate_limit_cache_operation' );
	if ( ! is_callable( $store ) ) {
		return extrachill_rate_limit_unavailable();
	}
	$result = call_user_func( $store, $key, $ttl, $operation );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( 'reset' === $operation ) {
		return true === $result ? true : extrachill_rate_limit_unavailable();
	}
	$minimum = 'get' === $operation ? 0 : 1;
	return is_numeric( $result ) && $minimum <= (int) $result ? (int) $result : extrachill_rate_limit_unavailable();
}

/** Resolve a stable counter key and remaining lifetime for the current window. */
function extrachill_rate_limit_window( string $key, int $window_seconds ) {
	$window_seconds = max( 1, $window_seconds );
	$now            = time();
	$start          = intdiv( $now, $window_seconds ) * $window_seconds;
	return array( hash( 'sha256', $key ) . ':' . $window_seconds . ':' . $start, $start + $window_seconds - $now );
}

/** Return true, retry-after seconds when full, or WP_Error if unavailable. */
function extrachill_rate_limit_admit( string $key, int $limit, int $window_seconds ) {
	list( $window_key, $ttl ) = extrachill_rate_limit_window( $key, $window_seconds );
	$count                    = extrachill_rate_limit_store( $window_key, $ttl );
	if ( is_wp_error( $count ) ) {
		return $count;
	}
	return $count <= $limit ? true : $ttl;
}

/** Read the current window without incrementing it. */
function extrachill_rate_limit_count( string $key, int $window_seconds ) {
	list( $window_key, $ttl ) = extrachill_rate_limit_window( $key, $window_seconds );
	return extrachill_rate_limit_store( $window_key, $ttl, 'get' );
}

/** Clear the current window after a successful sign-in. */
function extrachill_rate_limit_reset( string $key, int $window_seconds ) {
	list( $window_key, $ttl ) = extrachill_rate_limit_window( $key, $window_seconds );
	return extrachill_rate_limit_store( $window_key, $ttl, 'reset' );
}
