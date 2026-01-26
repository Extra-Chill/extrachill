<?php
/**
 * Badge Styling
 *
 * Map datamachine-events badges to theme badge classes for festival/location/venue styling.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachineEvents\Core\Event_Post_Type;

/**
 * Initialize badge styling hooks
 */
function extrachill_events_init_badge_styling() {
	if ( ! class_exists( 'DataMachineEvents\\Blocks\\Calendar\\Taxonomy_Badges' ) ) {
		return;
	}

	add_filter( 'datamachine_events_badge_wrapper_classes', 'extrachill_events_add_wrapper_classes', 10, 2 );
	add_filter( 'datamachine_events_badge_classes', 'extrachill_events_add_badge_classes', 10, 4 );
	add_filter( 'datamachine_events_excluded_taxonomies', 'extrachill_events_exclude_taxonomies', 10, 2 );
}

/**
 * Add theme-compatible wrapper class to badge container
 *
 * @param array $wrapper_classes Default wrapper classes from datamachine-events.
 * @param int   $post_id         Event post ID.
 * @return array Enhanced wrapper classes with theme compatibility.
 */
function extrachill_events_add_wrapper_classes( $wrapper_classes, $post_id ) {
	$wrapper_classes[] = 'taxonomy-badges';
	return $wrapper_classes;
}

/**
 * Map festival/location taxonomies to theme badge classes
 *
 * Enables custom colors from theme's badge-colors.css via taxonomy-specific
 * classes (e.g., festival-bonnaroo, location-charleston).
 *
 * @param array   $badge_classes  Default badge classes from datamachine-events.
 * @param string  $taxonomy_slug  Taxonomy name (festival, venue, location, etc.).
 * @param WP_Term $term           The taxonomy term object.
 * @param int     $post_id        Event post ID.
 * @return array Enhanced badge classes with taxonomy-specific styling.
 */
function extrachill_events_add_badge_classes( $badge_classes, $taxonomy_slug, $term, $post_id ) {
	$badge_classes[] = 'taxonomy-badge';

	switch ( $taxonomy_slug ) {
		case 'festival':
			$badge_classes[] = 'festival-badge';
			$badge_classes[] = 'festival-' . esc_attr( $term->slug );
			break;

		case 'location':
			$badge_classes[] = 'location-badge';
			$badge_classes[] = 'location-' . esc_attr( $term->slug );
			break;

		case 'venue':
			$badge_classes[] = 'venue-badge';
			$badge_classes[] = 'venue-' . esc_attr( $term->slug );
			break;
	}

	return $badge_classes;
}

/**
 * Exclude taxonomies from badge and modal display
 *
 * Artist taxonomy excluded to prevent redundant display with artist-specific metadata.
 *
 * @param array  $excluded Array of taxonomy slugs to exclude.
 * @param string $context  Context identifier: 'badge', 'modal'.
 * @return array Enhanced exclusion array.
 */
function extrachill_events_exclude_taxonomies( $excluded, $context = '' ) {
	$excluded[] = 'artist';

	if ( $context !== 'modal' ) {
		return array_values( array_unique( $excluded ) );
	}

	if ( ! class_exists( 'DataMachineEvents\\Core\\Event_Post_Type' ) ) {
		return array_values( array_unique( $excluded ) );
	}

	$taxonomies = get_object_taxonomies( Event_Post_Type::POST_TYPE, 'names' );
	if ( empty( $taxonomies ) || is_wp_error( $taxonomies ) ) {
		return array_values( array_unique( $excluded ) );
	}

	foreach ( $taxonomies as $taxonomy_slug ) {
		if ( $taxonomy_slug === 'location' ) {
			continue;
		}

		$excluded[] = $taxonomy_slug;
	}

	return array_values( array_unique( $excluded ) );
}
