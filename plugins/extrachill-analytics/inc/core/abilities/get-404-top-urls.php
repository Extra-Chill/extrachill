<?php
/**
 * Get 404 Top URLs Ability
 *
 * Read-side ability that returns the most frequently hit 404 URLs
 * with hit counts, last-seen timestamps, and category classification.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-404-top-urls ability.
 */
function extrachill_analytics_register_404_top_urls_ability() {
	wp_register_ability(
		'extrachill/get-404-top-urls',
		array(
			'label'       => __( 'Get 404 Top URLs', 'extrachill-analytics' ),
			'description' => __( 'Returns the most frequently hit 404 URLs with counts and categories.', 'extrachill-analytics' ),
			'category'    => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'days'     => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back.', 'extrachill-analytics' ),
						'default'     => 7,
					),
					'blog_id'  => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => __( 'Maximum number of URLs to return.', 'extrachill-analytics' ),
						'default'     => 30,
					),
					'min_hits' => array(
						'type'        => 'integer',
						'description' => __( 'Minimum number of hits to include a URL.', 'extrachill-analytics' ),
						'default'     => 2,
					),
				),
			),
			'output_schema' => array(
				'type'        => 'array',
				'description' => __( 'Array of top 404 URLs with url, hits, last_seen, and category.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_404_top_urls',
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
 * Execute callback for get-404-top-urls ability.
 *
 * @param array $input Input parameters.
 * @return array Array of top URL objects.
 */
function extrachill_analytics_ability_get_404_top_urls( $input ) {
	global $wpdb;

	$days     = isset( $input['days'] ) ? (int) $input['days'] : 7;
	$blog_id  = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$limit    = isset( $input['limit'] ) ? (int) $input['limit'] : 30;
	$min_hits = isset( $input['min_hits'] ) ? (int) $input['min_hits'] : 2;

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

	$values[] = $min_hits;
	$values[] = $limit;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "SELECT
		JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url')) AS url,
		COUNT(*) AS hits,
		MAX(created_at) AS last_seen
		FROM {$table}
		WHERE {$where_clause}
		GROUP BY url
		HAVING hits >= %d
		ORDER BY hits DESC
		LIMIT %d";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$results = array();
	foreach ( $rows as $row ) {
		$results[] = array(
			'url'       => $row->url,
			'hits'      => (int) $row->hits,
			'last_seen' => $row->last_seen,
			'category'  => extrachill_analytics_categorize_404_url( $row->url ),
		);
	}

	return $results;
}
