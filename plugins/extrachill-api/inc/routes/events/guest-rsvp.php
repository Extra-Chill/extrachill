<?php
/**
 * Guest RSVP endpoint — thin REST adapter for extrachill/guest-rsvp-event.
 *
 * POST /extrachill/v1/events/{id}/guest-rsvp
 *
 * Lets a logged-out visitor RSVP to a perk-enabled event with only an email.
 * The extrachill-events ability owns everything behind it (account
 * resolution, attendance, pass email) and is hidden from the core REST
 * runner; this route is the public write boundary: Cloudflare Turnstile,
 * then the shared per-client public-write rate limit.
 *
 * The response is always `{ status: 'rsvped' }` on success. It never reveals
 * whether the email already had an account or what the pass code is; those
 * are delivered by email only.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_guest_rsvp_route' );

/** Register the public guest RSVP adapter. */
function extrachill_api_register_guest_rsvp_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/(?P<id>\d+)/guest-rsvp',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_guest_rsvp',
			'permission_callback' => 'extrachill_api_guest_rsvp_permission',
			'args'                => array(
				'id'                 => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'email'              => array(
					'required' => true,
					'type'     => 'string',
				),
				'referrer'           => array( 'type' => 'string' ),
				'utm'                => array( 'type' => 'object' ),
				'turnstile_response' => array(
					'required' => false,
					'type'     => 'string',
				),
			),
		)
	);
}

/**
 * Turnstile first, then the public-write budget.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function extrachill_api_guest_rsvp_permission( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_turnstile_check_request' ) ) {
		return new WP_Error( 'guest_rsvp_security_unavailable', __( 'Security verification is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$turnstile = ec_turnstile_check_request( $request );
	if ( is_wp_error( $turnstile ) ) {
		return $turnstile;
	}

	/**
	 * Filters the guest RSVP writes allowed per client per minute.
	 *
	 * @param int $limit Default 5.
	 */
	$limit = (int) apply_filters( 'extrachill_api_guest_rsvp_rate_limit', 5 );
	return extrachill_api_check_public_write_rate_limit( $request, 'guest-rsvp', $limit );
}

/**
 * Execute an admitted guest RSVP through the owner ability.
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_guest_rsvp( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/guest-rsvp-event' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', __( 'Guest RSVP is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$input = array(
		'event_id' => absint( $request->get_param( 'id' ) ),
		'email'    => sanitize_email( (string) $request->get_param( 'email' ) ),
	);

	$referrer = esc_url_raw( (string) $request->get_param( 'referrer' ) );
	if ( '' !== $referrer ) {
		$input['referrer'] = $referrer;
	}

	$utm = $request->get_param( 'utm' );
	if ( is_array( $utm ) ) {
		$clean = array();
		foreach ( $utm as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( 0 === strpos( $key, 'utm_' ) && is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		if ( $clean ) {
			$input['utm'] = $clean;
		}
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( array( 'status' => 'rsvped' ) );
}
