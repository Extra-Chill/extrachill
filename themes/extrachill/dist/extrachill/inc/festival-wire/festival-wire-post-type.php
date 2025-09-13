<?php
/**
 * Festival Wire Custom Post Type Registration and Taxonomy setup.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Festival Wire Custom Post Type.
 */
function register_festival_wire_cpt() {

	$labels = array(
		        'name'                  => _x( 'Festival Wire', 'Post Type General Name', 'extrachill' ),
        'singular_name'         => _x( 'Festival Wire', 'Post Type Singular Name', 'extrachill' ),
        'menu_name'             => __( 'Festival Wire', 'extrachill' ),
        'name_admin_bar'        => __( 'Festival Wire', 'extrachill' ),
        'archives'              => __( 'Festival Wire Archives', 'extrachill' ),
        'attributes'            => __( 'Festival Wire Attributes', 'extrachill' ),
        'parent_item_colon'     => __( 'Parent Item:', 'extrachill' ),
        'all_items'             => __( 'All Festival Wire', 'extrachill' ),
        'add_new_item'          => __( 'Add New Festival Wire', 'extrachill' ),
        'add_new'               => __( 'Add New', 'extrachill' ),
        'new_item'              => __( 'New Festival Wire', 'extrachill' ),
        'edit_item'             => __( 'Edit Festival Wire', 'extrachill' ),
        'update_item'           => __( 'Update Festival Wire', 'extrachill' ),
        'view_item'             => __( 'View Festival Wire', 'extrachill' ),
        'view_items'            => __( 'View Festival Wire', 'extrachill' ),
        'search_items'          => __( 'Search Festival Wire', 'extrachill' ),
        'not_found'             => __( 'Not found', 'extrachill' ),
        'not_found_in_trash'    => __( 'Not found in Trash', 'extrachill' ),
        'featured_image'        => __( 'Featured Image', 'extrachill' ),
        'set_featured_image'    => __( 'Set featured image', 'extrachill' ),
        'remove_featured_image' => __( 'Remove featured image', 'extrachill' ),
        'use_featured_image'    => __( 'Use as featured image', 'extrachill' ),
        'insert_into_item'      => __( 'Insert into item', 'extrachill' ),
        'uploaded_to_this_item' => __( 'Uploaded to this item', 'extrachill' ),
        'items_list'            => __( 'Festival Wire list', 'extrachill' ),
        'items_list_navigation' => __( 'Festival Wire list navigation', 'extrachill' ),
        'filter_items_list'     => __( 'Filter festival wire list', 'extrachill' ),
	);
	$args = array(
		        'label'                 => __( 'Festival Wire', 'extrachill' ),
        'description'           => __( 'News feed for music festivals', 'extrachill' ),
		'labels'                => $labels,
		'supports'              => array( 'title', 'editor', 'excerpt', 'author', 'thumbnail', 'revisions', 'custom-fields' ), // Added excerpt support
		'taxonomies'            => array( 'category', 'festival', 'data_source' ), // Updated to include festival taxonomy
		'hierarchical'          => false,
		'public'                => true,
		'show_ui'               => true,
		'show_in_menu'          => true,
		'menu_position'         => 5,
		'menu_icon'             => 'dashicons-megaphone',
		'show_in_admin_bar'     => true,
		'show_in_nav_menus'     => true,
		'can_export'            => true,
		'has_archive'           => true, // Enable archive page
		'rewrite'               => array( 'slug' => 'festival-wire' ), // Custom slug
		'exclude_from_search'   => false, // Setting to false means it *can* be searched, but we will control *where* it shows up in search via pre_get_posts
		'publicly_queryable'    => true,
		'capability_type'       => 'post',
		'show_in_rest'          => true, // Enable Gutenberg editor support
	);
	register_post_type( 'festival_wire', $args );

}
add_action( 'init', 'register_festival_wire_cpt', 0 );

/**
 * Add the location taxonomy to the Festival Wire CPT.
 */
function add_location_to_festival_wire() {
	register_taxonomy_for_object_type( 'location', 'festival_wire' );
}
add_action( 'init', 'add_location_to_festival_wire' ); 