<?php
/**
 * Route Affinity Middleware
 *
 * Intercepts REST API requests and forwards them to the correct subsite
 * when the current site doesn't match the route's home site.
 *
 * This makes the REST API universally accessible — any route can be called
 * from any site in the network. The middleware transparently proxies the
 * request to the correct subsite via internal HTTP (localhost).
 *
 * Requires: ec_cross_site_rest_request() from extrachill-multisite.
 *
 * @package ExtraChillAPI
 * @since 0.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep artist item affinity on a path-segment boundary.
 *
 * @param array $affinity_map Route prefix to site key map.
 * @return array
 */
function extrachill_api_add_artist_route_affinity( $affinity_map ) {
	$affinity_map['/extrachill/v1/artists/'] = 'artist';

	return $affinity_map;
}
add_filter( 'ec_route_site_affinity_map', 'extrachill_api_add_artist_route_affinity' );

/**
 * Normalize structured request data before hashing it.
 *
 * @param mixed $value Value to normalize.
 * @return mixed
 */
function extrachill_api_normalize_affinity_data( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
	if ( ! $is_list ) {
		ksort( $value, SORT_STRING );
	}

	foreach ( $value as $key => $item ) {
		$value[ $key ] = extrachill_api_normalize_affinity_data( $item );
	}

	return $value;
}

/**
 * Canonicalize query input exactly as the cross-site HTTP hop transmits it.
 *
 * The helper uses http_build_query(), and the target PHP request parses that
 * wire string before WordPress exposes query parameters. Replaying both steps
 * preserves false-as-0 while omitting null and empty arrays at every depth.
 *
 * @param array $query Query parameters before transport.
 * @return array
 */
function extrachill_api_canonicalize_affinity_query( $query ) {
	$canonical = array();
	parse_str( http_build_query( $query ), $canonical );

	return extrachill_api_normalize_affinity_data( $canonical );
}

/**
 * Create the signed route-affinity payload.
 *
 * @param string $method      HTTP method.
 * @param string $route       REST route.
 * @param string $target_host Target host.
 * @param array  $query       Query parameters.
 * @param mixed  $body        Request body.
 * @param int    $timestamp   Unix timestamp.
 * @param string $nonce       Single-use nonce.
 * @return string
 */
function extrachill_api_route_affinity_payload( $method, $route, $target_host, $query, $body, $timestamp, $nonce ) {
	$query_digest = hash( 'sha256', wp_json_encode( extrachill_api_canonicalize_affinity_query( $query ) ) );
	$body_digest  = hash( 'sha256', wp_json_encode( extrachill_api_normalize_affinity_data( $body ) ) );

	return implode(
		"\n",
		array(
			strtoupper( $method ),
			$route,
			strtolower( $target_host ),
			$query_digest,
			$body_digest,
			(string) $timestamp,
			$nonce,
		)
	);
}

/**
 * Read the body shape that route-affinity forwards.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return mixed
 */
function extrachill_api_route_affinity_request_body( WP_REST_Request $request ) {
	if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
		return array();
	}

	$body = $request->get_json_params();
	if ( ! empty( $body ) ) {
		return $body;
	}

	$body = $request->get_body_params();

	return ! empty( $body ) ? $body : array();
}

/**
 * Check whether the current HTTP request originated from loopback.
 *
 * @return bool
 */
function extrachill_api_is_local_request() {
	$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return in_array( $remote_addr, array( '127.0.0.1', '::1' ), true );
}

/**
 * Check whether any route-affinity token fields were supplied.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return bool
 */
