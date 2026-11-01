<?php
/**
 * Route MCP transport requests from the main-site landing page to auth.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Run before WordPress's redirect_canonical callback (template_redirect, 10).
add_action( 'template_redirect', 'ec_redirect_mcp_transport_to_auth', 0 );

/**
 * Redirect MCP transport requests to the auth site's MCP endpoint.
 *
 * @return void
 */
function ec_redirect_mcp_transport_to_auth() {
	$main_blog_id = ec_get_blog_id( 'main' );
	if ( null === $main_blog_id || (int) get_current_blog_id() !== (int) $main_blog_id ) {
		return;
	}

	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	$target      = ec_get_mcp_transport_redirect_url(
		$request_uri,
		$_SERVER['REQUEST_METHOD'] ?? 'GET',
		$_SERVER['HTTP_ACCEPT'] ?? ''
	);
	if ( ! $target ) {
		return;
	}

	wp_safe_redirect( $target, 307 );
	exit;
}

/**
 * Resolve a main-site MCP transport request to its auth endpoint URL.
 *
 * @param string $request_uri Request URI, including any query string.
 * @param string $method      HTTP request method.
 * @param string $accept      HTTP Accept header.
 * @return string|null Redirect target, or null when this is not MCP transport.
 */
function ec_get_mcp_transport_redirect_url( $request_uri, $method, $accept ) {
	$path = wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( ! is_string( $path ) || ! in_array( $path, array( '/mcp', '/mcp/' ), true ) ) {
		return null;
	}

	$method       = strtoupper( (string) $method );
	$is_transport = in_array( $method, array( 'POST', 'DELETE' ), true )
		|| ( 'GET' === $method && false !== stripos( (string) $accept, 'text/event-stream' ) );
	if ( ! $is_transport ) {
		return null;
	}

	$auth_url = ec_get_site_url( 'auth' );
	if ( ! $auth_url ) {
		return null;
	}

	$query  = wp_parse_url( $request_uri, PHP_URL_QUERY );
	$target = untrailingslashit( $auth_url ) . '/wp-json/extrachill-mcp/v1/mcp';
	return is_string( $query ) && '' !== $query ? $target . '?' . $query : $target;
}
