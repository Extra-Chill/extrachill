<?php
declare(strict_types=1);
/**
 * Track Page View Ability
 *
 * Write-side ability that increments post view counts.
 * High-frequency hot-path — keeps logic minimal.
 *
 * @package ExtraChill\Analytics
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the track-page-view ability.
 */
function extrachill_analytics_register_track_page_view_ability(): void {
	wp_register_ability(
		'extrachill/track-page-view',
		array(
			'label'       => __( 'Track Page View', 'extrachill-analytics' ),
			'description' => __( 'Increment the view counter for a post. High-frequency endpoint called async after page load.', 'extrachill-analytics' ),
			'category'    => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to record a view for.', 'extrachill-analytics' ),
					),
				),
				'required' => array( 'post_id' ),
			),
			'output_schema' => array(
				'type'        => 'object',
				'description' => __( 'Confirmation object with recorded flag.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_track_page_view',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for track-page-view ability.
 *
 * Mirrors the existing REST handler: quick post-meta increment,
 * plus link-page daily-table action when applicable.
 *
 * @param array $input Input parameters.
 * @return array{recorded: bool}|WP_Error Confirmation or error.
 */
function extrachill_analytics_ability_track_page_view( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	if ( $post_id <= 0 ) {
		return new \WP_Error(
			'invalid_post_id',
			__( 'A valid post_id is required.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_track_post_views' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'View tracking function not available.', 'extrachill-analytics' ),
			array( 'status' => 500 )
		);
	}

	// All-time view increment (post meta).
	ec_track_post_views( $post_id );

	// Link pages also fire the 90-day daily-table action.
	if ( get_post_type( $post_id ) === 'artist_link_page' ) {
		do_action( 'extrachill_link_page_view_recorded', $post_id );
	}

	return array( 'recorded' => true );
}
