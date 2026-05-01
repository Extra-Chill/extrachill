<?php
/**
 * Get 404 Top IPs Ability
 *
 * Read-side ability that returns the top IP addresses (hashed)
 * generating 404 errors with hit counts and metadata.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-404-top-ips ability.
 */
function extrachill_analytics_register_404_top_ips_ability() {
	wp_register_ability(
		'extrachill/get-404-top-ips',
		array(
			'label'       => __( 'Get 404 Top IPs', 'extrachill-analytics' ),
			'description' => __( 'Returns the top IP addresses (hashed) generating 404 errors with hit counts and metadata.', 'extrachill-analytics' ),
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
						'description' => __( 'Maximum number of IPs to return.', 'extrachill-analytics' ),
						'default'     => 15,
					),
				),
			),
			'output_schema' => array(
				'type'        => 'array',
				'description' => __( 'Array of top IP hashes with hits, unique_urls, first_seen, last_seen, and top_ua.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_404_top_ips',
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
 * Execute callback for get-404-top-ips ability.
 *
 * @param array $input Input parameters.
 * @return array Array of top IP objects.
 */
function extrachill_analytics_ability_get_404_top_ips( $input ) {
	global $wpdb;

	$days    = isset( $input['days'] ) ? (int) $input['days'] : 7;
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$limit   = isset( $input['limit'] ) ? (int) $input['limit'] : 15;

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

	$values[] = $limit;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "SELECT
		JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.ip_hash')) AS ip_hash,
		COUNT(*) AS hits,
		COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url'))) AS unique_urls,
		MIN(created_at) AS first_seen,
		MAX(created_at) AS last_seen
		FROM {$table}
		WHERE {$where_clause}
		GROUP BY ip_hash
		ORDER BY hits DESC
		LIMIT %d";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$results = array();
	foreach ( $rows as $row ) {
		// Second pass: find the most common user agent for this IP.
		$ua_values = array();
		$ua_where  = array( "event_type = '404_error'" );

		$ua_where[]  = "JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.ip_hash')) = %s";
		$ua_values[] = $row->ip_hash;

		if ( $days > 0 ) {
			$ua_where[]  = 'created_at >= %s';
			$ua_values[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		}

		if ( $blog_id > 0 ) {
			$ua_where[]  = 'blog_id = %d';
			$ua_values[] = $blog_id;
		}

		$ua_where_clause = implode( ' AND ', $ua_where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ua_sql = "SELECT
			JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.user_agent')) AS ua,
			COUNT(*) AS cnt
			FROM {$table}
			WHERE {$ua_where_clause}
			GROUP BY ua
			ORDER BY cnt DESC
			LIMIT 1";

		$top_ua_row = $wpdb->get_row( $wpdb->prepare( $ua_sql, $ua_values ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results[] = array(
			'ip_hash'     => $row->ip_hash,
			'hits'        => (int) $row->hits,
			'unique_urls' => (int) $row->unique_urls,
			'first_seen'  => $row->first_seen,
			'last_seen'   => $row->last_seen,
			'top_ua'      => $top_ua_row ? $top_ua_row->ua : '',
		);
	}

	return $results;
}
