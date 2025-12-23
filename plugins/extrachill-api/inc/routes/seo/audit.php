<?php
/**
 * SEO Audit REST API Endpoint
 *
 * Starts a new SEO audit in full or batch mode.
 *
 * @endpoint POST /wp-json/extrachill/v1/seo/audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_seo_audit_route' );

/**
 * Registers the SEO audit start endpoint.
 */
function extrachill_api_register_seo_audit_route() {
	register_rest_route(
		'extrachill/v1',
		'/seo/audit',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_run_seo_audit',
			'permission_callback' => 'extrachill_api_seo_audit_permission_check',
			'args'                => array(
				'mode' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						return in_array( $value, array( 'full', 'batch' ), true );
					},
				),
			),
		)
	);
}

/**
 * Permission check for SEO audit endpoints.
 *
 * @return bool|WP_Error True if authorized, WP_Error otherwise.
 */
function extrachill_api_seo_audit_permission_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to run SEO audits.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Runs a new SEO audit.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with audit results or error.
 */
function extrachill_api_run_seo_audit( $request ) {
	if ( ! function_exists( 'ec_seo_run_full_audit' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Extra Chill SEO plugin audit functions not available.',
			array( 'status' => 500 )
		);
	}

	$mode = $request->get_param( 'mode' );

	if ( 'full' === $mode ) {
		$results = ec_seo_run_full_audit();
		return rest_ensure_response( $results );
	}

	$results = ec_seo_start_batch_audit();
	return rest_ensure_response( $results );
}
