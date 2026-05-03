<?php
declare(strict_types=1);
/**
 * Get Link Page Analytics Ability
 *
 * Read-side ability that returns aggregated analytics
 * for an artist link page over a configurable date range.
 *
 * @package ExtraChill\Analytics
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-link-page-analytics ability.
 */
function extrachill_analytics_register_get_link_page_analytics_ability(): void {
	wp_register_ability(
		'extrachill/get-link-page-analytics',
		array(
			'label'       => __( 'Get Link Page Analytics', 'extrachill-analytics' ),
			'description' => __( 'Returns aggregated analytics data for an artist link page.', 'extrachill-analytics' ),
			'category'    => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'link_page_id' => array(
						'type'        => 'integer',
						'description' => __( 'The link page post ID.', 'extrachill-analytics' ),
					),
					'date_range' => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to query. Defaults to 30.', 'extrachill-analytics' ),
						'default'     => 30,
					),
				),
				'required' => array( 'link_page_id' ),
			),
			'output_schema' => array(
				'type'        => 'object',
				'description' => __( 'Aggregated analytics data for the link page.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_link_page_analytics',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for get-link-page-analytics ability.
 *
 * Validates the link page, checks ownership via ec_can_manage_artist(),
 * then delegates to the extrachill_get_link_page_analytics filter.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error Analytics data or error.
 */
function extrachill_analytics_ability_get_link_page_analytics( array $input ) {
	$link_page_id = isset( $input['link_page_id'] ) ? (int) $input['link_page_id'] : 0;
	$date_range   = isset( $input['date_range'] ) ? (int) $input['date_range'] : 30;

	if ( $link_page_id <= 0 ) {
		return new \WP_Error(
			'invalid_link_page_id',
			__( 'A valid link_page_id is required.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	// Validate post type.
	if ( get_post_type( $link_page_id ) !== 'artist_link_page' ) {
		return new \WP_Error(
			'invalid_link_page',
			__( 'Invalid link page specified.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	// Resolve artist ownership.
	$artist_id = apply_filters( 'ec_get_artist_id', $link_page_id );
	if ( ! $artist_id ) {
		return new \WP_Error(
			'artist_not_found',
			__( 'Could not determine associated artist.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	// Permission: caller must be able to manage the artist.
	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new \WP_Error(
			'permission_denied',
			__( 'You do not have permission to view analytics for this link page.', 'extrachill-analytics' ),
			array( 'status' => 403 )
		);
	}

	/** @var array|WP_Error|null $result */
	$result = apply_filters( 'extrachill_get_link_page_analytics', null, $link_page_id, $date_range );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new \WP_Error(
			'analytics_unavailable',
			__( 'Analytics data could not be retrieved.', 'extrachill-analytics' ),
			array( 'status' => 500 )
		);
	}

	return $result;
}
