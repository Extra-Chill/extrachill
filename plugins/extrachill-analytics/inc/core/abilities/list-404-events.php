<?php
/**
 * List 404 Events Ability
 *
 * Read-side ability that returns raw 404 event records
 * with full event data fields.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the list-404-events ability.
 */
function extrachill_analytics_register_list_404_events_ability() {
	wp_register_ability(
		'extrachill/list-404-events',
		array(
			'label'       => __( 'List 404 Events', 'extrachill-analytics' ),
			'description' => __( 'Returns raw 404 event records with URL, referer, user agent, IP hash, and date.', 'extrachill-analytics' ),
			'category'    => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'days'    => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back.', 'extrachill-analytics' ),
						'default'     => 7,
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'limit'   => array(
						'type'        => 'integer',
						'description' => __( 'Maximum number of events to return.', 'extrachill-analytics' ),
						'default'     => 50,
					),
				),
			),
			'output_schema' => array(
				'type'        => 'array',
				'description' => __( 'Array of 404 event records.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_list_404_events',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
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
 * Execute callback for list-404-events ability.
 *
 * @param array $input Input parameters.
 * @return array Array of event records.
 */
function extrachill_analytics_ability_list_404_events( $input ) {
	$days    = isset( $input['days'] ) ? (int) $input['days'] : 7;
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$limit   = isset( $input['limit'] ) ? (int) $input['limit'] : 50;

	$args = array(
		'event_type' => '404_error',
		'limit'      => $limit,
		'orderby'    => 'created_at',
		'order'      => 'DESC',
	);

	if ( $days > 0 ) {
		$args['date_from'] = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$args['blog_id'] = $blog_id;
	}

	$events  = extrachill_get_analytics_events( $args );
	$results = array();

	foreach ( $events as $event ) {
		$data = is_array( $event->event_data ) ? $event->event_data : array();

		$results[] = array(
			'url'        => isset( $data['requested_url'] ) ? $data['requested_url'] : '',
			'referer'    => isset( $data['referer'] ) ? $data['referer'] : '',
			'user_agent' => isset( $data['user_agent'] ) ? $data['user_agent'] : '',
			'ip_hash'    => isset( $data['ip_hash'] ) ? $data['ip_hash'] : '',
			'date'       => $event->created_at,
			'blog_id'    => (int) $event->blog_id,
		);
	}

	return $results;
}
