<?php
/**
 * Public Blog-owned entity projections.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_BLOG_PUBLIC_ENTITY_PROJECTION_SCHEMA_VERSION = '1';
const EXTRACHILL_BLOG_PUBLIC_ENTITY_PROJECTION_MAX_ITEMS      = 100;

add_action( 'wp_abilities_api_categories_init', 'extrachill_blog_register_public_projection_ability_category' );
add_action( 'wp_abilities_api_init', 'extrachill_blog_register_public_entity_projection_ability' );

/**
 * Register the Blog ability category.
 *
 * @return void
 */
function extrachill_blog_register_public_projection_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-blog' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-blog',
		array(
			'label'       => __( 'Extra Chill Blog', 'extrachill-blog' ),
			'description' => __( 'Public projections owned by the Extra Chill publication.', 'extrachill-blog' ),
		)
	);
}

/**
 * Register the bounded public entity projection ability.
 *
 * @return void
 */
function extrachill_blog_register_public_entity_projection_ability() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$item_input_schema = array(
		'type'                 => 'object',
		'required'             => array( 'entity_type', 'slug' ),
		'properties'           => array(
			'entity_type' => array(
				'type' => 'string',
				'enum' => array( 'festival' ),
			),
			'slug'        => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
				'pattern'   => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
			),
		),
		'additionalProperties' => false,
	);

	wp_register_ability(
		'extrachill/blog-public-entity-projections',
		array(
			'label'               => __( 'Get Public Blog Entity Projections', 'extrachill-blog' ),
			'description'         => __( 'Resolve canonical public Blog presentation for an ordered batch of entity slugs.', 'extrachill-blog' ),
			'category'            => 'extrachill-blog',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'schema_version', 'items' ),
				'properties'           => array(
					'schema_version' => array(
						'type' => 'string',
						'enum' => array( EXTRACHILL_BLOG_PUBLIC_ENTITY_PROJECTION_SCHEMA_VERSION ),
					),
					'items'          => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => EXTRACHILL_BLOG_PUBLIC_ENTITY_PROJECTION_MAX_ITEMS,
						'items'    => $item_input_schema,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => extrachill_blog_public_entity_projection_output_schema(),
			'execute_callback'    => 'extrachill_blog_get_public_entity_projections',
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
 * Get the exact version-one output schema.
 *
 * @return array<string, mixed> JSON Schema definition.
 */
function extrachill_blog_public_entity_projection_output_schema() {
	$common_properties = array(
		'entity_type' => array(
			'type' => 'string',
			'enum' => array( 'festival' ),
		),
		'slug'        => array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
			'pattern'   => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
		),
	);
	$required          = array( 'entity_type', 'slug', 'status', 'name', 'url' );

	$resolved_properties           = $common_properties;
	$resolved_properties['status'] = array(
		'type' => 'string',
		'enum' => array( 'resolved' ),
	);
	$resolved_properties['name']   = array(
		'type'      => 'string',
		'minLength' => 1,
	);
	$resolved_properties['url']    = array(
		'type'   => 'string',
		'format' => 'uri',
	);

	$missing_properties           = $common_properties;
	$missing_properties['status'] = array(
		'type' => 'string',
		'enum' => array( 'not_found' ),
	);
	$missing_properties['name']   = array(
		'type'      => 'string',
		'maxLength' => 0,
	);
	$missing_properties['url']    = array(
		'type'      => 'string',
		'maxLength' => 0,
	);

	return array(
		'type'                 => 'object',
		'required'             => array( 'schema_version', 'items' ),
		'properties'           => array(
			'schema_version' => array(
				'type' => 'string',
				'enum' => array( EXTRACHILL_BLOG_PUBLIC_ENTITY_PROJECTION_SCHEMA_VERSION ),
			),
			'items'          => array(
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => EXTRACHILL_BLOG_PUBLIC_ENTITY_PROJECTION_MAX_ITEMS,
				'items'    => array(
					'oneOf' => array(
						array(
							'type'                 => 'object',
							'required'             => $required,
							'properties'           => $resolved_properties,
							'additionalProperties' => false,
						),
						array(
							'type'                 => 'object',
							'required'             => $required,
							'properties'           => $missing_properties,
							'additionalProperties' => false,
						),
					),
				),
			),
		),
		'additionalProperties' => false,
	);
}

/**
 * Resolve public Blog-owned entity presentation in input order.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|WP_Error Versioned projection or owner error.
 */
function extrachill_blog_get_public_entity_projections( $input ) {
	$is_main_site = function_exists( 'ec_get_current_site_key' )
		? 'main' === ec_get_current_site_key()
		: 1 === (int) get_current_blog_id();
	if ( ! $is_main_site ) {
		return new WP_Error( 'extrachill_blog_projection_owner_unavailable', __( 'The Blog projection owner is unavailable.', 'extrachill-blog' ) );
	}

	if ( ! taxonomy_exists( 'festival' ) ) {
		return new WP_Error( 'extrachill_blog_projection_taxonomy_unavailable', __( 'Festival projection infrastructure is unavailable.', 'extrachill-blog' ) );
	}

	$taxonomy = get_taxonomy( 'festival' );
	if ( ! $taxonomy ) {
		return new WP_Error( 'extrachill_blog_projection_taxonomy_unavailable', __( 'Festival projection infrastructure is unavailable.', 'extrachill-blog' ) );
	}

	$items = array();
	foreach ( array_slice( $input['items'], 0, EXTRACHILL_BLOG_PUBLIC_ENTITY_PROJECTION_MAX_ITEMS ) as $requested ) {
		$slug = (string) $requested['slug'];
		$item = array(
			'entity_type' => 'festival',
			'slug'        => $slug,
			'status'      => 'not_found',
			'name'        => '',
			'url'         => '',
		);

		if ( empty( $taxonomy->public ) ) {
			$items[] = $item;
			continue;
		}

		$term = get_term_by( 'slug', $slug, 'festival' );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		if ( ! ( $term instanceof WP_Term ) || (int) $term->count < 1 ) {
			$items[] = $item;
			continue;
		}

		$url = get_term_link( $term, 'festival' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		if ( ! is_string( $url ) || '' === $url ) {
			return new WP_Error( 'extrachill_blog_projection_permalink_unavailable', __( 'The festival canonical permalink is unavailable.', 'extrachill-blog' ) );
		}

		$item['status'] = 'resolved';
		$item['name']   = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
		$item['url']    = $url;
		$items[]        = $item;
	}

	return array(
		'schema_version' => EXTRACHILL_BLOG_PUBLIC_ENTITY_PROJECTION_SCHEMA_VERSION,
		'items'          => $items,
	);
}
