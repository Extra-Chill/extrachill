<?php
/**
 * Guest Local Scene digest signup: thin REST adapter for
 * extrachill/guest-subscribe-local-scene-digest (extrachill-events#989).
 *
 * POST /extrachill/v1/events/local-scene-digest/guest-subscribe
 *
 * Lets a logged-out visitor ask for a city's weekly show email with only an
 * email address. The extrachill-events ability owns everything behind it
 * (account resolution, the signed confirm email, throttling) and is hidden
 * from the core REST runner; this route is the public write boundary:
 * Cloudflare Turnstile, then the shared per-client public-write rate limit.
 * The `/events/` prefix gives it events-site affinity.
 *
 * The response is always `{ status: 'check_inbox' }` on success. It never
 * reveals whether the email had an account or was already subscribed.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_local_scene_digest_guest_route' );

/** Register the public guest digest signup adapter. */
function extrachill_api_register_local_scene_digest_guest_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/local-scene-digest/guest-subscribe',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_local_scene_digest_guest',
			'permission_callback' => 'extrachill_api_local_scene_digest_guest_permission',
			'args'                => array(
				'location_slug'      => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_title',
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
function extrachill_api_local_scene_digest_guest_permission( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_turnstile_check_request' ) ) {
		return new WP_Error( 'guest_digest_security_unavailable', __( 'Security verification is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$turnstile = ec_turnstile_check_request( $request );
	if ( is_wp_error( $turnstile ) ) {
		return $turnstile;
	}

	/**
	 * Filters the guest digest signups allowed per client per minute.
	 *
	 * @param int $limit Default 5.
	 */
	$limit = (int) apply_filters( 'extrachill_api_local_scene_digest_guest_rate_limit', 5 );
	return extrachill_api_check_public_write_rate_limit( $request, 'local-scene-digest-guest', $limit );
}

/**
 * Execute an admitted guest signup through the owner ability.
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_local_scene_digest_guest( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/guest-subscribe-local-scene-digest' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', __( 'Email signup is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$input = array(
		'location_slug' => sanitize_title( (string) $request->get_param( 'location_slug' ) ),
		'email'         => sanitize_email( (string) $request->get_param( 'email' ) ),
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

	return rest_ensure_response( array( 'status' => 'check_inbox' ) );
}
