<?php
/**
 * Guest list claim endpoint — thin REST adapter for extrachill/claim-event-pass.
 *
 * POST /extrachill/v1/events/{id}/claim-pass
 *
 * Lets a logged-in member claim a spot on a password-gated guest list
 * (extrachill#433) and get their pass back on screen. The extrachill-events
 * ability owns the password check, capacity, and issuance, and is hidden
 * from the core REST runner; this route is its write boundary, applying the
 * shared per-client public-write rate limit so the guest list password can't
 * be brute-forced.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_claim_pass_route' );

/** Register the logged-in guest list claim adapter. */
function extrachill_api_register_claim_pass_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/(?P<id>\d+)/claim-pass',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_claim_pass',
			'permission_callback' => 'extrachill_api_claim_pass_permission',
			'args'                => array(
				'id'       => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'password' => array( 'type' => 'string' ),
			),
		)
	);
}

/**
 * Logged-in members only, within the shared public-write budget.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function extrachill_api_claim_pass_permission( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_not_logged_in', __( 'Log in to claim a pass.', 'extrachill-api' ), array( 'status' => 401 ) );
	}

	/**
	 * Filters the guest list claims allowed per client per minute.
	 *
	 * @param int $limit Default 5.
	 */
	$limit = (int) apply_filters( 'extrachill_api_claim_pass_rate_limit', 5 );
	return extrachill_api_check_public_write_rate_limit( $request, 'claim-pass', $limit );
}

/**
 * Execute an admitted claim through the owner ability.
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_claim_pass( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/claim-event-pass' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', __( 'The guest list is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$input    = array( 'event_id' => absint( $request->get_param( 'id' ) ) );
	$password = $request->get_param( 'password' );
	if ( is_string( $password ) && '' !== $password ) {
		$input['password'] = $password;
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
