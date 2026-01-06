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

add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		if ( ! is_tax( 'promoter' ) ) {
			return $graph;
		}

		if ( ! class_exists( '\DataMachineEvents\Core\Promoter_Taxonomy' ) ) {
			return $graph;
		}

		$term          = get_queried_object();
		$promoter_data = \DataMachineEvents\Core\Promoter_Taxonomy::get_promoter_data( $term->term_id );

		if ( empty( $promoter_data ) ) {
			return $graph;
		}

		$promoter_url = get_term_link( $term );
		$schema_type  = ! empty( $promoter_data['type'] ) ? $promoter_data['type'] : 'Organization';

		$promoter_schema = [
			'@type' => $schema_type,
			'@id'   => $promoter_url . '#promoter',
			'name'  => $promoter_data['name'],
			'url'   => $promoter_url,
		];

		if ( ! empty( $promoter_data['description'] ) ) {
			$promoter_schema['description'] = wp_strip_all_tags( $promoter_data['description'] );
		}

		if ( ! empty( $promoter_data['url'] ) ) {
			$promoter_schema['sameAs'] = [ $promoter_data['url'] ];
		}

		$graph[] = $promoter_schema;

		return $graph;
	}
);
