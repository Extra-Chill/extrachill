<?php
/**
 * Signed Local Scene digest unsubscribe: thin REST adapter for
 * extrachill/unsubscribe-local-scene-digest (extrachill-events).
 *
 * GET  /extrachill/v1/local-scene-digest/unsubscribe  scanner-safe confirmation page
 * POST /extrachill/v1/local-scene-digest/unsubscribe  location-scoped unsubscribe
 *
 * Digest emails link here. The signature in the link is the authorization,
 * so the route is public. The ability owns verification, the consent change
 * and the page copy; this adapter maps the HTTP method onto `confirm` and
 * serves the returned standalone document as text/html instead of JSON. The
 * ability is registered on the events site only, so the route has
 * events-site affinity.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_API_LOCAL_SCENE_DIGEST_UNSUBSCRIBE_ROUTE = '/extrachill/v1/local-scene-digest/unsubscribe';

add_filter( 'ec_route_site_affinity_map', 'extrachill_api_add_local_scene_digest_unsubscribe_affinity' );
add_filter( 'rest_pre_serve_request', 'extrachill_api_serve_local_scene_digest_unsubscribe_html', 10, 4 );
add_action( 'extrachill_api_register_routes', 'extrachill_api_register_local_scene_digest_unsubscribe_route' );

/**
 * Give the unsubscribe route events-site affinity.
 *
 * @param array $affinity_map Route prefix to site key map.
 * @return array
 */
function extrachill_api_add_local_scene_digest_unsubscribe_affinity( $affinity_map ) {
	$affinity_map[ EXTRACHILL_API_LOCAL_SCENE_DIGEST_UNSUBSCRIBE_ROUTE ] = 'events';
	return $affinity_map;
}

/** Register the public, signature-authenticated one-click route. */
function extrachill_api_register_local_scene_digest_unsubscribe_route() {
	register_rest_route(
		'extrachill/v1',
		'/local-scene-digest/unsubscribe',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_handle_local_scene_digest_unsubscribe',
				'permission_callback' => '__return_true',
				'args'                => extrachill_api_local_scene_digest_unsubscribe_args(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_handle_local_scene_digest_unsubscribe',
				'permission_callback' => '__return_true',
				'args'                => extrachill_api_local_scene_digest_unsubscribe_args(),
			),
		)
	);
}

/**
 * The shared signed unsubscribe request schema.
 *
 * @return array
 */
function extrachill_api_local_scene_digest_unsubscribe_args() {
	return array(
		'token'     => array( 'type' => 'string' ),
		'user'      => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		),
		'location'  => array(
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_title',
		),
		'expires'   => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		),
		'signature' => array(
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		),
	);
}

/**
 * Resolve the signed request through the owner ability into an HTML response.
 *
 * GET never changes consent; POST confirms the unsubscribe.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_local_scene_digest_unsubscribe( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/unsubscribe-local-scene-digest' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', __( 'Unsubscribe is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$input = array( 'confirm' => 'POST' === $request->get_method() );
	foreach ( array( 'token', 'user', 'location', 'expires', 'signature' ) as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value ) {
			$input[ $key ] = $value;
		}
	}

	$result = $ability->execute( $input );
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
 * WP_REST_Server has already sent the response status and headers when this
 * filter runs. Errors keep the default JSON serialization.
 *
 * @param bool                 $served  Whether the request has already been served.
 * @param WP_HTTP_Response     $result  Response about to be served.
 * @param WP_REST_Request      $request Request being served.
 * @param WP_REST_Server|null  $server  REST server instance.
 * @return bool
 */
function extrachill_api_serve_local_scene_digest_unsubscribe_html( $served, $result, $request, $server ) {
	unset( $server );
	if ( $served || EXTRACHILL_API_LOCAL_SCENE_DIGEST_UNSUBSCRIBE_ROUTE !== $request->get_route() ) {
		return $served;
	}
	if ( ! $result instanceof WP_REST_Response || $result->is_error() || ! is_string( $result->get_data() ) ) {
		return $served;
	}

	echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone document escaped by the extrachill-events page builder.
	return true;
}
