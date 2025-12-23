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

function extrachill_api_activity_get_taxonomy_badge_color_map() {
	static $badge_map = null;
	if ( null !== $badge_map ) {
		return $badge_map;
	}

	$badge_map = array();

	$css_path = get_stylesheet_directory() . '/assets/css/taxonomy-badges.css';
	if ( ! file_exists( $css_path ) ) {
		return $badge_map;
	}

	$css = file_get_contents( $css_path );
	if ( false === $css || '' === $css ) {
		return $badge_map;
	}

	preg_match_all( '/([^{}]+)\{([^}]*)\}/s', $css, $blocks, PREG_SET_ORDER );
	foreach ( $blocks as $block ) {
		if ( ! isset( $block[1], $block[2] ) ) {
			continue;
		}

		$selectors = (string) $block[1];
		$body      = (string) $block[2];

		if ( false === strpos( $selectors, '.taxonomy-badge.' ) ) {
			continue;
		}

		if ( ! preg_match( '/background-color\s*:\s*([^;]+);/i', $body, $bg_match ) ) {
			continue;
		}

		if ( ! preg_match( '/(?<!background-)color\s*:\s*([^;]+);/i', $body, $color_match ) ) {
			continue;
		}

		$background_color = trim( (string) $bg_match[1] );
		$text_color       = trim( (string) $color_match[1] );

		preg_match_all( '/\.taxonomy-badge\.([a-z0-9-]+)/i', $selectors, $selector_matches );
		if ( empty( $selector_matches[1] ) ) {
			continue;
		}

		foreach ( $selector_matches[1] as $selector_key ) {
			$selector_key = sanitize_key( $selector_key );
			if ( '' === $selector_key ) {
				continue;
			}

			$badge_map[ $selector_key ] = array(
				'background_color' => $background_color,
				'text_color'       => $text_color,
			);
		}
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
