<?php
/**
 * Shared fixed-window admission for public REST writes.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomically increment one bounded fixed-window counter.
 *
 * The production store uses the platform's persistent object cache, where
 * add/increment are atomic across workers. Tests may replace the store with a
 * process-safe implementation to exercise concurrent admission deterministically.
 *
 * @param string $key Stable opaque counter key.
 * @param int    $ttl Remaining fixed-window lifetime.
 * @return int|WP_Error
 */
function extrachill_api_atomic_rate_limit_increment( $key, $ttl ) {
	/**
	 * Filters the atomic API rate-limit store.
	 *
	 * @param callable $store Callable accepting key and TTL.
	 */
	$default_store = str_replace( '-', '_', 'extrachill-api-atomic-rate-limit-cache-increment' );
	$store         = apply_filters( 'extrachill_api_rate_limit_store', $default_store );
	if ( ! is_callable( $store ) ) {
		return new WP_Error( 'api_rate_limiter_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}
	$count = call_user_func( $store, (string) $key, max( 1, (int) $ttl ) );
	if ( is_wp_error( $count ) ) {
		return $count;
	}

	return is_numeric( $count ) && (int) $count > 0
		? (int) $count
		: new WP_Error( 'api_rate_limiter_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
}

/** Use atomic persistent-cache operations for the production counter. */
function extrachill_api_atomic_rate_limit_cache_increment( $key, $ttl ) {
	if ( ! wp_using_ext_object_cache() ) {
		return new WP_Error( 'api_rate_limiter_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}
	$group = 'extrachill_api_rate_limits';
	if ( wp_cache_add( $key, 1, $group, $ttl ) ) {
		return 1;
	}
	$count = wp_cache_incr( $key, 1, $group );

	return false === $count
		? new WP_Error( 'api_rate_limiter_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) )
		: (int) $count;
}

/** Atomically decide whether this request remains within its exact cap. */
function extrachill_api_atomic_rate_limit_admit( $key, $limit, $ttl ) {
	$count = extrachill_api_atomic_rate_limit_increment( $key, $ttl );
	return is_wp_error( $count ) ? $count : $count <= (int) $limit;
}

/**
 * Apply the atomic per-client limiter to one public-write scope.
 *
 * @param WP_REST_Request $request REST request.
 * @param string          $scope   Stable limiter scope.
 * @param int             $limit   Maximum writes per minute.
 * @return true|WP_Error
 */
function extrachill_api_check_public_write_rate_limit( WP_REST_Request $request, $scope, $limit ) {
	$limit = (int) $limit;
	if ( $limit < 1 ) {
		return true;
	}

	$context = function_exists( 'extrachill_api_route_affinity_context' ) ? extrachill_api_route_affinity_context( $request ) : array();
	if ( $context ) {
		$client = strtolower( sanitize_text_field( (string) ( $context['client'] ?? '' ) ) );
		$ip     = sanitize_text_field( (string) ( $context['remote_addr'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $client ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'public_write_admission_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
		}
	} else {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'public_write_admission_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
		}
		$client = hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	$now         = time();
	$window      = intdiv( $now, MINUTE_IN_SECONDS );
	$retry_after = ( ( $window + 1 ) * MINUTE_IN_SECONDS ) - $now;
	$key         = 'write_' . substr( hash( 'sha256', sanitize_key( $scope ) . ':' . $client . ':' . $window ), 0, 40 );
	$admitted    = extrachill_api_atomic_rate_limit_admit( $key, $limit, $retry_after );
	if ( is_wp_error( $admitted ) ) {
		return $admitted;
	}
	if ( ! $admitted ) {
		return new WP_Error(
			'public_write_rate_limited',
			__( 'Too many requests. Please try again later.', 'extrachill-api' ),
			array(
				'status'      => 429,
				'retry_after' => $retry_after,
				'headers'     => array( 'Retry-After' => (string) $retry_after ),
			)
		);
	}

	return true;
}

/** Apply the atomic per-client limiter to one public-read scope. */
function extrachill_api_check_public_read_rate_limit( WP_REST_Request $request, $scope, $limit ) {
	$limit = (int) $limit;
	if ( $limit < 1 ) {
		return true;
	}

	$context = function_exists( 'extrachill_api_route_affinity_context' ) ? extrachill_api_route_affinity_context( $request ) : array();
	if ( $context ) {
		$client = strtolower( sanitize_text_field( (string) ( $context['client'] ?? '' ) ) );
		$ip     = sanitize_text_field( (string) ( $context['remote_addr'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $client ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'public_read_admission_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
		}
	} else {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'public_read_admission_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
		}
		$client = hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	$now         = time();
	$window      = intdiv( $now, MINUTE_IN_SECONDS );
	$retry_after = ( ( $window + 1 ) * MINUTE_IN_SECONDS ) - $now;
	$key         = 'read_' . substr( hash( 'sha256', sanitize_key( $scope ) . ':' . $client . ':' . $window ), 0, 40 );
	$admitted    = extrachill_api_atomic_rate_limit_admit( $key, $limit, $retry_after );
	if ( is_wp_error( $admitted ) ) {
		return $admitted;
	}
	if ( ! $admitted ) {
		return new WP_Error(
			'public_read_rate_limited',
			__( 'Too many requests. Please try again later.', 'extrachill-api' ),
			array(
				'status'      => 429,
				'retry_after' => $retry_after,
				'headers'     => array( 'Retry-After' => (string) $retry_after ),
			)
		);
	}

	return true;
}
