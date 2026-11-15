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
 * Keys come from the Network-provisioned keyring by default. The constant
 * below is an optional deployment override. The source integration
 * independently registers the matching source grant and selects its active key
 * through the Network contract.
 *
 * @return array<string,mixed>|null Target grant, or null when unavailable.
 */
function extrachill_events_priority_boost_target_grant(): ?array {
	$source_site_id = (int) ec_get_blog_id( 'shop' );
	$target_site_id = (int) ec_get_blog_id( 'events' );
	$target_url     = (string) ec_get_site_url( 'events' );
	$target_host    = $target_url ? wp_parse_url( $target_url, PHP_URL_HOST ) : '';
	$keys           = defined( 'EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ASSERTION_KEYS' )
		? constant( 'EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ASSERTION_KEYS' )
		: ( function_exists( 'ec_cross_site_service_assertion_service_keys' )
			? ec_cross_site_service_assertion_service_keys( EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ID )['keys']
			: array() );

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
 * Check Network-verified service authority for the active ability request.
 *
 * Network binds the active REST request, verifies the assertion before any
 * permission callback runs, and matches its claims plus exact method and route
 * against the target grant registered above.
 */
function extrachill_events_priority_boost_has_verified_service_authority(): bool {
	return function_exists( 'ec_cross_site_request_has_service_grant' )
		&& ec_cross_site_request_has_service_grant( EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ID, EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_SCOPE );
}