function extrachill_api_has_route_affinity_token( WP_REST_Request $request ) {
	foreach ( array( 'X-EC-Affinity-Timestamp', 'X-EC-Affinity-Signature', 'X-EC-Affinity-Target', 'X-EC-Affinity-Nonce' ) as $header ) {
		if ( $request->get_header( $header ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether a request is a trusted route-affinity re-entry.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return bool
 */
function extrachill_api_is_route_affinity_reentry( WP_REST_Request $request ) {
	if ( ! extrachill_api_is_local_request() ) {
		return false;
	}

	$timestamp = (int) $request->get_header( 'X-EC-Affinity-Timestamp' );
	$signature = $request->get_header( 'X-EC-Affinity-Signature' );
	$target    = strtolower( $request->get_header( 'X-EC-Affinity-Target' ) );
	$nonce     = $request->get_header( 'X-EC-Affinity-Nonce' );
	$host      = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	if ( ! $timestamp || ! $signature || ! $target || ! $nonce || $target !== $host || abs( time() - $timestamp ) > 300 ) {
		return false;
	}

	$payload  = extrachill_api_route_affinity_payload(
		$request->get_method(),
		$request->get_route(),
		$target,
		$request->get_query_params(),
		extrachill_api_route_affinity_request_body( $request ),
		$timestamp,
		$nonce
	);
	$expected = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	if ( ! hash_equals( $expected, $signature ) ) {
		return false;
	}

	// Persistent object caches make this single-use across loopback workers.
	// Without one, localhost and the five-minute signature window remain the
	// residual replay boundary.
	return wp_cache_add( 'route_affinity_' . hash( 'sha256', $nonce ), 1, 'extrachill_api', 300 );
}

/**
 * Check if a REST request should be forwarded to another subsite.
 *
 * Hooked into `rest_pre_dispatch` — if the route belongs to a different site,
 * forwards the request via internal HTTP and returns the response directly.
 *
 * @param mixed           $result  Response to replace the requested version with. Default null.
 * @param WP_REST_Server  $server  REST server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed Forwarded response or null to continue normal dispatch.
 */
function extrachill_api_route_affinity_dispatch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	// Only a signed localhost request can suppress forwarding on re-entry.
	if ( extrachill_api_is_route_affinity_reentry( $request ) ) {
		return $result;
	}
	if ( extrachill_api_is_local_request() && extrachill_api_has_route_affinity_token( $request ) ) {
		return new WP_Error( 'route_affinity_reentry_invalid', 'Invalid or expired route-affinity token.', array( 'status' => 403 ) );
	}

	// Only handle extrachill/v1 routes.
	$route = $request->get_route();
	if ( ! str_starts_with( $route, '/extrachill/v1/' ) ) {
		return $result;
	}

	// Check if the multisite helper is available.
	if ( ! function_exists( 'ec_get_route_site_affinity' ) || ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return $result;
	}

	// Determine which site this route belongs to.
	$target_site = '/extrachill/v1/artists' === $route ? 'artist' : ec_get_route_site_affinity( $route );

	if ( ! $target_site ) {
		return $result; // No affinity — handle normally on current site.
	}

	// Check if we're already on the correct site.
	if ( function_exists( 'ec_get_blog_id' ) ) {
		$target_blog_id  = ec_get_blog_id( $target_site );
		$current_blog_id = get_current_blog_id();

		if ( $target_blog_id && (int) $current_blog_id === (int) $target_blog_id ) {
			return $result; // Already on the right site — handle normally.
		}
	}

	// Forward the request to the correct subsite.
	$method = $request->get_method();

	// Build the REST path (everything after /extrachill/v1).
	$path = substr( $route, strlen( '/extrachill/v1' ) );

	$args = array();

	// Forward query parameters.
	$query_params = $request->get_query_params();
	if ( ! empty( $query_params ) ) {
		$args['query'] = $query_params;
	}

	// Forward body for write methods.
	$body = extrachill_api_route_affinity_request_body( $request );
	if ( ! empty( $body ) ) {
		$args['body'] = $body;
	}

	// Forward the current user context.
	$current_user_id = get_current_user_id();
	if ( $current_user_id > 0 ) {
		$args['user_id'] = $current_user_id;
	}

	$target_url  = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( $target_site ) : '';
	$target_host = $target_url ? wp_parse_url( $target_url, PHP_URL_HOST ) : '';
	if ( ! $target_host ) {
		return new WP_Error( 'route_affinity_target_invalid', 'Could not resolve route-affinity target host.', array( 'status' => 500 ) );
	}

	$timestamp       = time();
	$nonce           = wp_generate_uuid4();
	$payload         = extrachill_api_route_affinity_payload( $method, $route, $target_host, $query_params, $body, $timestamp, $nonce );
	$signature       = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	$args['headers'] = array(
		'X-EC-Affinity-Timestamp' => (string) $timestamp,
		'X-EC-Affinity-Signature' => $signature,
		'X-EC-Affinity-Target'    => strtolower( $target_host ),
		'X-EC-Affinity-Nonce'     => $nonce,
	);

	// Force HTTP loopback for affinity forwarding.
	//
	// A route only has site affinity because its handler depends on the
	// target site's plugin stack — e.g. /events/* handlers call abilities
	// registered by extrachill-events, which is active ONLY on the events
	// site. The default in-process cross-site path (switch_to_blog() +
	// rest_do_request()) swaps DB/options context but does NOT bootstrap the
	// target site's per-site plugins, so those abilities are never registered
	// in the source process. The forwarded request then runs the handler with
	// the ability missing → WP_Abilities_Registry logs a "not found" notice on
	// every call and the route returns a 500.
	//
	// HTTP loopback spins up a fresh PHP-FPM worker that bootstraps the target
	// site's full plugin stack, so the per-site ability is registered and the
	// handler resolves correctly. This is precisely the documented use case for
	// the loopback path. See Extra-Chill/extrachill-events#141.
	$force_http_loopback     = static function () {
		return true;
	};
	$forwarded_http_response = null;
	$capture_http_response   = static function ( $http_response, $http_args ) use ( &$forwarded_http_response, $signature ) {
		$headers = $http_args['headers'] ?? array();
		if ( ( $headers['X-EC-Affinity-Signature'] ?? '' ) === $signature && false !== $http_response ) {
			$forwarded_http_response = $http_response;
		}

		return $http_response;
	};
	add_filter( 'ec_cross_site_use_http_loopback', $force_http_loopback, 10, 0 );
	add_filter( 'pre_http_request', $capture_http_response, PHP_INT_MAX, 2 );
	add_filter( 'http_response', $capture_http_response, PHP_INT_MAX, 2 );

	try {
		$response = ec_cross_site_rest_request( $target_site, $method, $path, $args );
	} finally {
		remove_filter( 'ec_cross_site_use_http_loopback', $force_http_loopback, 10 );
		remove_filter( 'pre_http_request', $capture_http_response, PHP_INT_MAX );
		remove_filter( 'http_response', $capture_http_response, PHP_INT_MAX );
	}

	if ( is_array( $forwarded_http_response ) ) {
		$status  = wp_remote_retrieve_response_code( $forwarded_http_response );
		$headers = wp_remote_retrieve_headers( $forwarded_http_response );
		$body    = wp_remote_retrieve_body( $forwarded_http_response );
		$data    = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$data = $body;
		}

		if ( $headers instanceof Traversable ) {
			$headers = iterator_to_array( $headers );
		}

		return new WP_REST_Response( $data, $status, is_array( $headers ) ? $headers : array() );
	}

	if ( is_wp_error( $response ) ) {
		$status = $response->get_error_data()['status'] ?? 500;
		return new WP_REST_Response(
			array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	// Return the forwarded response.
	return new WP_REST_Response( $response, 200 );
}
add_filter( 'rest_pre_dispatch', 'extrachill_api_route_affinity_dispatch', 10, 3 );
