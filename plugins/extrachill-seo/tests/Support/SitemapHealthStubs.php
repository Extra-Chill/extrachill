<?php
/** Lightweight multisite and HTTP doubles for sitemap health tests. */

function is_multisite() {
	return true;
}

function get_sites( $args ) {
	return array( 1, 3, 7 );
}

function switch_to_blog( $id ) {
	$GLOBALS['ec_seo_test_blog_stack'][] = get_current_blog_id();
	$GLOBALS['ec_seo_test_blog_id'] = $id;
}

function restore_current_blog() {
	$GLOBALS['ec_seo_test_blog_id'] = array_pop( $GLOBALS['ec_seo_test_blog_stack'] );
}

function get_home_url( $id, $path = '' ) {
	$homes = array( 1 => 'https://extrachill.com', 3 => 'https://shop.extrachill.com', 7 => 'https://events.extrachill.com' );
	return $homes[ $id ] . $path;
}

function get_option( $name ) {
	return $GLOBALS['ec_seo_test_blog_public'] ?? 1;
}

function wp_sitemaps_get_server() {
	return new class() {
		public function sitemaps_enabled() {
			return $GLOBALS['ec_seo_test_sitemaps_enabled'] ?? true;
		}
	};
}

function wp_get_sitemap_providers() {
	throw new \LogicException( 'Request-local providers must not be read.' );
}

function wp_remote_get( $url, $args ) {
	return apply_filters( 'pre_http_request', false, $args, $url );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
