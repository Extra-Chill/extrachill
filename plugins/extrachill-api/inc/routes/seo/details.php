<?php
/**
 * SEO Audit Details REST API Endpoint
 *
 * Returns detailed items for a specific audit category with pagination.
 *
 * @endpoint GET /wp-json/extrachill/v1/seo/audit/details
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_seo_audit_details_route' );

/**
 * Registers the SEO audit details endpoint.
 */
function extrachill_api_register_seo_audit_details_route() {
	register_rest_route(
		'extrachill/v1',
		'/seo/audit/details',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_seo_audit_details',
			'permission_callback' => 'extrachill_api_seo_audit_permission_check',
			'args'                => array(
				'category' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						$valid_categories = array(
							'missing_excerpts',
							'missing_alt_text',
							'missing_featured',
							'broken_images',
							'broken_internal_links',
							'broken_external_links',
						);
						return in_array( $value, $valid_categories, true );
					},
				),
				'page'     => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $value ) {
						return $value >= 1;
					},
				),
				'per_page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 50,
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $value ) {
						return $value >= 1 && $value <= 100;
					},
				),
				'export'   => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		)
	);
}

/**
 * Gets detailed items for a specific audit category.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with audit details or error.
 */
function extrachill_api_get_seo_audit_details( $request ) {
	$category = $request->get_param( 'category' );
	$page     = $request->get_param( 'page' );
	$per_page = $request->get_param( 'per_page' );
	$export   = $request->get_param( 'export' );

	$function_map = array(
		'missing_excerpts'      => 'ec_seo_get_missing_excerpts',
		'missing_alt_text'      => 'ec_seo_get_missing_alt_text',
		'missing_featured'      => 'ec_seo_get_missing_featured',
		'broken_images'         => 'ec_seo_get_broken_images',
		'broken_internal_links' => 'ec_seo_get_broken_internal_links',
		'broken_external_links' => 'ec_seo_get_broken_external_links',
	);

	$function = $function_map[ $category ] ?? null;

	if ( ! $function || ! function_exists( $function ) ) {
		return new WP_Error(
			'function_not_found',
			'Audit detail function not available.',
			array( 'status' => 500 )
		);
	}

	if ( $export ) {
		$result = $function( PHP_INT_MAX, 0 );
	} else {
		$offset = ( $page - 1 ) * $per_page;
		$result = $function( $per_page, $offset );
	}

	$total       = $result['total'];
	$total_pages = $export ? 1 : (int) ceil( $total / $per_page );

	return rest_ensure_response(
		array(
			'category'    => $category,
			'page'        => $export ? 1 : $page,
			'per_page'    => $export ? $total : $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
			'items'       => $result['items'],
		)
	);
}
