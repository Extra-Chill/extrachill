<?php
/**
 * SEO Audit Status REST API Endpoint
 *
 * Returns current audit results and progress.
 *
 * @endpoint GET /wp-json/extrachill/v1/seo/audit/status
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_seo_audit_status_route' );

/**
 * Registers the SEO audit status endpoint.
 */
function extrachill_api_register_seo_audit_status_route() {
	register_rest_route(
		'extrachill/v1',
		'/seo/audit/status',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_seo_audit_status',
			'permission_callback' => 'extrachill_api_seo_audit_permission_check',
		)
	);
}

/**
 * Gets current SEO audit status and results.
 *
 * Wraps the extrachill/get-seo-results ability from extrachill-seo.
 *
 * @return WP_REST_Response|WP_Error Response with audit status or error.
 */
function extrachill_api_get_seo_audit_status() {
	$ability = wp_get_ability( 'extrachill/get-seo-results' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-seo plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
