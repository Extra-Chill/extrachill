<?php
/**
 * Guest list pass endpoint — thin REST adapter for extrachill/issue-guest-list-pass.
 *
 * POST /extrachill/v1/events/{id}/guest-list-pass
 *
 * Backs an event's /guest-list page (extrachill#433): a name and the guest
 * list password, plus an optional email for a copy, returns a pass code the
 * guest keeps on their phone. No account is involved. The extrachill-events
 * ability owns the password, capacity, and email behavior and is hidden
 * from the core REST runner; this route is the public write boundary:
 * Cloudflare Turnstile, then the shared per-client public-write rate limit,
 * which also bounds password guessing.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_guest_list_pass_route' );

/** Register the guest list pass adapter. */
function extrachill_api_register_guest_list_pass_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/(?P<id>\d+)/guest-list-pass',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_guest_list_pass',
			'permission_callback' => 'extrachill_api_guest_list_pass_permission',
			'args'                => array(
				'id'                 => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'password'           => array(
					'required' => true,
					'type'     => 'string',
				),
				'name'               => array(
					'required' => true,
					'type'     => 'string',
				),
				'email'              => array( 'type' => 'string' ),
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
function extrachill_api_guest_list_pass_permission( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_turnstile_check_request' ) ) {
		return new WP_Error( 'guest_list_security_unavailable', __( 'Security verification is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$turnstile = ec_turnstile_check_request( $request );
	if ( is_wp_error( $turnstile ) ) {
		return $turnstile;
	}

	/**
	 * Filters the guest list pass requests allowed per client per minute.
	 *
	 * @param int $limit Default 5.
	 */
	$limit = (int) apply_filters( 'extrachill_api_guest_list_pass_rate_limit', 5 );
	return extrachill_api_check_public_write_rate_limit( $request, 'guest-list-pass', $limit );
}

/**
 * Execute an admitted request through the owner ability.
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_guest_list_pass( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/issue-guest-list-pass' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', __( 'The guest list is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	// Password passed through verbatim; the owner ability checks it
	// against the stored hash.
	$input = array(
		'event_id' => absint( $request->get_param( 'id' ) ),
		'password' => (string) $request->get_param( 'password' ),
		'name'     => sanitize_text_field( (string) $request->get_param( 'name' ) ),
	);

	$email = trim( (string) $request->get_param( 'email' ) );
	if ( '' !== $email ) {
		$input['email'] = $email;
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
