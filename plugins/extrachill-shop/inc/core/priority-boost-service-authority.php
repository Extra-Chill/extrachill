<?php
/**
 * Bounded source authority for paid event priority fulfillment.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ID    = 'extrachill.events.priority-boost';
const EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_SCOPE = 'extrachill/events:priority-boost';
const EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ROUTE = '/wp-abilities/v1/abilities/extrachill/grant-event-priority-boost/run';

/**
 * Build the exact source grant from Shop-owned configuration.
 *
 * @param array $config Product configuration.
 * @return array|null Network grant, or null when incomplete.
 */
function extrachill_shop_priority_boost_build_source_grant( array $config ) {
	$required = array( 'source_site_id', 'target_site_id', 'target_host', 'active_key_id', 'keys' );
	foreach ( $required as $field ) {
		if ( ! isset( $config[ $field ] ) ) {
			return null;
		}
	}

	$active_key_id = (string) $config['active_key_id'];
	if (
		(int) $config['source_site_id'] < 1
		|| (int) $config['target_site_id'] < 1
		|| '' === trim( (string) $config['target_host'] )
		|| '' === $active_key_id
		|| ! is_array( $config['keys'] )
		|| ! isset( $config['keys'][ $active_key_id ] )
	) {
		return null;
	}

	return array(
		'service_id'     => EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ID,
		'scope'          => EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_SCOPE,
		'source_site_id' => (int) $config['source_site_id'],
		'target_site_id' => (int) $config['target_site_id'],
		'target_host'    => strtolower( trim( (string) $config['target_host'] ) ),
		'method'         => 'POST',
		'route'          => EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ROUTE,
		'active_key_id'  => $active_key_id,
		'keys'           => $config['keys'],
	);
}

/**
 * Resolve the source grant from deployment-provided key configuration.
 *
 * @return array|null Source grant, or null when unavailable.
 */
function extrachill_shop_priority_boost_source_grant() {
	if ( ! function_exists( 'ec_get_blog_id' ) || ! function_exists( 'ec_get_site_url' ) ) {
		return null;
	}

	$source_site_id = (int) ec_get_blog_id( 'shop' );
	$target_site_id = (int) ec_get_blog_id( 'events' );
	$target_url     = (string) ec_get_site_url( 'events' );
	$target_host    = $target_url ? wp_parse_url( $target_url, PHP_URL_HOST ) : '';
	$keys           = defined( 'EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ASSERTION_KEYS' )
		? constant( 'EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ASSERTION_KEYS' )
		: array();
	$active_key_id  = defined( 'EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ASSERTION_ACTIVE_KEY_ID' )
		? constant( 'EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ASSERTION_ACTIVE_KEY_ID' )
		: '';

	/** Filters deployment-provided priority boost assertion keys. */
	$keys = apply_filters( 'extrachill_shop_priority_boost_service_assertion_keys', $keys );
	/** Filters the deployment-selected active priority boost assertion key ID. */
	$active_key_id = apply_filters( 'extrachill_shop_priority_boost_service_assertion_active_key_id', $active_key_id );

	return extrachill_shop_priority_boost_build_source_grant(
		array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
			'target_host'    => is_string( $target_host ) ? $target_host : '',
			'active_key_id'  => is_string( $active_key_id ) ? $active_key_id : '',
			'keys'           => is_array( $keys ) ? $keys : array(),
		)
	);
}

/**
 * Register the Shop-owned source grant with Network.
 *
 * @param array $grants Registered source grants.
 * @return array Filtered source grants.
 */
function extrachill_shop_register_priority_boost_source_grant( array $grants ) {
	$grant = extrachill_shop_priority_boost_source_grant();
	if ( null !== $grant ) {
		$grants[] = $grant;
	}

	return $grants;
}
add_filter( 'ec_cross_site_service_assertion_source_grants', 'extrachill_shop_register_priority_boost_source_grant' );

/**
 * Execute the exact Events priority boost operation with service authority.
 *
 * @param array $input Validated ability input.
 * @return array|WP_Error Owner response.
 */
function extrachill_shop_execute_priority_boost_owner_ability( array $input ) {
	if ( ! function_exists( 'ec_cross_site_rest_request_http' ) ) {
		return new WP_Error( 'owner_runtime_unavailable', __( 'The owning site runtime is unavailable.', 'extrachill-shop' ), array( 'status' => 503 ) );
	}

	return ec_cross_site_rest_request_http(
		'events',
		'POST',
		EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ROUTE,
		array(
			'user_id'           => 0,
			'body'              => array( 'input' => $input ),
			'service_assertion' => array(
				'service_id' => EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ID,
				'scope'      => EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_SCOPE,
			),
		)
	);
}
