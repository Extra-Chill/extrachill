<?php
/**
 * Bounded service authority for paid event priority fulfillment.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ID    = 'extrachill.events.priority-boost';
const EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_SCOPE = 'extrachill/events:priority-boost';
const EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ROUTE = '/wp-abilities/v1/abilities/extrachill/grant-event-priority-boost/run';

/**
 * Build the exact target grant from Events-owned configuration.
 *
 * @param array<string,mixed> $config Product configuration.
 * @return array<string,mixed>|null Network grant, or null when incomplete.
 */
function extrachill_events_priority_boost_build_target_grant( array $config ): ?array {
	$required = array( 'source_site_id', 'target_site_id', 'target_host', 'keys' );
	foreach ( $required as $field ) {
		if ( ! isset( $config[ $field ] ) ) {
			return null;
		}
	}

	if ( (int) $config['source_site_id'] < 1 || (int) $config['target_site_id'] < 1 || '' === trim( (string) $config['target_host'] ) || ! is_array( $config['keys'] ) || empty( $config['keys'] ) ) {
		return null;
	}

	return array(
		'service_id'     => EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ID,
		'scope'          => EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_SCOPE,
		'source_site_id' => (int) $config['source_site_id'],
		'target_site_id' => (int) $config['target_site_id'],
		'target_host'    => strtolower( trim( (string) $config['target_host'] ) ),
		'method'         => 'POST',
		'route'          => EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ROUTE,
		'keys'           => $config['keys'],
	);
}

/**
 * Resolve the target grant without embedding assertion secrets in source.
 *
 * Deployment configuration supplies key IDs and secrets through the constant
 * below. The source integration independently registers the matching source
 * grant and selects its active key through the Network contract.
 *
 * @return array<string,mixed>|null Target grant, or null when unavailable.
 */
function extrachill_events_priority_boost_target_grant(): ?array {
	if ( ! function_exists( 'ec_get_blog_id' ) || ! function_exists( 'ec_get_site_url' ) ) {
		return null;
	}

	$source_site_id = (int) ec_get_blog_id( 'shop' );
	$target_site_id = (int) ec_get_blog_id( 'events' );
	$target_url     = (string) ec_get_site_url( 'events' );
	$target_host    = $target_url ? wp_parse_url( $target_url, PHP_URL_HOST ) : '';
	$keys           = defined( 'EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ASSERTION_KEYS' )
		? constant( 'EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ASSERTION_KEYS' )
		: array();

	/**
	 * Filters deployment-provided priority boost assertion keys.
	 *
	 * @param array<string,string> $keys Key IDs mapped to assertion secrets.
	 */
	$keys = apply_filters( 'extrachill_events_priority_boost_service_assertion_keys', $keys );

	return extrachill_events_priority_boost_build_target_grant(
		array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
			'target_host'    => is_string( $target_host ) ? $target_host : '',
			'keys'           => $keys,
		)
	);
}

/**
 * Register the Events-owned target grant with Network.
 *
 * @param array<int,array<string,mixed>> $grants Registered target grants.
 * @return array<int,array<string,mixed>> Filtered target grants.
 */
function extrachill_events_register_priority_boost_target_grant( array $grants ): array {
	$grant = extrachill_events_priority_boost_target_grant();
	if ( null !== $grant ) {
		$grants[] = $grant;
	}

	return $grants;
}
add_filter( 'ec_cross_site_service_assertion_target_grants', 'extrachill_events_register_priority_boost_target_grant' );

/**
 * Check verified claims against the exact Events-owned grant.
 *
 * Network has already verified the request method, route, query, body,
 * signature, lifetime, key, target, and single-use nonce before exposing these
 * claims. Events only applies its product authorization policy here.
 *
 * @param array<string,mixed> $claims Verified Network claims.
 * @param array<string,mixed> $grant  Events target grant.
 */
function extrachill_events_priority_boost_service_claims_match( array $claims, array $grant ): bool {
	foreach ( array( 'service_id', 'scope', 'source_site_id', 'target_site_id', 'target_host' ) as $field ) {
		if ( ! array_key_exists( $field, $claims ) || (string) $claims[ $field ] !== (string) $grant[ $field ] ) {
			return false;
		}
	}

	return true;
}

/**
 * Apply Events policy to one request carrying Network-verified claims.
 *
 * @param string              $method  HTTP method.
 * @param string              $route   Exact REST route.
 * @param array<string,mixed> $claims  Verified Network claims.
 * @param array<string,mixed> $grant   Events target grant.
 */
function extrachill_events_priority_boost_service_request_is_authorized( string $method, string $route, array $claims, array $grant ): bool {
	return 'POST' === $method
		&& EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ROUTE === $route
		&& extrachill_events_priority_boost_service_claims_match( $claims, $grant );
}

/**
 * Store or read the exact REST request currently executing the ability.
 *
 * @param mixed $request Exact REST request, or null.
 * @param bool  $replace Whether to replace the active request.
 * @return mixed Active REST request, or null.
 */
function extrachill_events_priority_boost_active_rest_request( $request = null, bool $replace = false ) {
	static $active_request = null;

	if ( $replace ) {
		$active_request = $request;
	}

	return $active_request;
}

/**
 * Bind only the exact ability run request during its callback lifecycle.
 *
 * @param mixed $response Current REST response.
 * @param array $handler  Matched route handler.
 * @param mixed $request  Exact REST request.
 * @return mixed Unchanged REST response.
 */
function extrachill_events_bind_priority_boost_rest_request( $response, array $handler, $request ) {
	unset( $handler );

	if ( ! is_wp_error( $response ) && 'POST' === $request->get_method() && EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ROUTE === $request->get_route() ) {
		extrachill_events_priority_boost_active_rest_request( $request, true );
	}

	return $response;
}
add_filter( 'rest_request_before_callbacks', 'extrachill_events_bind_priority_boost_rest_request', 10, 3 );

/**
 * Clear the active request after REST has finished permission header checks.
 *
 * @param mixed $response Current REST response.
 * @param mixed $server   REST server.
 * @param mixed $request  Exact REST request.
 * @return mixed Unchanged REST response.
 */
function extrachill_events_release_priority_boost_rest_request( $response, $server, $request ) {
	unset( $server );

	if ( extrachill_events_priority_boost_active_rest_request() === $request ) {
		extrachill_events_priority_boost_active_rest_request( null, true );
	}

	return $response;
}
add_filter( 'rest_post_dispatch', 'extrachill_events_release_priority_boost_rest_request', 20, 3 );

/** Check exact target-side Network claims for the active ability request. */
function extrachill_events_priority_boost_has_verified_service_authority(): bool {
	$request = extrachill_events_priority_boost_active_rest_request();
	$grant   = extrachill_events_priority_boost_target_grant();

	if ( null === $request || null === $grant || ! function_exists( 'ec_cross_site_verified_service_context' ) ) {
		return false;
	}

	$claims = ec_cross_site_verified_service_context( $request );

	return is_array( $claims )
		&& extrachill_events_priority_boost_service_request_is_authorized( $request->get_method(), $request->get_route(), $claims, $grant );
}
