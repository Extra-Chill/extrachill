<?php
/**
 * Link Page editor configuration.
 *
 * GET /wp-json/extrachill/v1/link-pages/editor-configuration
 *
 * Returns the Link Page editor configuration for the authenticated user
 * (owner adapter, identities, fonts, and the adapter scripts to load). The
 * extrachill.link /edit shell calls this with a wp-native bearer token.
 * Thin adapter over the extrachill-link-pages ability.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_link_page_editor_configuration_route' );

/** Register the route. */
function extrachill_api_register_link_page_editor_configuration_route() {
	register_rest_route(
		'extrachill/v1',
		'/link-pages/editor-configuration',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_link_page_editor_configuration_handler',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'link_page_id' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Execute the editor configuration ability.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_link_page_editor_configuration_handler( WP_REST_Request $request ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-link-page-editor-configuration' ) : null;
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-link-pages is required.', array( 'status' => 500 ) );
	}
	$result = $ability->execute( array( 'link_page_id' => (int) $request->get_param( 'link_page_id' ) ) );
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$result->add_data( array( 'status' => 400 ) );
		}
		return $result;
	}
	return rest_ensure_response( $result );
}
