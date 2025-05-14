<?php
/**
 * Register Band Link Page Custom Post Type (extrch.co)
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

function extrch_register_band_link_page_cpt() {
    $labels = array(
        'name'               => __( 'Band Link Pages', 'generatepress_child' ),
        'singular_name'      => __( 'Band Link Page', 'generatepress_child' ),
        'add_new'            => __( 'Add New', 'generatepress_child' ),
        'add_new_item'       => __( 'Add New Band Link Page', 'generatepress_child' ),
        'edit_item'          => __( 'Edit Band Link Page', 'generatepress_child' ),
        'new_item'           => __( 'New Band Link Page', 'generatepress_child' ),
        'view_item'          => __( 'View Band Link Page', 'generatepress_child' ),
        'search_items'       => __( 'Search Band Link Pages', 'generatepress_child' ),
        'not_found'          => __( 'No Band Link Pages found', 'generatepress_child' ),
        'not_found_in_trash' => __( 'No Band Link Pages found in Trash', 'generatepress_child' ),
        'menu_name'          => __( 'Band Link Pages', 'generatepress_child' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true, // DEV: Temporarily public for easy development
        'publicly_queryable' => true, // DEV: Allow front-end query for testing
        'show_ui'            => true,  // Show in admin for management
        'show_in_menu'       => true, // DEV: Show in main admin menu for easy access
        'show_in_admin_bar'  => false,
        'show_in_nav_menus'  => false,
        'exclude_from_search'=> true,
        'has_archive'        => false, // No archive
        'rewrite'            => array('slug' => 'band-link-page'), // DEV: Enable pretty permalinks for testing
        'supports'           => array( 'title', 'custom-fields' ),
        'capability_type'    => 'post',
        'hierarchical'       => false,
        'taxonomies'         => array(), // No taxonomies
        'show_in_rest'       => true, // For future API use
    );

    register_post_type( 'band_link_page', $args );
}
add_action( 'init', 'extrch_register_band_link_page_cpt' ); 