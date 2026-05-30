<?php
/**
 * Get Analytics Meta Ability
 *
 * Read-side ability that returns the filter options for the analytics
 * dashboard: the distinct event types and the blogs that have events.
 *
 * @package ExtraChill\Analytics
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-analytics-meta ability.
 */
function extrachill_analytics_register_meta_ability() {
	wp_register_ability(
		'extrachill/get-analytics-meta',
		array(
			'label'        => __( 'Get Analytics Meta', 'extrachill-analytics' ),
			'description'  => __( 'Returns analytics filter options: distinct event types and the blogs that have events.', 'extrachill-analytics' ),
			'category'     => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema' => array(
				'type'        => 'object',
				'description' => __( 'Object with event_types (string array) and blogs (array of {id, name}).', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_meta',
			'permission_callback' => function () {
				return current_user_can( 'manage_network_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
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
 * Execute callback for get-analytics-meta ability.
 *
 * @return array Meta data with event_types and blogs.
 */
function extrachill_analytics_ability_get_meta() {
	$event_types = function_exists( 'extrachill_get_analytics_event_types' )
		? extrachill_get_analytics_event_types()
		: array();

	$blog_ids = function_exists( 'extrachill_get_analytics_blog_ids' )
		? extrachill_get_analytics_blog_ids()
		: array();

	$blogs = array();
	foreach ( $blog_ids as $blog_id ) {
		$blog_id   = absint( $blog_id );
		$blog_name = get_blog_option( $blog_id, 'blogname' );
		$blogs[]   = array(
			'id'   => $blog_id,
			'name' => $blog_name ? $blog_name : "Blog {$blog_id}",
		);
	}

	return array(
		'event_types' => $event_types,
		'blogs'       => $blogs,
	);
}
