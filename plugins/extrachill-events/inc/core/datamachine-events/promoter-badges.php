<?php
/**
 * Promoter Badges
 *
 * Skip duplicate promoter badges when promoter matches venue name.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize promoter badge hooks
 */
function extrachill_events_init_promoter_badges() {
	add_filter( 'extrachill_taxonomy_badges_skip_term', 'extrachill_events_skip_duplicate_promoter', 10, 4 );
}

/**
 * Skip promoter badge if name matches venue name
 *
 * Prevents redundant display when promoter and venue are the same entity.
 * Mirrors logic from datamachine-events Calendar block Taxonomy_Badges.
 *
 * @param bool    $skip     Whether to skip this term.
 * @param WP_Term $term     The term being rendered.
 * @param string  $taxonomy The taxonomy slug.
 * @param int     $post_id  The post ID.
 * @return bool True to skip promoter matching venue, unchanged otherwise.
 */
function extrachill_events_skip_duplicate_promoter( $skip, $term, $taxonomy, $post_id ) {
	if ( $taxonomy !== 'promoter' ) {
		return $skip;
	}

	$venue_terms = get_the_terms( $post_id, 'venue' );
	if ( ! $venue_terms || is_wp_error( $venue_terms ) ) {
		return $skip;
	}

	$venue_name = $venue_terms[0]->name;
	if ( strcasecmp( trim( $term->name ), trim( $venue_name ) ) === 0 ) {
		return true;
	}

	return $skip;
}
