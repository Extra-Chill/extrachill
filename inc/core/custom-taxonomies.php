<?php

/**
 * Custom Taxonomy Registration
 *
 * Registers music-focused taxonomies: location, festival, artist, venue.
 * All include REST API support for block editor integration.
 *
 * @package ExtraChill
 * @since 69.57
 */
function extra_chill_register_custom_taxonomies() {
    if (!taxonomy_exists('location')) {
        $location_labels = array(
            'name'              => _x( 'Locations', 'taxonomy general name', 'extrachill' ),
            'singular_name'     => _x( 'Location', 'taxonomy singular name', 'extrachill' ),
            'search_items'      => __( 'Search Locations', 'extrachill' ),
            'all_items'         => __( 'All Locations', 'extrachill' ),
            'parent_item'       => __( 'Parent Location', 'extrachill' ),
            'parent_item_colon' => __( 'Parent Location:', 'extrachill' ),
            'edit_item'         => __( 'Edit Location', 'extrachill' ),
            'update_item'       => __( 'Update Location', 'extrachill' ),
            'add_new_item'      => __( 'Add New Location', 'extrachill' ),
            'new_item_name'     => __( 'New Location Name', 'extrachill' ),
            'menu_name'         => __( 'Location', 'extrachill' ),
        );

        $location_args = array(
            'hierarchical'      => true,
            'labels'            => $location_labels,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_quick_edit'=> true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => array(
                'slug'         => 'location',
                'with_front'   => false,
                'hierarchical' => true,
            ),
        );

        register_taxonomy( 'location', array( 'post' ), $location_args );
    }

    if (!taxonomy_exists('festival')) {
        register_taxonomy('festival', array('post', 'festival_wire'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => _x('Festivals', 'taxonomy general name', 'extrachill'),
                'singular_name' => _x('Festival', 'taxonomy singular name', 'extrachill'),
                'search_items' => __('Search Festivals', 'extrachill'),
                'all_items' => __('All Festivals', 'extrachill'),
                'edit_item' => __('Edit Festival', 'extrachill'),
                'update_item' => __('Update Festival', 'extrachill'),
                'add_new_item' => __('Add New Festival', 'extrachill'),
                'new_item_name' => __('New Festival Name', 'extrachill'),
                'menu_name' => __('Festivals', 'extrachill'),
            ),
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'festival'),
            'show_in_rest' => true,
        ));
    }

    if (!taxonomy_exists('artist')) {
        register_taxonomy('artist', array('post'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => _x('Artists', 'taxonomy general name', 'extrachill'),
                'singular_name' => _x('Artist', 'taxonomy singular name', 'extrachill'),
                'search_items' => __('Search Artists', 'extrachill'),
                'all_items' => __('All Artists', 'extrachill'),
                'edit_item' => __('Edit Artist', 'extrachill'),
                'update_item' => __('Update Artist', 'extrachill'),
                'add_new_item' => __('Add New Artist', 'extrachill'),
                'new_item_name' => __('New Artist Name', 'extrachill'),
                'menu_name' => __('Artists', 'extrachill'),
            ),
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'artist'),
            'show_in_rest' => true,
        ));
    }

    if (!taxonomy_exists('venue')) {
        register_taxonomy('venue', array('post'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => _x('Venues', 'taxonomy general name', 'extrachill'),
                'singular_name' => _x('Venue', 'taxonomy singular name', 'extrachill'),
                'search_items' => __('Search Venues', 'extrachill'),
                'all_items' => __('All Venues', 'extrachill'),
                'edit_item' => __('Edit Venue', 'extrachill'),
                'update_item' => __('Update Venue', 'extrachill'),
                'add_new_item' => __('Add New Venue', 'extrachill'),
                'new_item_name' => __('New Venue Name', 'extrachill'),
                'menu_name' => __('Venues', 'extrachill'),
            ),
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'venue'),
            'show_in_rest' => true,
        ));
    }
}
add_action( 'init', 'extra_chill_register_custom_taxonomies', 0 );