<?php
/**
 * Purge 404 Events Ability
 *
 * Write-side ability that deletes old 404 error events
 * from the analytics database. Supports dry-run mode.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the purge-404-events ability.
 */
function extrachill_analytics_register_purge_404_events_ability() {
	wp_register_ability(
		'extrachill/purge-404-events',
		array(
			'label'               => __( 'Purge 404 Events', 'extrachill-analytics' ),
			'description'         => __( 'Deletes old 404 error events from the analytics database. Supports dry-run mode.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'    => array(
						'type'        => 'integer',
						'description' => __( 'Delete events older than this many days.', 'extrachill-analytics' ),
						'default'     => 30,
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, only count without deleting.', 'extrachill-analytics' ),
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with count of affected events and whether deletion occurred.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_purge_404_events',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => true,
				),
			),
		)
	);
}

/**
 * Execute callback for purge-404-events ability.
 *
 * @param array $input Input parameters.
 * @return array Result with count and deleted flag.
 */
function extrachill_analytics_ability_purge_404_events( $input ) {
	global $wpdb;

	$days    = isset( $input['days'] ) ? (int) $input['days'] : 30;
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$dry_run = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : true;

	$table  = extrachill_analytics_events_table();
	$where  = array( 'event_type = %s' );
	$values = array( EC_ANALYTICS_EVENT_404_ERROR );

	if ( $days > 0 ) {
		$where[]  = 'created_at < %s';
		$values[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	$where_clause = implode( ' AND ', $where );

	// Count matching events.
	// phpcs:disable WordPress.DB.PreparedSQL
	$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
	if ( ! empty( $values ) ) {
		$count = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
	} else {
		$count = (int) $wpdb->get_var( $count_sql );
	}

	if ( $dry_run || 0 === $count ) {
		return array(
			'count'   => $count,
			'deleted' => false,
		);
	}

	// Perform deletion.
	$delete_sql = "DELETE FROM {$table} WHERE {$where_clause}";
	if ( ! empty( $values ) ) {
		$wpdb->query( $wpdb->prepare( $delete_sql, $values ) );
	} else {
		$wpdb->query( $delete_sql );
	}
	// phpcs:enable WordPress.DB.PreparedSQL

	return array(
		'count'   => $count,
		'deleted' => true,
	);
}
