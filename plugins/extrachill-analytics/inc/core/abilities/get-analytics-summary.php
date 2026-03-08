<?php
/**
 * Get Analytics Summary Ability
 *
 * Read-side ability that returns event counts grouped by type
 * with optional date filtering.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-analytics-summary ability.
 */
function extrachill_analytics_register_summary_ability() {
	wp_register_ability(
		'extrachill/get-analytics-summary',
		array(
			'label'       => __( 'Get Analytics Summary', 'extrachill-analytics' ),
			'description' => __( 'Returns event counts grouped by type with optional date filtering.', 'extrachill-analytics' ),
			'category'    => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'days' => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'event_type' => array(
						'type'        => 'string',
						'description' => __( 'Filter to a specific event type. Empty for all types.', 'extrachill-analytics' ),
						'default'     => '',
					),
				),
			),
			'output_schema' => array(
				'type'        => 'object',
				'description' => __( 'Summary with event_types array and total count.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_summary',
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
 * Execute callback for get-analytics-summary ability.
 *
 * @param array $input Input parameters.
 * @return array Summary data.
 */
function extrachill_analytics_ability_get_summary( $input ) {
	global $wpdb;

	$days       = isset( $input['days'] ) ? (int) $input['days'] : 28;
	$event_type = isset( $input['event_type'] ) ? sanitize_key( $input['event_type'] ) : '';

	$table  = extrachill_analytics_events_table();
	$where  = array( '1=1' );
	$values = array();

	if ( $days > 0 ) {
		$where[]  = 'created_at >= %s';
		$values[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	if ( ! empty( $event_type ) ) {
		$where[]  = 'event_type = %s';
		$values[] = $event_type;
	}

	$where_clause = implode( ' AND ', $where );

	$sql = "SELECT event_type, COUNT(*) as count FROM {$table} WHERE {$where_clause} GROUP BY event_type ORDER BY count DESC";

	if ( ! empty( $values ) ) {
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	} else {
		$results = $wpdb->get_results( $sql );
	}

	$event_types = array();
	$total       = 0;

	foreach ( $results as $row ) {
		$count       = (int) $row->count;
		$daily_avg   = $days > 0 ? round( $count / $days, 1 ) : $count;

		$event_types[] = array(
			'event_type' => $row->event_type,
			'count'      => $count,
			'daily_avg'  => $daily_avg,
		);

		$total += $count;
	}

	return array(
		'event_types' => $event_types,
		'total'       => $total,
		'days'        => $days,
		'period'      => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
	);
}
