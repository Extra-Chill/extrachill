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
 * @return array|WP_Error Owner response or transport error.
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
		return ec_cross_site_rest_request(
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
