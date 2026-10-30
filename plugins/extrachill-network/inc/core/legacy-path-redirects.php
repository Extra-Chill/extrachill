<?php
/**
 * Legacy path redirects.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'ec_handle_legacy_path_redirects', 1 );

/**
 * Handle legacy path redirects on the main site.
 *
 * Forwards legacy /festival-wire/* paths to the wire subsite, validating the
 * requested slug against wire before redirecting so stale or duplicate slugs
 * fall through to a natural 404 instead of forwarding to a guaranteed 404.
 *
 * @return void
 */
function ec_handle_legacy_path_redirects() {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( null === $main_blog_id || (int) get_current_blog_id() !== (int) $main_blog_id ) {
		return;
	}

	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( ! is_string( $path ) ) {
		return;
	}

	if ( ! preg_match( '#^/festival-wire(?:/|$)#', $path ) ) {
		return;
	}

	$wire_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'wire' ) : null;
	if ( ! $wire_url ) {
		return;
	}

	// Resolve the festival-wire post slug from the path so we can validate it
	// against the wire subsite before forwarding. A blind forward 301s stale or
	// duplicate slugs (e.g. a "-2" suffixed slug Google has indexed) verbatim to
	// wire, which then returns a 404 because the real post lives at the clean
	// slug. See extrachill-seo#2 for the broader Festival Wire SEO leak.
	$base   = '/festival-wire';
	$suffix = substr( $path, strlen( $base ) );
	$suffix = trim( $suffix, '/' );
	$slug   = '';
	if ( '' !== $suffix ) {
		// The post slug is the last path segment (ignore any nested archive paths).
		$segments = explode( '/', $suffix );
		$slug     = sanitize_title( end( $segments ) );
	}

	// Archive root (/festival-wire or /festival-wire/) has no slug to validate;
	// forward as-is so the wire archive handles it.
	if ( '' === $slug ) {
		wp_safe_redirect( $wire_url . '/festival-wire/', 301 );
		exit;
	}

	$target_slug = ec_resolve_festival_wire_slug( $slug );

	// Neither the requested slug nor a cleaned variant resolves to a published
	// post on wire. Fall through and let the request 404 naturally rather than
	// forwarding garbage to a guaranteed 404 on the subsite.
	if ( null === $target_slug ) {
		return;
	}

	wp_safe_redirect( trailingslashit( $wire_url . '/festival-wire/' . $target_slug ), 301 );
	exit;
}

/**
 * Resolve a festival-wire request slug to a real published slug on the wire subsite.
 *
 * Checks the slug as-given first. If that fails and the slug carries a trailing
 * numeric "-N" disambiguation suffix (e.g. "-2", "-3"), retries with the suffix
 * stripped. This recovers stale/duplicate slugs that 404 on wire while the clean
 * slug exists.
 *
 * @param string $slug Requested festival-wire post slug (already sanitized).
 * @return string|null Valid published slug on wire, or null if nothing resolves.
 */
function ec_resolve_festival_wire_slug( $slug ) {
	$wire_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'wire' ) : null;
	if ( null === $wire_blog_id ) {
		return null;
	}

	$candidates = array( $slug );

	// Strip a trailing numeric disambiguation suffix (e.g. "...-tradition-2").
	$clean = preg_replace( '/-\d+$/', '', $slug );
	if ( is_string( $clean ) && '' !== $clean && $clean !== $slug ) {
		$candidates[] = $clean;
	}

	$resolved = null;

	switch_to_blog( $wire_blog_id );
	foreach ( $candidates as $candidate ) {
		$post = get_page_by_path( $candidate, OBJECT, 'festival_wire' );
		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
			$resolved = $post->post_name;
			break;
		}
	}
	restore_current_blog();

	return $resolved;
}
