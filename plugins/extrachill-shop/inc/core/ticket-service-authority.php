<?php
/** Bounded Shop source authority for the three Events ticket service operations. */
defined( 'ABSPATH' ) || exit;

function extrachill_shop_ticket_service_operations() {
	return array( 'issue-ticket-passes', 'get-ticket-order-status', 'revoke-ticket-passes' );
}

function extrachill_shop_register_ticket_source_grants( array $grants ) {
	$keys = defined( 'EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_KEYS' ) ? EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_KEYS : array();
	$active = defined( 'EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_ACTIVE_KEY_ID' ) ? EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_ACTIVE_KEY_ID : '';
	$keys = apply_filters( 'extrachill_shop_ticket_service_assertion_keys', $keys );
	$active = apply_filters( 'extrachill_shop_ticket_service_assertion_active_key_id', $active );
	$host = wp_parse_url( (string) ec_get_site_url( 'events' ), PHP_URL_HOST );
	if ( ! is_array( $keys ) || ! is_string( $active ) || ! isset( $keys[ $active ] ) || ! $host || ! ec_get_blog_id( 'shop' ) || ! ec_get_blog_id( 'events' ) ) { return $grants; }
	foreach ( extrachill_shop_ticket_service_operations() as $operation ) {
		$grants[] = array( 'service_id' => 'extrachill.events.tickets', 'scope' => 'extrachill/events:tickets', 'source_site_id' => (int) ec_get_blog_id( 'shop' ), 'target_site_id' => (int) ec_get_blog_id( 'events' ), 'target_host' => strtolower( $host ), 'method' => 'POST', 'route' => '/wp-abilities/v1/abilities/extrachill/' . $operation . '/run', 'active_key_id' => $active, 'keys' => $keys );
	}
	return $grants;
}
add_filter( 'ec_cross_site_service_assertion_source_grants', 'extrachill_shop_register_ticket_source_grants' );

function extrachill_shop_execute_ticket_owner_ability( $operation, array $input ) {
	if ( ! in_array( $operation, extrachill_shop_ticket_service_operations(), true ) || ! function_exists( 'ec_cross_site_rest_request_http' ) ) { return new WP_Error( 'owner_runtime_unavailable', 'Ticket service unavailable.', array( 'status' => 503 ) ); }
	return ec_cross_site_rest_request_http( 'events', 'POST', '/wp-abilities/v1/abilities/extrachill/' . $operation . '/run', array( 'user_id' => 0, 'body' => array( 'input' => $input ), 'service_assertion' => array( 'service_id' => 'extrachill.events.tickets', 'scope' => 'extrachill/events:tickets' ) ) );
}
