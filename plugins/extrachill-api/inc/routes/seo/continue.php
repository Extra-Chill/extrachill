<?php
/**
 * SEO Audit Continue REST API Endpoint
 *
 * Continues a batch audit from where it left off.
 *
 * @endpoint POST /wp-json/extrachill/v1/seo/audit/continue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_seo_audit_continue_route' );

/**
 * Registers the SEO audit continue endpoint.
 */
function extrachill_api_register_seo_audit_continue_route() {
	register_rest_route(
		'extrachill/v1',
		'/seo/audit/continue',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_continue_seo_audit',
			'permission_callback' => 'extrachill_api_seo_audit_permission_check',
		)
	);
}

/**
 * Continues a batch SEO audit.
 *
 * @return WP_REST_Response|WP_Error Response with audit progress or error.
 */
function extrachill_api_continue_seo_audit() {
	if ( ! function_exists( 'ec_seo_continue_batch_audit' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Extra Chill SEO plugin not available.',
			array( 'status' => 500 )
		);
	}

	$results = ec_seo_continue_batch_audit();
	return rest_ensure_response( $results );
}
