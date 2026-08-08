<?php
/**
 * Public Events entity projections ability.
 *
 * @package ExtraChillEvents
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_public_entity_projections_ability' );

/** Register the canonical public presentation resolver. */
function extrachill_events_register_public_entity_projections_ability(): void {
	$item_input_schema  = array(
		'type'                 => 'object',
		'required'             => array( 'entity_type', 'slug' ),
		'additionalProperties' => false,
		'properties'           => array(
			'entity_type' => array(
				'type' => 'string',
				'enum' => array( 'venue', 'location', 'local_scene_digest' ),
			),
			'slug'        => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
				'pattern'   => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
			),
		),
	);
	$item_output_schema = array(
		'type'                 => 'object',
		'required'             => array( 'entity_type', 'slug', 'status', 'name', 'url' ),
		'additionalProperties' => false,
		'properties'           => array(
			'entity_type' => $item_input_schema['properties']['entity_type'],
			'slug'        => $item_input_schema['properties']['slug'],
			'status'      => array(
				'type' => 'string',
				'enum' => array( 'resolved', 'not_found' ),
			),
			'name'        => array( 'type' => 'string' ),
			'url'         => array( 'type' => 'string' ),
		),
	);

	wp_register_ability(
		'extrachill/events-public-entity-projections',
		array(
			'label'               => __( 'Public Events Entity Projections', 'extrachill-events' ),
			'description'         => __( 'Batch-resolves public names and canonical URLs for Events-owned subscription identities.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'schema_version', 'items' ),
				'additionalProperties' => false,
				'properties'           => array(
					'schema_version' => array(
						'type' => 'string',
						'enum' => array( '1' ),
					),
					'items'          => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => $item_input_schema,
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'schema_version', 'items' ),
				'additionalProperties' => false,
				'properties'           => array(
					'schema_version' => array(
						'type' => 'string',
						'enum' => array( '1' ),
					),
					'items'          => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => $item_output_schema,
					),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_public_entity_projections',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Resolve public Events-owned entity presentation in input order.
 *
 * @param array $input Validated ability input.
 * @return array|WP_Error Projection response or owner infrastructure failure.
 */
function extrachill_events_ability_public_entity_projections( array $input ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	$events_blog_id = (int) apply_filters( 'extrachill_events_canonical_blog_id', $events_blog_id );

	if ( $events_blog_id <= 0 || ( is_multisite() && ! get_site( $events_blog_id ) ) ) {
		return new \WP_Error( 'events_site_unavailable', __( 'The canonical Events site is unavailable.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$switched = get_current_blog_id() !== $events_blog_id;
	if ( $switched ) {
		switch_to_blog( $events_blog_id );
	}

	try {
		$requested = array();
		foreach ( $input['items'] as $item ) {
			$taxonomy                                = 'venue' === $item['entity_type'] ? 'venue' : 'location';
			$requested[ $taxonomy ][ $item['slug'] ] = true;
		}

		$terms_by_taxonomy = array();
		foreach ( $requested as $taxonomy => $slugs ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new \WP_Error(
					'events_taxonomy_unavailable',
					__( 'A canonical Events taxonomy is unavailable.', 'extrachill-events' ),
					array(
						'status'   => 500,
						'taxonomy' => $taxonomy,
					)
				);
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'slug'       => array_keys( $slugs ),
				)
			);
			if ( is_wp_error( $terms ) ) {
				return new \WP_Error(
					'events_entity_lookup_failed',
					$terms->get_error_message(),
					array(
						'status'   => 500,
						'taxonomy' => $taxonomy,
					)
				);
			}

			foreach ( $terms as $term ) {
				$terms_by_taxonomy[ $taxonomy ][ $term->slug ] = $term;
			}
		}

		$items = array();
		foreach ( $input['items'] as $item ) {
			$taxonomy = 'venue' === $item['entity_type'] ? 'venue' : 'location';
			$term     = $terms_by_taxonomy[ $taxonomy ][ $item['slug'] ] ?? null;
			$row      = array(
				'entity_type' => $item['entity_type'],
				'slug'        => $item['slug'],
				'status'      => 'not_found',
				'name'        => '',
				'url'         => '',
			);

			if ( ! $term instanceof \WP_Term || ! is_term_publicly_viewable( $term ) ) {
				$items[] = $row;
				continue;
			}

			if ( 'location' === $taxonomy ) {
				$location = extrachill_events_prepare_canonical_location( $term );
				if ( null === $location ) {
					$items[] = $row;
					continue;
				}
				$url = $location['archive_url'];
			} else {
				$url = get_term_link( $term );
			}

			if ( is_wp_error( $url ) || '' === $url ) {
				return new \WP_Error(
					'events_entity_url_failed',
					__( 'A canonical Events entity URL could not be generated.', 'extrachill-events' ),
					array(
						'status'   => 500,
						'taxonomy' => $taxonomy,
						'slug'     => $term->slug,
					)
				);
			}

			$row['status'] = 'resolved';
			$row['name']   = $term->name;
			$row['url']    = $url;
			$items[]       = $row;
		}

		return array(
			'schema_version' => '1',
			'items'          => $items,
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}
