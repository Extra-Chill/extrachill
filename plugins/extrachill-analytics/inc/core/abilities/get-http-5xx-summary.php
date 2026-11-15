<?php
/**
 * Get HTTP 5xx Summary Ability
 *
 * Reports every response with status >= 500 in the web server access log, by
 * path class and status, from the bounded counter in http-5xx-counter.php.
 * This includes gateway errors (502/504) that never reach WordPress.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_http_5xx_summary_ability' );

/** Register the get-http-5xx-summary ability. */
function extrachill_analytics_register_http_5xx_summary_ability() {
	wp_register_ability(
		'extrachill/get-http-5xx-summary',
		array(
			'label'               => __( 'Get HTTP 5xx Summary', 'extrachill-analytics' ),
			'description'         => __( 'Counts web server responses with status >= 500 (including gateway errors that never reach WordPress) by path class and status over a trailing window (max 7 days).', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'hours' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => EC_ANALYTICS_REST_5XX_RETENTION_HOURS,
						'default' => 24,
					),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => EC_ANALYTICS_REST_5XX_MAX_KEYS * EC_ANALYTICS_REST_5XX_RETENTION_HOURS,
						'default' => 10,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'total, per_day, window_hours, top, and rows (route = path class, code = HTTP status, count, per_day, peak_per_hour).', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_http_5xx_summary',
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
 * Execute the HTTP 5xx summary.
 *
 * @param array|null $input Ability input.
 * @return array Summary.
 */
function extrachill_analytics_ability_get_http_5xx_summary( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	return extrachill_analytics_http_5xx_summarize(
		(int) ( $input['hours'] ?? 24 ),
		time(),
		(int) ( $input['limit'] ?? 10 )
	);
}
