<?php
/**
 * Ability: extrachill/community-taxonomy-counts
 *
 * Return a topic archive URL and topic count for a taxonomy term.
 *
 * Kept for existing consumers. Network cross-site linking uses the generic
 * extrachill/taxonomy-post-counts ability, which has the same topic semantics.
 *
 * @package ExtraChillCommunity
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_community_taxonomy_counts_ability' );

/**
 * Register the community-taxonomy-counts ability.
 */
function extrachill_community_register_community_taxonomy_counts_ability(): void {

	wp_register_ability(
		'extrachill/community-taxonomy-counts',
		array(
			'label'               => __( 'Community Taxonomy Counts', 'extra-chill-community' ),
			'description'         => __( 'Return topic archive URL and topic count for a taxonomy term.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => 'Taxonomy to query (e.g. location).',
					),
					'slug'     => array(
						'type'        => 'string',
						'description' => 'Term slug to look up.',
					),
				),
				'required'   => array( 'taxonomy', 'slug' ),
			),
			'output_schema'       => array(
				'anyOf' => array(
					array(
						'type'       => 'object',
						'properties' => array(
							'slug'  => array( 'type' => 'string' ),
							'name'  => array( 'type' => 'string' ),
							'count' => array( 'type' => 'integer' ),
							'url'   => array( 'type' => 'string' ),
						),
					),
					array( 'type' => 'null' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_community_taxonomy_counts',
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
 * Return topic archive data for a taxonomy term.
 *
 * @param array $input Ability input with 'taxonomy' and 'slug'.
 * @return array|null|WP_Error Topic archive data or null if not found.
 */
function extrachill_community_ability_community_taxonomy_counts( array $input ): array|null|WP_Error {
	$taxonomy = isset( $input['taxonomy'] ) ? sanitize_text_field( (string) $input['taxonomy'] ) : '';
	$slug     = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';

	if ( '' === $taxonomy || '' === $slug ) {
		return new WP_Error( 'missing_params', 'Both taxonomy and slug are required.', array( 'status' => 400 ) );
	}

	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$topic_query = new WP_Query(
		array(
			'post_type'      => 'topic',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
		)
	);
	if ( $topic_query->found_posts < 1 ) {
		return null;
	}

	$url = get_term_link( $term );
	if ( is_wp_error( $url ) ) {
		return null;
	}

	return array(
		'slug'  => $term->slug,
		'name'  => $term->name,
		'count' => (int) $topic_query->found_posts,
		'url'   => $url,
	);
}
