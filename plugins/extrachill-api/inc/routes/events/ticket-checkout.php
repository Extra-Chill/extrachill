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
			// Not required here: a missing answer reaches Shop, which returns
			// its dedicated terms_required error.
			'terms_accepted'     => array( 'type' => 'boolean' ),
			'venue_show_updates' => array( 'type' => 'boolean' ),
			'turnstile_response' => array( 'type' => 'string' ),
			// Which ticket type, and an optional discount or access code (extrachill#1716).
			'ticket_type'        => array(
				'type'      => 'string',
				'maxLength' => 40,
			),
			'code'               => array(
				'type'      => 'string',
				'maxLength' => 32,
			),
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
	$input = array(
		'event'              => (int) $request['event'],
		'quantity'           => (int) $request['quantity'],
		'name'               => sanitize_text_field( $request['name'] ),
		'email'              => trim( $request['email'] ),
		'terms_accepted'     => true === $request->get_param( 'terms_accepted' ),
		'venue_show_updates' => true === $request->get_param( 'venue_show_updates' ),
	);
	foreach ( array( 'ticket_type', 'code' ) as $key ) {
		$value = $request->get_param( $key );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$input[ $key ] = sanitize_text_field( $value );
		}
	}
	$result = $ability->execute( $input );
	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}
