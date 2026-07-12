<?php
/**
 * REST route: POST /wp-json/extrachill/v1/analytics/view
 *
 * Async view counting endpoint. Called via JavaScript after page load
 * to track post views without blocking page render.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_view_count_route' );

function extrachill_api_register_view_count_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/view',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_view_count_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id'    => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
				'visitor_id' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					// Anonymous first-party UUID v4 echoed by the beacon. Accept only a
					// well-formed UUID v4; anything else (including empty / opted-out)
					// is coerced to '' so the ability records the pageview anonymously.
					'validate_callback' => function ( $param ) {
						return '' === $param || 1 === preg_match(
							'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
							(string) $param
						);
					},
					'sanitize_callback' => 'sanitize_text_field',
				),
				'referrer'   => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					// Raw client-side document.referrer captured by the beacon. The
					// tracking ability normalizes it to a host-only `referrer_host`
					// (no query strings, no PII) and drops direct/same-host values,
					// so validation stays permissive here — garbage normalizes to
					// nothing downstream.
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

function extrachill_api_view_count_handler( $request ) {
	$post_id    = (int) $request->get_param( 'post_id' );
	$visitor_id = (string) $request->get_param( 'visitor_id' );
	$referrer   = (string) $request->get_param( 'referrer' );

	// Thin wrapper: all write logic (post-meta bump, pageview event row carrying
	// visitor_id, link-page daily-table action) lives in the analytics ability.
	$ability = wp_get_ability( 'extrachill/track-page-view' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_not_found',
			'extrachill-analytics plugin is required.',
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute(
		array(
			'post_id'    => $post_id,
			'visitor_id' => $visitor_id,
			'referrer'   => $referrer,
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
