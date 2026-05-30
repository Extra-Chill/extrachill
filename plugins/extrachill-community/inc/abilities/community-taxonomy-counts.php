<?php
/**
 * Ability: extrachill/community-taxonomy-counts
 *
 * Return forum URL and topic count for a taxonomy term.
 * Used by cross-site linking — community forums are location hubs.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
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
			'description'         => __( 'Return forum URL and topic count for a taxonomy term (cross-site linking).', 'extra-chill-community' ),
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
 * Return forum data for a taxonomy term.
 *
 * Forums are location hubs, so we return the forum permalink and topic count
 * rather than a taxonomy archive URL.
 *
 * @param array $input Ability input with 'taxonomy' and 'slug'.
 * @return array|null|WP_Error Forum data or null if not found.
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

	$forums = get_posts(
		array(
			'post_type'      => 'forum',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
		)
	);

	if ( empty( $forums ) ) {
		return null;
	}

	$forum = $forums[0];

	$topic_query = new WP_Query(
		array(
			'post_type'      => 'topic',
			'post_status'    => 'publish',
			'post_parent'    => $forum->ID,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);
	$topic_count = $topic_query->found_posts;

	return array(
		'slug'  => $term->slug,
		'name'  => $term->name,
		'count' => (int) $topic_count,
		'url'   => get_permalink( $forum ),
	);
}
