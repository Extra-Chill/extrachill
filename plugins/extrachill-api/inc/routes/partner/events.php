<?php
/**
 * Partner Events Endpoint (Extra-Chill/extrachill#1802)
 *
 * GET /extrachill/v1/partner/events?limit=1..50
 * Header: X-Extrachill-Partner-Key
 *
 * Thin transport over extrachill/get-partner-venue-events. The key selects
 * the venue; this adapter only moves the header into the ability, maps
 * ability errors to HTTP, and sets the private five-minute cache contract.
 * Runs on the events site via route affinity (the ability is registered only
 * there).
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_API_PARTNER_EVENTS_ABILITY = 'extrachill/get-partner-venue-events';
const EXTRACHILL_API_PARTNER_KEY_HEADER     = 'X-Extrachill-Partner-Key';

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_partner_events_route' );

/**
 * Register the keyed partner events endpoint.
 */
function extrachill_api_register_partner_events_route() {
	register_rest_route(
		'extrachill/v1',
		'/partner/events',
		array(
			'methods'               => WP_REST_Server::READABLE,
			'callback'              => 'extrachill_api_handle_partner_events',
			// The partner key is the credential; the ability verifies it and
			// every unusable key becomes a 401 extrachill_partner_key_invalid.
			'permission_callback'   => '__return_true',
			'args'                  => array(
				'limit' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
					'description' => 'Maximum upcoming events, 1-50.',
				),
			),
			'_extrachill_abilities' => array( EXTRACHILL_API_PARTNER_EVENTS_ABILITY ),
		)
	);
}

/**
 * Return the partner events document for the key's venue.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_partner_events( WP_REST_Request $request ) {
	$rate_limit = extrachill_api_check_public_read_rate_limit( $request, 'partner_events', 60 );
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$key = trim( (string) $request->get_header( EXTRACHILL_API_PARTNER_KEY_HEADER ) );
	if ( '' === $key ) {
		return extrachill_api_partner_key_invalid();
	}

	$ability = wp_get_abilities()[ EXTRACHILL_API_PARTNER_EVENTS_ABILITY ] ?? null;
	if ( ! $ability instanceof WP_Ability ) {
		return new WP_Error( 'extrachill_partner_events_unavailable', __( 'Partner events are temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'partner_key' => $key,
			'limit'       => (int) $request->get_param( 'limit' ),
		)
	);
	if ( is_wp_error( $result ) ) {
		$status = (int) ( $result->get_error_data()['status'] ?? 0 );
		if ( 'extrachill_partner_key_invalid' === $result->get_error_code() || 401 === $status ) {
			return extrachill_api_partner_key_invalid();
		}
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status >= 400 ? $status : 500 ) );
	}

	$response = rest_ensure_response( $result );
	$response->header( 'Cache-Control', 'private, max-age=300' );
	$response->header( 'Vary', EXTRACHILL_API_PARTNER_KEY_HEADER );
	$response->header( 'X-Robots-Tag', 'noindex' );

	return $response;
}

/**
 * The single 401 every unusable partner key receives.
 *
 * @return WP_Error
 */
function extrachill_api_partner_key_invalid() {
	return new WP_Error(
		'extrachill_partner_key_invalid',
		__( 'Invalid partner key.', 'extrachill-api' ),
		array( 'status' => 401 )
	);
}
