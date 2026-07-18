<?php
/**
 * Get Attack Summary Ability
 *
 * Read-side ability that summarizes search_attack events. Supports grouping by
 * pattern family, calendar day, source IP, or source URL.
 *
 * SCOPE: this counter measures injection probes submitted through the ON-SITE
 * SEARCH form (event_type='search_attack', written by the search-term
 * classifier in security-classifier.php). It does NOT measure the URL/path
 * scanner storm — those requests 404 without ever touching the search box and
 * are counted by the companion get-scanner-404-summary ability. Two distinct
 * attack surfaces, two distinct counters. A low/flat search_attack number means
 * on-site search injection is quiet, NOT that scanning has stopped — check
 * get-scanner-404-summary for the path-scanner volume.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_attack_summary_ability' );

/**
 * Register the get-attack-summary ability.
 */
function extrachill_analytics_register_attack_summary_ability() {
	wp_register_ability(
		'extrachill/get-attack-summary',
		array(
			'label'               => __( 'Get Attack Summary', 'extrachill-analytics' ),
			'description'         => __( 'Summarizes search_attack events by pattern, day, IP, or URL.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'     => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'group_by' => array(
						'type'        => 'string',
						'description' => __( 'Grouping dimension: pattern, day, ip, or url.', 'extrachill-analytics' ),
						'enum'        => array( 'pattern', 'day', 'ip', 'url' ),
						'default'     => 'pattern',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => __( 'Maximum rows to return. 0 for unlimited.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'blog_id'  => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Summary with rows array, totals, and period metadata.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_attack_summary',
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
 * Execute callback for get-attack-summary ability.
 *
 * @param array $input Input parameters.
 * @return array Summary data with shape:
 *   [
 *     'rows'      => [ ['key' => ..., 'count' => N, ...], ... ],
 *     'total'     => int,
 *     'group_by'  => string,
 *     'days'      => int,
 *     'period'    => string,
 *     'distinct'  => int,  // Number of distinct keys (rows before limit truncation)
 *   ]
 */
function extrachill_analytics_ability_get_attack_summary( $input ) {
	global $wpdb;

	$days     = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 28;
	$group_by = isset( $input['group_by'] ) ? (string) $input['group_by'] : 'pattern';
	$limit    = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 25;
	$blog_id  = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	$valid_groups = array( 'pattern', 'day', 'ip', 'url' );
	if ( ! in_array( $group_by, $valid_groups, true ) ) {
		$group_by = 'pattern';
	}

	$table  = extrachill_analytics_events_table();
	$where  = array( 'event_type = %s' );
	$values = array( EC_ANALYTICS_EVENT_SEARCH_ATTACK );

	if ( $days > 0 ) {
		$where[]  = 'created_at >= %s';
		$values[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	$where_clause = implode( ' AND ', $where );
	// Snapshot the WHERE-only values before we append a LIMIT placeholder for the
	// grouped query — count/distinct queries need just the WHERE values.
	$where_values = $values;

	// Build the GROUP BY expression depending on dimension. Uses MySQL JSON
	// functions for fields stored inside event_data.
	switch ( $group_by ) {
		case 'day':
			$select = 'DATE(created_at) AS grp_key, COUNT(*) AS cnt';
			$group  = 'DATE(created_at)';
			$order  = 'grp_key DESC';
			break;
		case 'ip':
			$select = "JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.ip')) AS grp_key, COUNT(*) AS cnt";
			$group  = "JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.ip'))";
			$order  = 'cnt DESC';
			break;
		case 'url':
			$select = 'source_url AS grp_key, COUNT(*) AS cnt';
			$group  = 'source_url';
			$order  = 'cnt DESC';
			break;
		case 'pattern':
		default:
			$select = "JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.classification')) AS grp_key, COUNT(*) AS cnt";
			$group  = "JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.classification'))";
			$order  = 'cnt DESC';
			break;
	}

	$sql          = "SELECT {$select} FROM {$table} WHERE {$where_clause} GROUP BY {$group} ORDER BY {$order}";
	$query_values = $values;
	$fetch_limit  = 0;
	if ( $limit > 0 ) {
		$sql           .= ' LIMIT %d';
		$fetch_limit    = $limit + 1; // Fetch one extra so we know if more exist.
		$query_values[] = $fetch_limit;
	}

	$results = empty( $query_values )
		? $wpdb->get_results( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: $wpdb->get_results( $wpdb->prepare( $sql, $query_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	$rows = array();
	foreach ( $results as $row ) {
		$rows[] = array(
			'key'   => null !== $row->grp_key ? (string) $row->grp_key : '(null)',
			'count' => (int) $row->cnt,
		);
	}

	$truncated = false;
	if ( $limit > 0 && count( $rows ) > $limit ) {
		$rows      = array_slice( $rows, 0, $limit );
		$truncated = true;
	}

	// Total + distinct across the entire filtered set (not just returned rows).
	$count_sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
	$grand_total = empty( $where_values )
		? (int) $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	$distinct_sql = "SELECT COUNT(DISTINCT {$group}) FROM {$table} WHERE {$where_clause}";
	$distinct     = empty( $where_values )
		? (int) $wpdb->get_var( $distinct_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: (int) $wpdb->get_var( $wpdb->prepare( $distinct_sql, $where_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	return array(
		'rows'      => $rows,
		'total'     => $grand_total,
		'distinct'  => $distinct,
		'group_by'  => $group_by,
		'days'      => $days,
		'period'    => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'truncated' => $truncated,
	);
}
