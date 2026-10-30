<?php
/**
 * Public recent Wire activity ability.
 *
 * @package ExtraChillNewsWire
 */

defined( 'ABSPATH' ) || exit;

const EC_NEWS_WIRE_RECENT_ACTIVITY_SCHEMA_VERSION = '1';
const EC_NEWS_WIRE_RECENT_ACTIVITY_DEFAULT_LIMIT  = 10;
const EC_NEWS_WIRE_RECENT_ACTIVITY_MAX_LIMIT      = 20;

add_action( 'wp_abilities_api_categories_init', 'ec_news_wire_register_ability_category' );
add_action( 'wp_abilities_api_init', 'ec_news_wire_register_recent_activity_ability' );

/**
 * Register the News Wire ability category.
 */
function ec_news_wire_register_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-news-wire' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-news-wire',
		array(
			'label'       => __( 'Extra Chill News Wire', 'extrachill' ),
			'description' => __( 'Public News Wire activity projections.', 'extrachill' ),
		)
	);
}

/**
 * Register the bounded recent activity projection.
 */
function ec_news_wire_register_recent_activity_ability() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill-news-wire/get-recent-activity',
		array(
			'label'               => __( 'Get Recent News Wire Activity', 'extrachill' ),
			'description'         => __( 'Returns a bounded, newest-first projection of public News Wire items for cross-site composition.', 'extrachill' ),
			'category'            => 'extrachill-news-wire',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'        => 'integer',
						'description' => __( 'Maximum number of recent items to return.', 'extrachill' ),
						'default'     => EC_NEWS_WIRE_RECENT_ACTIVITY_DEFAULT_LIMIT,
						'minimum'     => 1,
						'maximum'     => EC_NEWS_WIRE_RECENT_ACTIVITY_MAX_LIMIT,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => ec_news_wire_recent_activity_output_schema(),
			'execute_callback'    => 'ec_news_wire_get_recent_activity',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}

/**
 * Get the exact output schema for recent activity.
 *
 * @return array<string, mixed> JSON Schema definition.
 */
function ec_news_wire_recent_activity_output_schema() {
	$nullable_string = array( 'string', 'null' );

	return array(
		'type'                 => 'object',
		'required'             => array( 'schema_version', 'items' ),
		'properties'           => array(
			'schema_version' => array(
				'type'  => 'string',
				'const' => EC_NEWS_WIRE_RECENT_ACTIVITY_SCHEMA_VERSION,
			),
			'items'          => array(
				'type'     => 'array',
				'maxItems' => EC_NEWS_WIRE_RECENT_ACTIVITY_MAX_LIMIT,
				'items'    => array(
					'type'                 => 'object',
					'required'             => array( 'canonical_url', 'title', 'timestamp', 'image', 'summary', 'source', 'type' ),
					'properties'           => array(
						'canonical_url' => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'title'         => array( 'type' => 'string' ),
						'timestamp'     => array(
							'type'   => 'string',
							'format' => 'date-time',
						),
						'image'         => array(
							'type'   => $nullable_string,
							'format' => 'uri',
						),
						'summary'       => array( 'type' => $nullable_string ),
						'source'        => array(
							'type'  => 'string',
							'const' => 'extra-chill-news-wire',
						),
						'type'          => array(
							'type'  => 'string',
							'const' => 'festival-wire',
						),
					),
					'additionalProperties' => false,
				),
			),
		),
		'additionalProperties' => false,
	);
}

/**
 * Return recent public activity without exposing Wire storage details.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed> Versioned activity projection.
 */
function ec_news_wire_get_recent_activity( $input = array() ) {
	$limit = isset( $input['limit'] ) ? (int) $input['limit'] : EC_NEWS_WIRE_RECENT_ACTIVITY_DEFAULT_LIMIT;
	$limit = max( 1, min( EC_NEWS_WIRE_RECENT_ACTIVITY_MAX_LIMIT, $limit ) );

	$posts = get_posts(
		array(
			'post_type'      => 'festival_wire',
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	$items = array();
	foreach ( $posts as $post ) {
		if ( 'publish' !== get_post_status( $post ) || ! empty( $post->post_password ) ) {
			continue;
		}

		$summary = wp_strip_all_tags( get_the_excerpt( $post ) );
		$image   = get_the_post_thumbnail_url( $post, 'medium_large' );

		$items[] = array(
			'canonical_url' => get_permalink( $post ),
			'title'         => get_the_title( $post ),
			'timestamp'     => get_post_time( DATE_W3C, true, $post ),
			'image'         => $image ? $image : null,
			'summary'       => '' !== $summary ? $summary : null,
			'source'        => 'extra-chill-news-wire',
			'type'          => 'festival-wire',
		);
	}

	return array(
		'schema_version' => EC_NEWS_WIRE_RECENT_ACTIVITY_SCHEMA_VERSION,
		'items'          => array_slice( $items, 0, $limit ),
	);
}
