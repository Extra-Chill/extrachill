<?php
/**
 * Get REST 5xx Summary Ability
 *
 * Reports REST responses with status >= 500 by matched route and error code,
 * from the bounded counter in rest-5xx-counter.php. This is a separate source
 * from the PHP error summary: REST error responses never reach debug.log.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_rest_5xx_summary_ability' );

/** Register the get-rest-5xx-summary ability. */
function extrachill_analytics_register_rest_5xx_summary_ability() {
	wp_register_ability(
		'extrachill/get-rest-5xx-summary',
		array(
			'label'               => __( 'Get REST 5xx Summary', 'extrachill-analytics' ),
			'description'         => __( 'Counts REST responses with status >= 500 by matched route and error code over a trailing window (max 7 days).', 'extrachill-analytics' ),
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
				'description' => __( 'total, per_day, window_hours, top, and rows (route, code, count, per_day, peak_per_hour).', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_rest_5xx_summary',
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
 * Execute the REST 5xx summary.
 *
 * @param array|null $input Ability input.
 * @return array Summary.
 */
function extrachill_analytics_ability_get_rest_5xx_summary( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	return extrachill_analytics_rest_5xx_summarize(
		extrachill_analytics_rest_5xx_buckets(),
		(int) ( $input['hours'] ?? 24 ),
		time(),
		(int) ( $input['limit'] ?? 10 )
	);
}
