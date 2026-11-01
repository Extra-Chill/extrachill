<?php
/**
 * Cross-site Ability transport helpers.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute a read-only Ability on its owning site.
 *
 * Site-specific plugins require a loopback request so the owner site receives
 * its normal plugin bootstrap. The temporary transport override must never
 * escape this request.
 *
 * @param string $site_key     Network site key.
 * @param string $ability_name Registered Ability name.
 * @param array  $input        Ability input.
 * @return array|string|WP_Error Owner response or transport error.
 */
function extrachill_blog_request_cross_site_ability( $site_key, $ability_name, $input = array() ) {
	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return new WP_Error( 'extrachill_blog_cross_site_unavailable', __( 'Cross-site activity is unavailable.', 'extrachill-blog' ) );
	}

	$force_loopback = static function () {
		return true;
	};

	add_filter( 'ec_cross_site_use_http_loopback', $force_loopback, 10 );
	try {
		return extrachill_blog_cached_cross_site_request(
			(string) $site_key,
			'GET',
			'/wp-abilities/v1/abilities/' . (string) $ability_name . '/run',
			array(
				'query' => array( 'input' => $input ),
			)
		);
	} finally {
		remove_filter( 'ec_cross_site_use_http_loopback', $force_loopback, 10 );
	}
}

/**
 * Cache public cross-site reads by destination and complete request inputs.
 *
 * Errors are deliberately not cached so a transport failure can recover promptly.
 *
 * @param string $site_key Site key.
 * @param string $method   HTTP method.
 * @param string $route    REST route.
 * @param array  $args     Request arguments.
 * @return array|string|WP_Error Owner response or transport error.
 */
function extrachill_blog_cached_cross_site_request( $site_key, $method, $route, $args = array() ) {
	$cache_key = 'ec_blog_public_read_' . md5( (string) wp_json_encode( array( $site_key, $method, $route, $args ) ) );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$result = ec_cross_site_rest_request( $site_key, $method, $route, $args );
	if ( ! is_wp_error( $result ) ) {
		set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
	}
	return $result;
}

/**
 * Validate the shared versioned envelope used by activity owners.
 *
 * @param mixed $result Owner response.
 * @phpstan-assert-if-true array{schema_version: string, items: array} $result
 * @return bool
 */
function extrachill_blog_is_activity_projection( $result ) {
	return ! is_wp_error( $result )
		&& is_array( $result )
		&& array( 'schema_version', 'items' ) === array_keys( $result )
		&& '1' === $result['schema_version']
		&& is_array( $result['items'] );
}
