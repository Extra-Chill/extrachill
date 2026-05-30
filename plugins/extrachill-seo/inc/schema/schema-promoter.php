<?php
/**
 * Organization/Person Schema for Promoter Taxonomy Archives
 *
 * Outputs Organization or Person schema on promoter taxonomy archive pages.
 * Type is determined by promoter's _promoter_type meta field.
 * Requires datamachine-events plugin for promoter data functions.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the Organization/Person schema entity for a promoter taxonomy term.
 *
 * @param \WP_Term $term Promoter term.
 * @return array|null Promoter entity, or null when promoter data is unavailable.
 */
function ec_seo_build_promoter_schema( \WP_Term $term ): ?array {
	// Uses data-machine-events public integration API. See data-machine-events
	// docs/integration-api.md.
	if ( ! function_exists( 'data_machine_events_get_promoter_data' ) ) {
		return null;
	}

	$promoter_data = data_machine_events_get_promoter_data( (int) $term->term_id );

	if ( empty( $promoter_data ) ) {
		return null;
	}

	$promoter_url = get_term_link( $term );
	$schema_type  = ! empty( $promoter_data['type'] ) ? $promoter_data['type'] : 'Organization';

	$promoter_schema = array(
		'@type' => $schema_type,
		'@id'   => $promoter_url . '#promoter',
		'name'  => $promoter_data['name'],
		'url'   => $promoter_url,
	);

	if ( ! empty( $promoter_data['description'] ) ) {
		$promoter_schema['description'] = wp_strip_all_tags( $promoter_data['description'] );
	}

	if ( ! empty( $promoter_data['url'] ) ) {
		$promoter_schema['sameAs'] = array( $promoter_data['url'] );
	}

	return $promoter_schema;
}

/**
 * Append promoter schema to the graph on promoter taxonomy archives.
 *
 * @param array $graph Current schema graph.
 * @return array Graph with promoter entity appended when applicable.
 */
function ec_seo_emit_promoter_schema( $graph ) {
	return ec_seo_register_taxonomy_schema( $graph, 'promoter', __NAMESPACE__ . '\\ec_seo_build_promoter_schema' );
}
add_filter( 'extrachill_seo_schema_graph', __NAMESPACE__ . '\\ec_seo_emit_promoter_schema' );
