<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-taxonomy-counts
 *
 * Return taxonomy term counts for shop products.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_taxonomy_counts_ability' );

/**
 * Register the shop-taxonomy-counts ability.
 */
function extrachill_shop_register_taxonomy_counts_ability(): void {

	wp_register_ability(
		'extrachill/shop-taxonomy-counts',
		array(
			'label'       => __( 'Shop Taxonomy Counts', 'extrachill-shop' ),
			'description' => __( 'Return taxonomy term counts for shop products. Supports querying by slug or bulk listing.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy' => array(
						'type'        => 'string',
						'enum'        => array( 'artist' ),
						'description' => 'Taxonomy to query (currently only artist supported).',
					),
					'slug'     => array(
						'type'        => 'string',
						'description' => 'Specific term slug. If provided, returns single term data.',
					),
					'limit'    => array(
						'type'        => 'integer',
						'default'     => 8,
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'Max terms to return for bulk queries.',
					),
				),
				'required' => array( 'taxonomy' ),
			),
			'output_schema' => array(
				'anyOf' => array(
					array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					array( 'type' => 'object' ),
					array( 'type' => 'null' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_taxonomy_counts',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Return taxonomy term counts.
 *
 * @param array $input Ability input.
 * @return array|null|WP_Error
 */
function extrachill_shop_ability_taxonomy_counts( array $input ): array|null|WP_Error {
	$taxonomy = (string) ( $input['taxonomy'] ?? 'artist' );
	$slug     = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
	$limit    = (int) ( $input['limit'] ?? 8 );
	$limit    = max( 1, min( 50, $limit ) );

	// Single term query.
	if ( ! empty( $slug ) ) {
		return extrachill_shop_ability_get_single_term_count( $slug, $taxonomy );
	}

	// Bulk query — check transient cache first.
	$cache_key = 'ec_shop_counts_' . $taxonomy;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached && is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	// Cold cache — delegate to existing ability if available.
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/taxonomy-post-counts' ) : null;

	if ( $ability ) {
		$result = $ability->execute( array(
			'taxonomy'  => $taxonomy,
			'site'      => 'shop',
			'post_type' => 'product',
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$terms = $result['terms'] ?? array();
		set_transient( $cache_key, $terms, 6 * HOUR_IN_SECONDS );

		return array_slice( $terms, 0, $limit );
	}

	// Fallback: query terms directly.
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}

	$terms = get_terms( array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => true,
		'number'     => $limit,
		'orderby'    => 'count',
		'order'      => 'DESC',
	) );

	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	$result = array();
	foreach ( $terms as $term ) {
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			continue;
		}
		$result[] = array(
			'term_id' => $term->term_id,
			'slug'    => $term->slug,
			'name'    => $term->name,
			'count'   => $term->count,
			'url'     => $url,
		);
	}

	set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );

	return array_slice( $result, 0, $limit );
}

/**
 * Get product count for a single term.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy slug.
 * @return array|null Term data or null.
 */
function extrachill_shop_ability_get_single_term_count( string $slug, string $taxonomy ): ?array {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$query = new WP_Query( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => false,
		'tax_query'      => array(
			array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term->term_id,
			),
		),
	) );

	if ( $query->found_posts < 1 ) {
		return null;
	}

	$url = get_term_link( $term );
	if ( is_wp_error( $url ) ) {
		return null;
	}

	return array(
		'term_id' => $term->term_id,
		'slug'    => $term->slug,
		'name'    => $term->name,
		'count'   => $query->found_posts,
		'url'     => $url,
	);
}
