<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', 'extrachill_register_ability_category' );

function extrachill_register_ability_category() {
	wp_register_ability_category(
		'data-retrieval',
		array(
			'label'       => __( 'Data Retrieval', 'extrachill-search' ),
			'description' => __( 'Abilities that retrieve and return data from the WordPress multisite network.', 'extrachill-search' ),
		)
	);
}

add_action( 'wp_abilities_api_init', 'extrachill_register_abilities' );

function extrachill_register_abilities() {
	wp_register_ability(
		'extrachill-search/multisite-search',
		array(
			'label'       => __( 'Multisite Search', 'extrachill-search' ),
			'description' => __( 'Search across all network sites in the WordPress multisite installation with relevance scoring.', 'extrachill-search' ),
			'category'    => 'data-retrieval',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'search_term' => array(
						'type'        => 'string',
						'description' => __( 'The search query to find matching content across network sites.', 'extrachill-search' ),
					),
					'site_urls'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Optional array of specific site URLs/domains to search. If empty, searches all network sites.', 'extrachill-search' ),
					),
					'limit'       => array(
						'type'        => 'integer',
						'description' => __( 'Maximum number of results to return.', 'extrachill-search' ),
						'default'     => 10,
					),
					'offset'      => array(
						'type'        => 'integer',
						'description' => __( 'Number of results to skip for pagination.', 'extrachill-search' ),
						'default'     => 0,
					),
					'post_status' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of post statuses to include in search results.', 'extrachill-search' ),
						'default'     => array( 'publish' ),
					),
					'orderby'     => array(
						'type'        => 'string',
						'description' => __( 'Field to sort results by.', 'extrachill-search' ),
						'default'     => 'date',
					),
					'order'       => array(
						'type'        => 'string',
						'description' => __( 'Sort direction (ASC or DESC).', 'extrachill-search' ),
						'default'     => 'DESC',
					),
					'return_count'=> array(
						'type'        => 'boolean',
						'description' => __( 'If true, returns array with results and total count keys. If false, returns results array only.', 'extrachill-search' ),
						'default'     => false,
					),
				),
				'required'   => array( 'search_term' ),
			),
			'output_schema' => array(
				'oneOf' => array(
					array(
						'type'        => 'array',
						'description' => __( 'Array of search result objects when return_count is false.', 'extrachill-search' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'ID'            => array( 'type' => 'integer', 'description' => 'Post ID' ),
								'post_title'    => array( 'type' => 'string', 'description' => 'Post title' ),
								'post_content'  => array( 'type' => 'string', 'description' => 'Full post content' ),
								'post_excerpt'  => array( 'type' => 'string', 'description' => 'Post excerpt' ),
								'post_date'     => array( 'type' => 'string', 'description' => 'Post publication date' ),
								'post_modified' => array( 'type' => 'string', 'description' => 'Post last modified date' ),
								'post_type'     => array( 'type' => 'string', 'description' => 'Post type' ),
								'post_name'     => array( 'type' => 'string', 'description' => 'Post slug' ),
								'post_author'   => array( 'type' => 'integer', 'description' => 'Author ID' ),
								'site_id'       => array( 'type' => 'integer', 'description' => 'Source blog ID' ),
								'site_name'     => array( 'type' => 'string', 'description' => 'Source site name' ),
								'site_url'      => array( 'type' => 'string', 'description' => 'Source site URL (host only)' ),
								'permalink'     => array( 'type' => 'string', 'description' => 'Full post URL' ),
								'taxonomies'    => array(
									'type' => 'object',
									'description' => 'Taxonomy terms organized by taxonomy name',
								),
								'thumbnail'     => array(
									'type'        => 'object',
									'description' => 'Featured image data',
									'properties' => array(
										'thumbnail_id'     => array( 'type' => 'integer' ),
										'thumbnail_url'    => array( 'type' => 'string' ),
										'thumbnail_srcset' => array( 'type' => 'string' ),
										'thumbnail_sizes'  => array( 'type' => 'string' ),
										'thumbnail_alt'    => array( 'type' => 'string' ),
									),
								),
								'_search_score' => array( 'type' => 'integer', 'description' => 'Relevance score (when searching)' ),
							),
						),
					),
					array(
						'type'        => 'object',
						'description' => __( 'Paginated results with total count when return_count is true.', 'extrachill-search' ),
						'properties' => array(
							'results' => array( 'type' => 'array', 'description' => 'Array of search results' ),
							'total'   => array( 'type' => 'integer', 'description' => 'Total number of matching results' ),
						),
					),
				),
			),
			'execute_callback'    => 'extrachill_ability_multisite_search',
			'permission_callback' => function () {
				return current_user_can( 'read' );
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'     => true,
					'idempotent'   => true,
					'destructive'   => false,
					'instructions'  => __( 'Search across all network sites using the provided search term. Returns paginated results sorted by relevance score and date.', 'extrachill-search' ),
				),
			),
		)
	);
}

function extrachill_ability_multisite_search( $input = array() ) {
	if ( empty( $input['search_term'] ) ) {
		return new WP_Error(
			'missing_search_term',
			__( 'Search term is required.', 'extrachill-search' ),
			array( 'status' => 400 )
		);
	}

	$search_term = $input['search_term'];
	$site_urls = isset( $input['site_urls'] ) ? $input['site_urls'] : array();
	$args = array(
		'limit'        => isset( $input['limit'] ) ? (int) $input['limit'] : 10,
		'offset'       => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
		'post_status'  => isset( $input['post_status'] ) ? $input['post_status'] : array( 'publish' ),
		'orderby'      => isset( $input['orderby'] ) ? $input['orderby'] : 'date',
		'order'        => isset( $input['order'] ) ? $input['order'] : 'DESC',
		'return_count' => isset( $input['return_count'] ) ? (bool) $input['return_count'] : false,
	);

	if ( ! function_exists( 'extrachill_multisite_search' ) ) {
		return new WP_Error(
			'search_function_unavailable',
			__( 'Multisite search function is not available.', 'extrachill-search' ),
			array( 'status' => 500 )
		);
	}

	$results = extrachill_multisite_search( $search_term, $site_urls, $args );

	if ( is_wp_error( $results ) ) {
		return $results;
	}

	return $results;
}
