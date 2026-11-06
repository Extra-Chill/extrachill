<?php
/** Public ticket checkout admission; Shop owns payments. */
defined( 'ABSPATH' ) || exit;
add_action( 'extrachill_api_register_routes', 'extrachill_api_register_ticket_checkout_route' );
function extrachill_api_register_ticket_checkout_route() {
	register_rest_route( 'extrachill/v1', '/events/tickets/checkout', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_ticket_checkout',
		'permission_callback' => 'extrachill_api_ticket_checkout_permission',
		'args'                => array(
			'event'              => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
			'quantity'           => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 10,
				'required' => true,
			),
			'name'               => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 200,
			),
			'email'              => array(
				'type'     => 'string',
				'format'   => 'email',
				'required' => true,
			),
			'turnstile_response' => array( 'type' => 'string' ),
		),
	) );
}
function extrachill_api_ticket_checkout_permission( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_turnstile_check_request' ) ) {
		return new WP_Error( 'ticket_security_unavailable', 'Security verification unavailable.', array( 'status' => 503 ) ); }
	$result = ec_turnstile_check_request( $request );
	if ( is_wp_error( $result ) ) {
		return $result; }
	return extrachill_api_check_public_write_rate_limit( $request, 'ticket-checkout', (int) apply_filters( 'extrachill_api_ticket_checkout_rate_limit', 5 ) );
}
function extrachill_api_ticket_checkout( WP_REST_Request $request ) {
	// The protected route runs on Shop so the hidden ability cannot be invoked
	// through an unprotected remote core REST runner.
	$ability = wp_get_ability( 'extrachill/start-ticket-checkout' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'Ticket checkout unavailable.', array( 'status' => 503 ) ); }
	$result = $ability->execute( array(
		'event'    => (int) $request['event'],
		'quantity' => (int) $request['quantity'],
		'name'     => sanitize_text_field( $request['name'] ),
		'email'    => trim( $request['email'] ),
	) );
	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}
