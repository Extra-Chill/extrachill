<?php
/**
 * Drill 404 Category Ability
 *
 * Read-side ability that drills into a specific 404 category,
 * showing individual URLs and optional redirect suggestions.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the drill-404-category ability.
 */
function extrachill_analytics_register_drill_404_category_ability() {
	wp_register_ability(
		'extrachill/drill-404-category',
		array(
			'label'       => __( 'Drill 404 Category', 'extrachill-analytics' ),
			'description' => __( 'Drills into a specific 404 category showing individual URLs with optional redirect suggestions.', 'extrachill-analytics' ),
			'category'    => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'category' => array(
						'type'        => 'string',
						'description' => __( 'Category name to drill into (e.g. legacy-html, content, bot-probe).', 'extrachill-analytics' ),
					),
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
						'default'     => 20,
					),
				),
				'required' => array( 'category' ),
			),
			'output_schema' => array(
				'type'        => 'array',
				'description' => __( 'Array of URLs in the category with hits, last_seen, and optional slug/post_id/fixable fields.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_drill_404_category',
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
 * Execute callback for drill-404-category ability.
 *
 * @param array $input Input parameters.
 * @return array Array of URL objects within the requested category.
 */
function extrachill_analytics_ability_drill_404_category( $input ) {
	global $wpdb;

	$category = isset( $input['category'] ) ? sanitize_key( $input['category'] ) : '';
	$days     = isset( $input['days'] ) ? (int) $input['days'] : 7;
	$blog_id  = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$limit    = isset( $input['limit'] ) ? (int) $input['limit'] : 20;

	if ( empty( $category ) ) {
		return array();
	}

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
		COUNT(*) AS hits,
		MAX(created_at) AS last_seen
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

	// Drop URLs that already have an active redirect rule so solved 404s
	// don't keep resurfacing in the drill until their pre-fix rows age out.
	$rows = extrachill_analytics_exclude_redirected_404_rows( $rows );

	// Categories that are candidates for redirect suggestions.
	$redirect_categories = array( 'legacy-html', 'content', 'date-prefix' );
	$is_redirect_candidate = in_array( $category, $redirect_categories, true );

	// Filter by category and slice to limit.
	$results = array();
	foreach ( $rows as $row ) {
		$row_category = extrachill_analytics_categorize_404_url( $row->url );
		if ( $row_category !== $category ) {
			continue;
		}

		$item = array(
			'url'       => $row->url,
			'hits'      => (int) $row->hits,
			'last_seen' => $row->last_seen,
		);

		if ( $is_redirect_candidate ) {
			$slug    = extrachill_analytics_extract_404_slug( $row->url );
			$post_id = extrachill_analytics_find_post_by_slug( $slug );

			$item['slug']    = $slug;
			$item['post_id'] = $post_id ? $post_id : null;
			$item['fixable'] = false !== $post_id;
		}

		$results[] = $item;

		if ( count( $results ) >= $limit ) {
			break;
		}
	}

	return $results;
}
