<?php
/**
 * Artist Platform Custom Post Types
 * 
 * Centralized registration of all custom post types for the artist platform.
 * Registers artist_profile and artist_link_page CPTs with appropriate settings.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

function extrachill_register_artist_profile_cpt() {
    if ( ! function_exists( 'register_post_type' ) ) {
        return;
    }

    $labels = array(
        'name'                  => _x( 'Artist Profiles', 'Post Type General Name', 'extrachill-artist-platform' ),
        'singular_name'         => _x( 'Artist Profile', 'Post Type Singular Name', 'extrachill-artist-platform' ),
        'menu_name'             => __( 'Artist Profiles', 'extrachill-artist-platform' ),
        'name_admin_bar'        => __( 'Artist Profile', 'extrachill-artist-platform' ),
        'archives'              => __( 'Artist Profile Archives', 'extrachill-artist-platform' ),
        'attributes'            => __( 'Artist Profile Attributes', 'extrachill-artist-platform' ),
        'parent_item_colon'     => __( 'Parent Artist Profile:', 'extrachill-artist-platform' ),
        'all_items'             => __( 'All Artist Profiles', 'extrachill-artist-platform' ),
        'add_new_item'          => __( 'Add New Artist Profile', 'extrachill-artist-platform' ),
        'add_new'               => __( 'Add New', 'extrachill-artist-platform' ),
        'new_item'              => __( 'New Artist Profile', 'extrachill-artist-platform' ),
        'edit_item'             => __( 'Edit Artist Profile', 'extrachill-artist-platform' ),
        'update_item'           => __( 'Update Artist Profile', 'extrachill-artist-platform' ),
        'view_item'             => __( 'View Artist Profile', 'extrachill-artist-platform' ),
        'view_items'            => __( 'View Artist Profiles', 'extrachill-artist-platform' ),
        'search_items'          => __( 'Search Artist Profile', 'extrachill-artist-platform' ),
        'not_found'             => __( 'Not found', 'extrachill-artist-platform' ),
        'not_found_in_trash'    => __( 'Not found in Trash', 'extrachill-artist-platform' ),
        'featured_image'        => __( 'Featured Image', 'extrachill-artist-platform' ),
        'set_featured_image'    => __( 'Set featured image', 'extrachill-artist-platform' ),
        'remove_featured_image' => __( 'Remove featured image', 'extrachill-artist-platform' ),
        'use_featured_image'    => __( 'Use as featured image', 'extrachill-artist-platform' ),
        'insert_into_item'      => __( 'Insert into artist profile', 'extrachill-artist-platform' ),
        'uploaded_to_this_item' => __( 'Uploaded to this artist profile', 'extrachill-artist-platform' ),
        'items_list'            => __( 'Artist Profiles list', 'extrachill-artist-platform' ),
        'items_list_navigation' => __( 'Artist Profiles list navigation', 'extrachill-artist-platform' ),
        'filter_items_list'     => __( 'Filter artist profiles list', 'extrachill-artist-platform' ),
    );

    $args = array(
        'label'                 => __( 'Artist Profile', 'extrachill-artist-platform' ),
        'description'           => __( 'Custom Post Type for Artist Profiles', 'extrachill-artist-platform' ),
        'labels'                => $labels,
        'supports'              => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 5,
        'menu_icon'             => 'dashicons-groups',
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => false,
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'rewrite'               => false,
        'map_meta_cap'          => true,
        'show_in_rest'          => true,
    );

    register_post_type( 'artist_profile', $args );
}

/**
 * Register artist_link_page Custom Post Type
 */
function extrachill_register_artist_link_page_cpt() {

    $labels = array(
        'name'                  => _x( 'Link Pages', 'Post Type General Name', 'extrachill-artist-platform' ),
        'singular_name'         => _x( 'Link Page', 'Post Type Singular Name', 'extrachill-artist-platform' ),
        'menu_name'             => __( 'Link Pages', 'extrachill-artist-platform' ),
        'name_admin_bar'        => __( 'Link Page', 'extrachill-artist-platform' ),
        'archives'              => __( 'Link Page Archives', 'extrachill-artist-platform' ),
        'attributes'            => __( 'Link Page Attributes', 'extrachill-artist-platform' ),
        'parent_item_colon'     => __( 'Parent Link Page:', 'extrachill-artist-platform' ),
        'all_items'             => __( 'All Link Pages', 'extrachill-artist-platform' ),
        'add_new_item'          => __( 'Add New Link Page', 'extrachill-artist-platform' ),
        'add_new'               => __( 'Add New', 'extrachill-artist-platform' ),
        'new_item'              => __( 'New Link Page', 'extrachill-artist-platform' ),
        'edit_item'             => __( 'Edit Link Page', 'extrachill-artist-platform' ),
        'update_item'           => __( 'Update Link Page', 'extrachill-artist-platform' ),
        'view_item'             => __( 'View Link Page', 'extrachill-artist-platform' ),
        'view_items'            => __( 'View Link Pages', 'extrachill-artist-platform' ),
        'search_items'          => __( 'Search Link Page', 'extrachill-artist-platform' ),
        'not_found'             => __( 'Not found', 'extrachill-artist-platform' ),
        'not_found_in_trash'    => __( 'Not found in Trash', 'extrachill-artist-platform' ),
        'featured_image'        => __( 'Featured Image', 'extrachill-artist-platform' ),
        'set_featured_image'    => __( 'Set featured image', 'extrachill-artist-platform' ),
        'remove_featured_image' => __( 'Remove featured image', 'extrachill-artist-platform' ),
        'use_featured_image'    => __( 'Use as featured image', 'extrachill-artist-platform' ),
        'insert_into_item'      => __( 'Insert into link page', 'extrachill-artist-platform' ),
        'uploaded_to_this_item' => __( 'Uploaded to this link page', 'extrachill-artist-platform' ),
        'items_list'            => __( 'Link Pages list', 'extrachill-artist-platform' ),
        'items_list_navigation' => __( 'Link Pages list navigation', 'extrachill-artist-platform' ),
        'filter_items_list'     => __( 'Filter link pages list', 'extrachill-artist-platform' ),
    );

    $args = array(
        'label'                 => __( 'Link Page', 'extrachill-artist-platform' ),
        'description'           => __( 'Custom Post Type for Artist Link Pages', 'extrachill-artist-platform' ),
        'labels'                => $labels,
        'supports'              => array( 'title', 'custom-fields', 'author' ),
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 6,
        'menu_icon'             => 'dashicons-admin-links',
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => false,
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'rewrite'               => array('slug' => 'link-page'),
        'capability_type'       => 'post',
        'map_meta_cap'          => true,
        'show_in_rest'          => true,
    );

    register_post_type( 'artist_link_page', $args );
}

/**
 * Initialize post type registration
 */
function extrachill_init_post_types() {
    extrachill_register_artist_profile_cpt();
    extrachill_register_artist_link_page_cpt();
}

// Hook to init with proper priority for post type registration
add_action( 'init', 'extrachill_init_post_types', 5 );