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
 * Transport timeout, in seconds, for decorative public cross-site reads.
 *
 * These reads only decorate pages, so a slow owner must fail fast instead of
 * holding a PHP-FPM worker for the network default (30s for reads).
 */
const EXTRACHILL_BLOG_CROSS_SITE_READ_TIMEOUT = 3;

/**
 * Seconds a successful public cross-site read stays cached.
 */
const EXTRACHILL_BLOG_CROSS_SITE_SUCCESS_TTL = 600;

/**
 * Seconds a failed public cross-site read stays negative-cached.
 */
const EXTRACHILL_BLOG_CROSS_SITE_FAILURE_TTL = 60;

/**
 * Sentinel key marking a negative-cached transport failure.
 */
const EXTRACHILL_BLOG_CROSS_SITE_FAILURE_SENTINEL = 'extrachill_blog_cross_site_failure';

/**
 * Cache public cross-site reads by destination and complete request inputs.
 *
 * Requests use a short explicit timeout (EXTRACHILL_BLOG_CROSS_SITE_READ_TIMEOUT)
 * unless the caller set `$args['timeout']`. Successes are cached for ten minutes.
 * Failures are negative-cached briefly (one minute) under the same key as a
 * sentinel and replayed as a WP_Error. Without the negative cache, a saturated
 * PHP-FPM pool made every render retry each loopback, and those retries queued
 * behind the same pool, which amplified pool starvation. The short TTL still
 * lets a recovered owner come back quickly.
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
	if ( is_array( $cached ) && isset( $cached[ EXTRACHILL_BLOG_CROSS_SITE_FAILURE_SENTINEL ] ) ) {
		$failure = $cached[ EXTRACHILL_BLOG_CROSS_SITE_FAILURE_SENTINEL ];
		return new WP_Error(
			is_array( $failure ) && ! empty( $failure['code'] ) ? (string) $failure['code'] : 'extrachill_blog_cross_site_failure_cached',
			is_array( $failure ) && isset( $failure['message'] ) ? (string) $failure['message'] : __( 'Cross-site activity is temporarily unavailable.', 'extrachill-blog' )
		);
	}
	if ( false !== $cached ) {
		return $cached;
	}

	if ( ! isset( $args['timeout'] ) ) {
		$args['timeout'] = EXTRACHILL_BLOG_CROSS_SITE_READ_TIMEOUT;
	}

	$result = ec_cross_site_rest_request( $site_key, $method, $route, $args );
	if ( is_wp_error( $result ) ) {
		set_transient(
			$cache_key,
			array(
				EXTRACHILL_BLOG_CROSS_SITE_FAILURE_SENTINEL => array(
					'code'    => (string) $result->get_error_code(),
					'message' => (string) $result->get_error_message(),
				),
			),
			EXTRACHILL_BLOG_CROSS_SITE_FAILURE_TTL
		);
		return $result;
	}

	set_transient( $cache_key, $result, EXTRACHILL_BLOG_CROSS_SITE_SUCCESS_TTL );
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
