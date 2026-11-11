<?php
/**
 * REST route: GET /wp-json/extrachill/v1/analytics/link-page
 *
 * Authenticated endpoint for fetching aggregated analytics data for link pages.
 * Delegates to the extrachill/get-link-page-analytics ability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_link_page_analytics_route' );

function extrachill_api_register_link_page_analytics_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/link-page',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_link_page_analytics_handler',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'link_page_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
				'date_range'   => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 30,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Handles link page analytics requests.
 *
 * Thin adapter over the extrachill/get-link-page-analytics ability
 * (extrachill-analytics), which owns Link Page validation, owner resolution
 * from the Link Page's storage-blog owner reference, and the artist
 * permission check.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_link_page_analytics_handler( WP_REST_Request $request ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-link-page-analytics' ) : null;
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-analytics plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'link_page_id' => absint( $request->get_param( 'link_page_id' ) ),
			'date_range'   => absint( $request->get_param( 'date_range' ) ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
