<?php
/**
 * Taxonomy Registration
 *
 * Register extrachill taxonomies (location, artist, festival) for the data_machine_events post type.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachineEvents\Core\Event_Post_Type;

/**
 * Initialize taxonomy registration hooks
 */
function extrachill_events_init_taxonomy_registration() {
	add_action( 'registered_post_type', 'extrachill_events_on_registered_post_type', 10, 2 );
	add_action( 'registered_taxonomy', 'extrachill_events_on_registered_taxonomy', 10, 3 );
	add_action( 'init', 'extrachill_events_register_taxonomies', 20 );
	add_filter( 'data_machine_events_post_type_menu_items', 'extrachill_events_allow_taxonomy_menu_items' );
}

/**
 * Get taxonomies to register for events
 *
 * @return array Taxonomy slugs
 */
function extrachill_events_get_event_taxonomies() {
	return array( 'location', 'artist', 'festival' );
}

/**
 * Handle post type registration to register taxonomies
 *
 * @param string       $post_type        Post type slug.
 * @param WP_Post_Type $post_type_object Post type object.
 */
function extrachill_events_on_registered_post_type( $post_type, $post_type_object ) {
	if ( ! class_exists( 'DataMachineEvents\\Core\\Event_Post_Type' ) ) {
		return;
	}

	if ( $post_type !== Event_Post_Type::POST_TYPE ) {
		return;
	}

	extrachill_events_register_taxonomies();
}

/**
 * Handle taxonomy registration to register for events
 *
 * @param string       $taxonomy    Taxonomy slug.
 * @param string|array $object_type Object type(s).
 * @param array        $args        Taxonomy arguments.
 */
function extrachill_events_on_registered_taxonomy( $taxonomy, $object_type, $args ) {
	if ( ! class_exists( 'DataMachineEvents\\Core\\Event_Post_Type' ) ) {
		return;
	}

	if ( ! in_array( $taxonomy, extrachill_events_get_event_taxonomies(), true ) ) {
		return;
	}

	extrachill_events_register_taxonomies();
}

/**
 * Register extrachill taxonomies for event post type
 */
function extrachill_events_register_taxonomies() {
	if ( ! class_exists( 'DataMachineEvents\\Core\\Event_Post_Type' ) ) {
		return;
	}

	$post_type = Event_Post_Type::POST_TYPE;
	if ( ! post_type_exists( $post_type ) ) {
		return;
	}

	foreach ( extrachill_events_get_event_taxonomies() as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		register_taxonomy_for_object_type( $taxonomy, $post_type );
	}
}

/**
 * Allow event taxonomies in menu items
 *
 * @param array $allowed_items Allowed menu item types.
 * @return array Modified allowed items.
 */
function extrachill_events_allow_taxonomy_menu_items( $allowed_items ) {
	foreach ( extrachill_events_get_event_taxonomies() as $taxonomy ) {
		$allowed_items[ $taxonomy ] = true;
	}

	return $allowed_items;
}
