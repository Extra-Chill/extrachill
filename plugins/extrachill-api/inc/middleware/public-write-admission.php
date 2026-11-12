<?php
/**
 * Public REST adapters for network fixed-window admission.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Apply the per-client limiter to one public-write scope. */
function extrachill_api_check_public_write_rate_limit( WP_REST_Request $request, $scope, $limit ) {
	return extrachill_api_check_public_rate_limit( $request, $scope, $limit, 'write' );
}

/** Apply the per-client limiter to one public-read scope. */
function extrachill_api_check_public_read_rate_limit( WP_REST_Request $request, $scope, $limit ) {
	return extrachill_api_check_public_rate_limit( $request, $scope, $limit, 'read' );
}

/** Internal shared adapter preserving the transport's public error contract. */
function extrachill_api_check_public_rate_limit( WP_REST_Request $request, $scope, $limit, $kind ) {
	$limit = (int) $limit;
	if ( 1 > $limit ) {
		return true;
	}

	$context = function_exists( 'extrachill_api_route_affinity_context' ) ? extrachill_api_route_affinity_context( $request ) : array();
	if ( $context ) {
		$client = strtolower( sanitize_text_field( (string) ( $context['client'] ?? '' ) ) );
		$ip     = sanitize_text_field( (string) ( $context['remote_addr'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $client ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'public_' . $kind . '_admission_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
		}
	} else {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'public_' . $kind . '_admission_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
		}
		$client = hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	$key      = 'api_' . $kind . ':' . sanitize_key( $scope ) . ':' . $client;
	$admitted = extrachill_rate_limit_admit( $key, $limit, MINUTE_IN_SECONDS );
	if ( is_wp_error( $admitted ) ) {
		return new WP_Error( 'api_rate_limiter_unavailable', __( 'Request admission is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}
	if ( true !== $admitted ) {
		return new WP_Error(
			'public_' . $kind . '_rate_limited',
			__( 'Too many requests. Please try again later.', 'extrachill-api' ),
			array(
				'status'      => 429,
				'retry_after' => $admitted,
				'headers'     => array( 'Retry-After' => (string) $admitted ),
			)
		);
	}

	return true;
}
