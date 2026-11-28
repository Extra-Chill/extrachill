<?php
/** Bounded Shop-to-Events ticket service authority. */
defined( 'ABSPATH' ) || exit;

const EXTRACHILL_EVENTS_TICKET_SERVICE_ID    = 'extrachill.events.tickets';
const EXTRACHILL_EVENTS_TICKET_SERVICE_SCOPE = 'extrachill/events:tickets';

function extrachill_events_ticket_service_routes(): array {
	return array_map( static function ( $ability ) { return '/wp-abilities/v1/abilities/extrachill/' . $ability . '/run';
	}, array( 'issue-ticket-passes', 'get-ticket-order-status', 'revoke-ticket-passes', 'restore-ticket-passes', 'record-checkout-ticket-sales', 'notify-commerce-seller', 'resend-ticket-pass-emails' ) );
}

function extrachill_events_ticket_target_grants(): array {
	// Network-provisioned keys by default; the deployment constant remains an optional override.
	$auto   = function_exists( 'ec_cross_site_service_assertion_service_keys' ) ? ec_cross_site_service_assertion_service_keys( EXTRACHILL_EVENTS_TICKET_SERVICE_ID )['keys'] : array();
	$keys   = apply_filters( 'extrachill_events_ticket_service_assertion_keys', defined( 'EXTRACHILL_EVENTS_TICKET_SERVICE_ASSERTION_KEYS' ) ? EXTRACHILL_EVENTS_TICKET_SERVICE_ASSERTION_KEYS : $auto );
	$config = array(
		'source_site_id' => (int) ec_get_blog_id( 'shop' ),
		'target_site_id' => (int) ec_get_blog_id( 'events' ),
		'target_host'    => (string) wp_parse_url( ec_get_site_url( 'events' ), PHP_URL_HOST ),
		'keys'           => $keys,
	);
	$grant  = extrachill_events_priority_boost_build_target_grant( $config );
	if ( ! $grant ) {
		return array(); }
	$grant['service_id'] = EXTRACHILL_EVENTS_TICKET_SERVICE_ID;
	$grant['scope']      = EXTRACHILL_EVENTS_TICKET_SERVICE_SCOPE;
	$grants              = array();
	foreach ( extrachill_events_ticket_service_routes() as $route ) {
		$grant['route'] = $route;
		$grants[]       = $grant;
	}
	return $grants;
}
add_filter( 'ec_cross_site_service_assertion_target_grants', static function ( array $grants ): array { return array_merge( $grants, extrachill_events_ticket_target_grants() );
} );

/** Check Network-verified ticket service authority for the active ability request. */
function extrachill_events_ticket_has_service_authority(): bool {
	return function_exists( 'ec_cross_site_request_has_service_grant' )
		&& ec_cross_site_request_has_service_grant( EXTRACHILL_EVENTS_TICKET_SERVICE_ID, EXTRACHILL_EVENTS_TICKET_SERVICE_SCOPE );
}
