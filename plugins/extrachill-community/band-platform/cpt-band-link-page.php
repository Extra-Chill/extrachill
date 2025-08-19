<?php
/**
 * Register Band Link Page Custom Post Type
 *
 * @package ExtrchCo
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

function bp_register_band_link_page_cpt() {
    $labels = array(
        'name'               => __( 'Band Link Pages', 'extra-chill-community' ),
        'singular_name'      => __( 'Band Link Page', 'extra-chill-community' ),
        'add_new'            => __( 'Add New', 'extra-chill-community' ),
        'add_new_item'       => __( 'Add New Band Link Page', 'extra-chill-community' ),
        'edit_item'          => __( 'Edit Band Link Page', 'extra-chill-community' ),
        'new_item'           => __( 'New Band Link Page', 'extra-chill-community' ),
        'view_item'          => __( 'View Band Link Page', 'extra-chill-community' ),
        'search_items'       => __( 'Search Band Link Pages', 'extra-chill-community' ),
        'not_found'          => __( 'No Band Link Pages found', 'extra-chill-community' ),
        'not_found_in_trash' => __( 'No Band Link Pages found in Trash', 'extra-chill-community' ),
        'menu_name'          => __( 'Band Link Pages', 'extra-chill-community' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true, // DEV: Temporarily public for testing
        'publicly_queryable' => true, // DEV: Allow front-end query for testing
        'show_ui'            => true,  // Show in admin for management
        'show_in_menu'       => true, // Not in main admin menu
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
add_action( 'init', 'bp_register_band_link_page_cpt' ); 