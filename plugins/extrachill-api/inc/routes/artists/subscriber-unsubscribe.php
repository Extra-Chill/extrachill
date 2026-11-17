<?php
/**
 * Artist mailing list unsubscribe: thin REST adapters for extrachill-artist-platform.
 *
 * GET  /extrachill/v1/artists/subscribers/unsubscribe          scanner-safe confirmation page
 * POST /extrachill/v1/artists/subscribers/unsubscribe          removes that subscription
 *   -> extrachill/artist-subscriber-unsubscribe
 * GET    /extrachill/v1/artists/me/subscriptions               signed-in member's artist lists
 *   -> extrachill/list-my-artist-subscriptions
 * DELETE /extrachill/v1/artists/me/subscriptions/{subscriber_id}
 *   -> extrachill/remove-my-artist-subscription
 *
 * Artists paste the signed link from their subscriber export into their own
 * emails. The signature is the authorization, so that route is public; the
 * ability owns verification, the removal and the page copy, and this adapter
 * maps the HTTP method onto `confirm` and serves the returned document as
 * text/html (same shape as the Local Scene digest unsubscribe route). All
 * routes live under /extrachill/v1/artists/, so route affinity runs them on
 * the artist site where the abilities are registered.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_API_ARTIST_UNSUBSCRIBE_ROUTE = '/extrachill/v1/artists/subscribers/unsubscribe';

add_filter( 'rest_pre_serve_request', 'extrachill_api_serve_artist_unsubscribe_html', 10, 4 );
add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_unsubscribe_routes' );

/** Register the signed link route and the member self-service routes. */
function extrachill_api_register_artist_unsubscribe_routes() {
	$token_args = array(
		'token' => array( 'type' => 'string' ),
	);

	register_rest_route(
		'extrachill/v1',
		'/artists/subscribers/unsubscribe',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_handle_artist_unsubscribe',
				'permission_callback' => '__return_true',
				'args'                => $token_args,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_handle_artist_unsubscribe',
				'permission_callback' => '__return_true',
				'args'                => $token_args,
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/artists/me/subscriptions',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_list_my_artist_subscriptions',
			'permission_callback' => 'is_user_logged_in',
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/artists/me/subscriptions/(?P<subscriber_id>\d+)',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'extrachill_api_remove_my_artist_subscription',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'subscriber_id' => array(
					'required' => true,
					'type'     => 'integer',
					'minimum'  => 1,
				),
			),
		)
	);
}

/**
 * Resolve the signed link through the owner ability into an HTML response.
 *
 * GET never changes the subscription; POST confirms the unsubscribe.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_artist_unsubscribe( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/artist-subscriber-unsubscribe' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', __( 'Unsubscribe is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'confirm' => 'POST' === $request->get_method(),
			'token'   => (string) $request->get_param( 'token' ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// wp_get_nocache_headers() marks Last-Modified for removal with false;
	// WP_REST_Server would send that as an empty header, so drop it.
	$response = new WP_REST_Response( (string) ( $result['html'] ?? '' ), 200 );
	$response->set_headers(
		array_merge(
			array_filter( wp_get_nocache_headers() ),
			array(
				'Content-Type' => 'text/html; charset=utf-8',
				'X-Robots-Tag' => 'noindex, nofollow',
			)
		)
	);

	return $response;
}

/**
 * Print the unsubscribe document as raw HTML instead of a JSON string.
 *
 * @param bool                $served  Whether the request has already been served.
 * @param WP_HTTP_Response    $result  Response about to be served.
 * @param WP_REST_Request     $request Request being served.
 * @param WP_REST_Server|null $server  REST server instance.
 * @return bool
 */
function extrachill_api_serve_artist_unsubscribe_html( $served, $result, $request, $server ) {
	unset( $server );
	if ( $served || EXTRACHILL_API_ARTIST_UNSUBSCRIBE_ROUTE !== $request->get_route() ) {
		return $served;
	}
	if ( ! $result instanceof WP_REST_Response || $result->is_error() || ! is_string( $result->get_data() ) ) {
		return $served;
	}

	echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone document escaped by the extrachill-artist-platform page builder.
	return true;
}

/**
 * GET /artists/me/subscriptions
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_list_my_artist_subscriptions( WP_REST_Request $request ) {
	unset( $request );
	$ability = wp_get_ability( 'extrachill/list-my-artist-subscriptions' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * DELETE /artists/me/subscriptions/{subscriber_id}
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_remove_my_artist_subscription( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/remove-my-artist-subscription' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'subscriber_id' => (int) $request->get_param( 'subscriber_id' ) ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
