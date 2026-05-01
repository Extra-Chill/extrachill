<?php
/**
 * Get 404 Summary Ability
 *
 * Read-side ability that returns 404 error summary statistics
 * including totals, unique counts, and daily breakdown.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-404-summary ability.
 */
function extrachill_analytics_register_404_summary_ability() {
	wp_register_ability(
		'extrachill/get-404-summary',
		array(
			'label'       => __( 'Get 404 Summary', 'extrachill-analytics' ),
			'description' => __( 'Returns 404 error summary statistics with totals, unique counts, and daily breakdown.', 'extrachill-analytics' ),
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
				),
			),
			'output_schema' => array(
				'type'        => 'object',
				'description' => __( 'Summary with total, unique URLs/IPs, daily average, and daily breakdown.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_404_summary',
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
 * Execute callback for get-404-summary ability.
 *
 * @param array $input Input parameters.
 * @return array Summary data.
 */
function extrachill_analytics_ability_get_404_summary( $input ) {
	global $wpdb;

	$days    = isset( $input['days'] ) ? (int) $input['days'] : 7;
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	$table  = extrachill_analytics_events_table();
	$where  = array( "event_type = '404_error'" );
	$values = array();

	if ( $days > 0 ) {
		$where[]  = 'created_at >= %s';
		$values[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	$where_clause = implode( ' AND ', $where );

	// Total count.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
	if ( ! empty( $values ) ) {
		$total = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $values ) );
	} else {
		$total = (int) $wpdb->get_var( $total_sql );
	}

	// Unique URLs.
	$unique_urls_sql = "SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url'))) FROM {$table} WHERE {$where_clause}";
	if ( ! empty( $values ) ) {
		$unique_urls = (int) $wpdb->get_var( $wpdb->prepare( $unique_urls_sql, $values ) );
	} else {
		$unique_urls = (int) $wpdb->get_var( $unique_urls_sql );
	}

	// Unique IPs.
	$unique_ips_sql = "SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.ip_hash'))) FROM {$table} WHERE {$where_clause}";
	if ( ! empty( $values ) ) {
		$unique_ips = (int) $wpdb->get_var( $wpdb->prepare( $unique_ips_sql, $values ) );
	} else {
		$unique_ips = (int) $wpdb->get_var( $unique_ips_sql );
	}

	// Daily breakdown.
	$daily_sql = "SELECT DATE(created_at) as date, COUNT(*) as hits FROM {$table} WHERE {$where_clause} GROUP BY DATE(created_at) ORDER BY date DESC";
	if ( ! empty( $values ) ) {
		$daily_rows = $wpdb->get_results( $wpdb->prepare( $daily_sql, $values ) );
	} else {
		$daily_rows = $wpdb->get_results( $daily_sql );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$daily = array();
	foreach ( $daily_rows as $row ) {
		$daily[] = array(
			'date' => $row->date,
			'hits'  => (int) $row->hits,
		);
	}

	$per_day_avg = $days > 0 ? round( $total / $days, 2 ) : (float) $total;

	return array(
		'total'       => $total,
		'unique_urls' => $unique_urls,
		'unique_ips'  => $unique_ips,
		'per_day_avg' => $per_day_avg,
		'days'        => $days,
		'period'      => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'daily'       => $daily,
	);
}
