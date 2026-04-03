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
	// Don't intercept if already forwarded (prevent infinite loops).
	if ( $request->get_header( 'X-EC-Forwarded' ) ) {
		return $result;
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
	$target_site = ec_get_route_site_affinity( $route );

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

	// Build request args.
	$args = array(
		'headers' => array(
			'X-EC-Forwarded' => '1', // Prevent infinite forwarding loops.
		),
	);

	// Forward query parameters.
	$query_params = $request->get_query_params();
	if ( ! empty( $query_params ) ) {
		$args['query'] = $query_params;
	}

	// Forward body for write methods.
	if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
		$body = $request->get_json_params();
		if ( ! empty( $body ) ) {
			$args['body'] = $body;
		} else {
			// Fall back to body params (form-encoded).
			$body_params = $request->get_body_params();
			if ( ! empty( $body_params ) ) {
				$args['body'] = $body_params;
			}
		}
	}

	// Forward the current user context.
	$current_user_id = get_current_user_id();
	if ( $current_user_id > 0 ) {
		$args['user_id'] = $current_user_id;
	}

	$response = ec_cross_site_rest_request( $target_site, $method, $path, $args );

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
