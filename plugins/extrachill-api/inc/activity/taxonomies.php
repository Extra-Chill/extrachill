<?php
/**
 * Activity taxonomy utilities.
 *
 * Centralizes taxonomy handling for the activity system including allowlist
 * management and helper functions for extracting taxonomy data from posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_ACTIVITY_TAXONOMY_ALLOWLIST', array(
	'category',
	'post_tag',
	'festival',
	'location',
	'venue',
	'artist',
	'promoter',
) );

/**
 * Get taxonomy terms for a post, filtered to allowlist.
 *
 * @param WP_Post $post Post object.
 * @return array|null Taxonomy data keyed by taxonomy slug, or null if empty.
 */
function extrachill_api_activity_get_post_taxonomies( $post ) {
	$taxonomies = array();

	foreach ( EXTRACHILL_ACTIVITY_TAXONOMY_ALLOWLIST as $taxonomy ) {
		$terms = get_the_terms( $post->ID, $taxonomy );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$taxonomies[ $taxonomy ] = array_map( function ( $term ) {
				return array(
					'id'   => $term->term_id,
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}, $terms );
		}
	}

	return ! empty( $taxonomies ) ? $taxonomies : null;
}

/**
 * Check if taxonomy is in allowlist.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return bool True if allowed.
 */
function extrachill_api_activity_is_taxonomy_allowed( $taxonomy ) {
	return in_array( $taxonomy, EXTRACHILL_ACTIVITY_TAXONOMY_ALLOWLIST, true );
}
