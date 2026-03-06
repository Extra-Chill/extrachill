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
 * Get taxonomy badge color map from @extrachill/tokens.
 *
 * Reads the taxonomy-badge category from the tokens.json installed via npm
 * in the theme's node_modules. Returns a map of "{taxonomy}-{slug}" => colors.
 *
 * @return array Map of badge_key => array( 'background_color', 'text_color' ).
 */
function extrachill_api_activity_get_taxonomy_badge_color_map() {
	static $badge_map = null;
	if ( null !== $badge_map ) {
		return $badge_map;
	}

	$badge_map = array();

	// Read from @extrachill/tokens package in theme node_modules.
	$tokens_path = get_stylesheet_directory() . '/node_modules/@extrachill/tokens/tokens.json';

	// Fallback: check the multisite plugin for a bundled copy.
	if ( ! file_exists( $tokens_path ) ) {
		$tokens_path = WP_PLUGIN_DIR . '/extrachill-multisite/tokens.json';
	}

	if ( ! file_exists( $tokens_path ) ) {
		return $badge_map;
	}

	$json = file_get_contents( $tokens_path );
	if ( false === $json || '' === $json ) {
		return $badge_map;
	}

	$tokens = json_decode( $json, true );
	if ( ! is_array( $tokens ) || ! isset( $tokens['categories']['taxonomy-badge'] ) ) {
		return $badge_map;
	}

	foreach ( $tokens['categories']['taxonomy-badge'] as $key => $token ) {
		if ( ! isset( $token['bg'], $token['text'] ) ) {
			continue;
		}

		$badge_map[ $key ] = array(
			'background_color' => $token['bg'],
			'text_color'       => $token['text'],
		);
	}

	return $badge_map;
}

/**
 * Get taxonomy terms for a post, filtered to allowlist.
 *
 * @param WP_Post $post Post object.
 * @return array|null Taxonomy data keyed by taxonomy slug, or null if empty.
 */
function extrachill_api_activity_get_post_taxonomies( $post ) {
	$taxonomies = array();
	$badge_map  = extrachill_api_activity_get_taxonomy_badge_color_map();

	foreach ( EXTRACHILL_ACTIVITY_TAXONOMY_ALLOWLIST as $taxonomy ) {
		$terms = get_the_terms( $post->ID, $taxonomy );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$taxonomies[ $taxonomy ] = array_map( function ( $term ) use ( $taxonomy, $badge_map ) {
				$payload = array(
					'id'   => $term->term_id,
					'slug' => $term->slug,
					'name' => $term->name,
				);

				$badge_key = $taxonomy . '-' . $term->slug;
				if ( isset( $badge_map[ $badge_key ] ) ) {
					$payload['badge'] = $badge_map[ $badge_key ];
				}

				return $payload;
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
