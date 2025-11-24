<?php
/**
 * Single Event Related Events
 *
 * Handles related events logic for single event pages.
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Use venue and location taxonomies for event posts
 *
 * Order matters:
 * 1. venue - Shows upcoming events at same venue
 * 2. location - Shows upcoming events in same location (different venues)
 *
 * @hook extrachill_related_posts_taxonomies
 * @param array  $taxonomies Default taxonomies (artist, venue)
 * @param int    $post_id    Current post ID
 * @param string $post_type  Current post type
 * @return array Modified taxonomies for event posts
 * @since 0.1.0
 */
function ec_events_filter_related_taxonomies( $taxonomies, $post_id, $post_type ) {
	if ( get_current_blog_id() === 7 && $post_type === 'datamachine_events' ) {
		return array( 'venue', 'location' );
	}
	return $taxonomies;
}
add_filter( 'extrachill_related_posts_taxonomies', 'ec_events_filter_related_taxonomies', 10, 3 );

/**
 * Allow location taxonomy in related posts whitelist
 *
 * @hook extrachill_related_posts_allowed_taxonomies
 * @param array  $allowed   Default allowed taxonomies
 * @param string $post_type Current post type
 * @return array Modified allowed taxonomies
 * @since 0.1.0
 */
function ec_events_allow_related_taxonomies( $allowed, $post_type ) {
	if ( $post_type === 'datamachine_events' ) {
		return array_merge( $allowed, array( 'location' ) );
	}
	return $allowed;
}
add_filter( 'extrachill_related_posts_allowed_taxonomies', 'ec_events_allow_related_taxonomies', 10, 2 );

/**
 * Modify query for event posts: change post type and add upcoming events filter
 *
 * Only shows future events by adding meta_query comparing event datetime
 * to current datetime. Orders by event date ascending (soonest first).
 *
 * @hook extrachill_related_posts_query_args
 * @param array  $query_args Default query args
 * @param string $taxonomy   Current taxonomy being queried
 * @param int    $post_id    Current post ID
 * @param string $post_type  Current post type
 * @return array Modified query args for event posts
 * @since 0.1.0
 */
function ec_events_filter_related_query_args( $query_args, $taxonomy, $post_id, $post_type ) {
	if ( get_current_blog_id() !== 7 || $post_type !== 'datamachine_events' ) {
		return $query_args;
	}

	$query_args['post_type'] = 'datamachine_events';

	$query_args['meta_query'] = array(
		array(
			'key'     => '_datamachine_event_datetime',
			'value'   => current_time( 'mysql' ),
			'compare' => '>=',
			'type'    => 'DATETIME',
		),
	);

	$query_args['meta_key'] = '_datamachine_event_datetime';
	$query_args['orderby']  = 'meta_value';
	$query_args['order']    = 'ASC';

	return $query_args;
}
add_filter( 'extrachill_related_posts_query_args', 'ec_events_filter_related_query_args', 10, 4 );

/**
 * Exclude same venue when showing location-based related events
 *
 * When displaying location-based related events, exclude events at the same venue
 * to provide variety. This prevents showing duplicate venue events in both sections.
 *
 * @hook extrachill_related_posts_tax_query
 * @param array  $tax_query Tax query array
 * @param string $taxonomy  Current taxonomy being queried
 * @param int    $term_id   Current term ID
 * @param int    $post_id   Current post ID
 * @param string $post_type Current post type
 * @return array Modified tax query with venue exclusion for location queries
 * @since 0.1.0
 */
function ec_events_exclude_venue_from_location( $tax_query, $taxonomy, $term_id, $post_id, $post_type ) {
	if ( get_current_blog_id() !== 7 || $post_type !== 'datamachine_events' || $taxonomy !== 'location' ) {
		return $tax_query;
	}

	$venue_terms = get_the_terms( $post_id, 'venue' );
	if ( ! $venue_terms || is_wp_error( $venue_terms ) ) {
		return $tax_query;
	}

	$venue_term_ids = wp_list_pluck( $venue_terms, 'term_id' );

	$tax_query[] = array(
		'taxonomy' => 'venue',
		'field'    => 'term_id',
		'terms'    => $venue_term_ids,
		'operator' => 'NOT IN',
	);

	return $tax_query;
}
add_filter( 'extrachill_related_posts_tax_query', 'ec_events_exclude_venue_from_location', 10, 5 );
