<?php
/**
 * Register Artist Link Page Custom Post Type
 *
 * @package ExtrchCo
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

function bp_register_artist_link_page_cpt() {
    $labels = array(
        'name'               => __( 'Artist Link Pages', 'extrachill-artist-platform' ),
        'singular_name'      => __( 'Artist Link Page', 'extrachill-artist-platform' ),
        'add_new'            => __( 'Add New', 'extrachill-artist-platform' ),
        'add_new_item'       => __( 'Add New Artist Link Page', 'extrachill-artist-platform' ),
        'edit_item'          => __( 'Edit Artist Link Page', 'extrachill-artist-platform' ),
        'new_item'           => __( 'New Artist Link Page', 'extrachill-artist-platform' ),
        'view_item'          => __( 'View Artist Link Page', 'extrachill-artist-platform' ),
        'search_items'       => __( 'Search Artist Link Pages', 'extrachill-artist-platform' ),
        'not_found'          => __( 'No Artist Link Pages found', 'extrachill-artist-platform' ),
        'not_found_in_trash' => __( 'No Artist Link Pages found in Trash', 'extrachill-artist-platform' ),
        'menu_name'          => __( 'Artist Link Pages', 'extrachill-artist-platform' ),
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
        'rewrite'            => array('slug' => 'artist-link-page'), // DEV: Enable pretty permalinks for testing
        'supports'           => array( 'title', 'custom-fields' ),
        'capability_type'    => 'post',
        'hierarchical'       => false,
        'taxonomies'         => array(), // No taxonomies
        'show_in_rest'       => true, // For future API use
    );

    register_post_type( 'artist_link_page', $args );
}
add_action( 'init', 'bp_register_artist_link_page_cpt' ); 