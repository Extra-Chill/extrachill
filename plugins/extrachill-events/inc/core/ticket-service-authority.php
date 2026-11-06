<?php
/** Bounded Shop-to-Events ticket service authority. */
defined( 'ABSPATH' ) || exit;

function extrachill_events_ticket_service_routes(): array {
	return array_map( static function ( $ability ) { return '/wp-abilities/v1/abilities/extrachill/' . $ability . '/run';
	}, array( 'issue-ticket-passes', 'get-ticket-order-status', 'revoke-ticket-passes' ) );
}

function extrachill_events_ticket_target_grants(): array {
	$keys   = apply_filters( 'extrachill_events_ticket_service_assertion_keys', defined( 'EXTRACHILL_EVENTS_TICKET_SERVICE_ASSERTION_KEYS' ) ? EXTRACHILL_EVENTS_TICKET_SERVICE_ASSERTION_KEYS : array() );
	$config = array(
		'source_site_id' => (int) ec_get_blog_id( 'shop' ),
		'target_site_id' => (int) ec_get_blog_id( 'events' ),
		'target_host'    => (string) wp_parse_url( ec_get_site_url( 'events' ), PHP_URL_HOST ),
		'keys'           => $keys,
	);
	$grant  = extrachill_events_priority_boost_build_target_grant( $config );
	if ( ! $grant ) {
		return array(); }
	$grant['service_id'] = 'extrachill.events.tickets';
	$grant['scope']      = 'extrachill/events:tickets';
	$grants              = array();
	foreach ( extrachill_events_ticket_service_routes() as $route ) {
		$grant['route'] = $route;
		$grants[]       = $grant;
	}
	return $grants;
}
add_filter( 'ec_cross_site_service_assertion_target_grants', static function ( array $grants ): array { return array_merge( $grants, extrachill_events_ticket_target_grants() );
} );

function extrachill_events_ticket_active_request( $request = null, bool $replace = false ) {
	static $active = null;
	if ( $replace ) {
		$active = $request; }
	return $active;
}
add_filter( 'rest_request_before_callbacks', static function ( $response, $handler, $request ) {
	if ( ! is_wp_error( $response ) && 'POST' === $request->get_method() && in_array( $request->get_route(), extrachill_events_ticket_service_routes(), true ) ) {
		extrachill_events_ticket_active_request( $request, true );
	}
	return $response;
}, 10, 3 );
add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
	if ( extrachill_events_ticket_active_request() === $request ) {
		extrachill_events_ticket_active_request( null, true ); }
	return $response;
}, 20, 3 );

function extrachill_events_ticket_has_service_authority(): bool {
	$request = extrachill_events_ticket_active_request();
	if ( ! $request || ! function_exists( 'ec_cross_site_verified_service_context' ) || 'POST' !== $request->get_method() ) {
		return false; }
	$claims = ec_cross_site_verified_service_context( $request );
	foreach ( extrachill_events_ticket_target_grants() as $grant ) {
		if ( $request->get_route() === $grant['route'] && extrachill_events_priority_boost_service_claims_match( $claims, $grant ) ) {
			return true; }
	}
	return false;
}
