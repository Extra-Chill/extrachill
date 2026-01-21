<?php
/**
 * Festival Schema
 *
 * Outputs Event schema on festival taxonomy archives, using festival metadata
 * defined by the Extra Chill News Wire plugin.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the single location term for a festival archive.
 *
 * Festivals in production should have exactly one location term across all
 * posts attached to the festival.
 *
 * @param \WP_Term $festival_term Festival term.
 * @return \WP_Term|null Location term if exactly one exists.
 */
function ec_seo_get_festival_single_location_term( $festival_term ) {
	$post_types = get_taxonomy( 'festival' )->object_type;

	$post_ids = get_posts(
		array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => 'festival',
					'field'    => 'term_id',
					'terms'    => (int) $festival_term->term_id,
				),
			),
		)
	);

	if ( empty( $post_ids ) ) {
		return null;
	}

	$location_terms = wp_get_object_terms(
		$post_ids,
		'location',
		array(
			'fields' => 'all',
		)
	);

	if ( is_wp_error( $location_terms ) ) {
		return null;
	}

	$unique = array();
	foreach ( $location_terms as $location_term ) {
		if ( ! ( $location_term instanceof \WP_Term ) ) {
			continue;
		}
		$unique[ (int) $location_term->term_id ] = $location_term;
	}

	if ( 1 !== count( $unique ) ) {
		return null;
	}

	return array_values( $unique )[0];
}

add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		if ( ! is_tax( 'festival' ) ) {
			return $graph;
		}

		if ( ! function_exists( 'ec_news_wire_get_festival_metadata' ) ) {
			return $graph;
		}

		if ( ! taxonomy_exists( 'festival' ) ) {
			return $graph;
		}

		$term = get_queried_object();
		if ( ! ( $term instanceof \WP_Term ) ) {
			return $graph;
		}

		$festival_meta = ec_news_wire_get_festival_metadata( $term->slug );
		if ( ! $festival_meta ) {
			return $graph;
		}

		$festival_url = get_term_link( $term );
		if ( is_wp_error( $festival_url ) ) {
			return $graph;
		}

		$schema = array(
			'@type' => 'Event',
			'@id'   => $festival_url . '#festival',
			'name'  => $festival_meta['name'],
			'url'   => $festival_url,
		);

		if ( '' !== $festival_meta['description'] ) {
			$schema['description'] = wp_strip_all_tags( $festival_meta['description'] );
		}

		if ( '' !== $festival_meta['start_date'] ) {
			$schema['startDate'] = $festival_meta['start_date'];
		}

		if ( '' !== $festival_meta['end_date'] ) {
			$schema['endDate'] = $festival_meta['end_date'];
		}

		$location_term = ec_seo_get_festival_single_location_term( $term );
		if ( $location_term ) {
			$location_url = get_term_link( $location_term );
			if ( ! is_wp_error( $location_url ) ) {
				$schema['location'] = array(
					'@type' => 'Place',
					'name'  => $location_term->name,
					'url'   => $location_url,
				);
			}
		}

		$graph[] = $schema;

		return $graph;
	}
);