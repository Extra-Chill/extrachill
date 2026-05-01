<?php
/**
 * Get 404 Patterns Ability
 *
 * Read-side ability that aggregates 404 URLs by pattern category,
 * showing the distribution of error types.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-404-patterns ability.
 */
function extrachill_analytics_register_404_patterns_ability() {
	wp_register_ability(
		'extrachill/get-404-patterns',
		array(
			'label'       => __( 'Get 404 Patterns', 'extrachill-analytics' ),
			'description' => __( 'Aggregates 404 URLs by pattern category with hit counts and percentages.', 'extrachill-analytics' ),
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
				'type'        => 'array',
				'description' => __( 'Array of pattern categories with hits, unique_urls, pct, and actionable flag.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_404_patterns',
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
 * Execute callback for get-404-patterns ability.
 *
 * @param array $input Input parameters.
 * @return array Array of category aggregation objects.
 */
function extrachill_analytics_ability_get_404_patterns( $input ) {
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

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "SELECT
		JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url')) AS url,
		COUNT(*) AS hits
		FROM {$table}
		WHERE {$where_clause}
		GROUP BY url
		ORDER BY hits DESC";

	if ( ! empty( $values ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	} else {
		$rows = $wpdb->get_results( $sql );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Aggregate by category.
	$categories  = array();
	$total_hits  = 0;

	foreach ( $rows as $row ) {
		$category = extrachill_analytics_categorize_404_url( $row->url );
		$hits     = (int) $row->hits;
		$total_hits += $hits;

		if ( ! isset( $categories[ $category ] ) ) {
			$categories[ $category ] = array(
				'hits'        => 0,
				'unique_urls' => 0,
			);
		}

		$categories[ $category ]['hits']        += $hits;
		$categories[ $category ]['unique_urls'] += 1;
	}

	// Build output sorted by hits descending.
	$results = array();
	foreach ( $categories as $category => $data ) {
		$results[] = array(
			'category'    => $category,
			'hits'        => $data['hits'],
			'unique_urls' => $data['unique_urls'],
			'pct'         => $total_hits > 0 ? round( ( $data['hits'] / $total_hits ) * 100, 1 ) : 0.0,
			'actionable'  => extrachill_analytics_is_actionable_404_category( $category ),
		);
	}

	usort(
		$results,
		function ( $a, $b ) {
			return $b['hits'] - $a['hits'];
		}
	);

	return $results;
}
