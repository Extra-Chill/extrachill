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
		'name'                  => _x( 'Festival Wire', 'Post Type General Name', 'colormag-pro' ),
		'singular_name'         => _x( 'Festival Wire', 'Post Type Singular Name', 'colormag-pro' ),
		'menu_name'             => __( 'Festival Wire', 'colormag-pro' ),
		'name_admin_bar'        => __( 'Festival Wire', 'colormag-pro' ),
		'archives'              => __( 'Festival Wire Archives', 'colormag-pro' ),
		'attributes'            => __( 'Festival Wire Attributes', 'colormag-pro' ),
		'parent_item_colon'     => __( 'Parent Item:', 'colormag-pro' ),
		'all_items'             => __( 'All Festival Wire', 'colormag-pro' ),
		'add_new_item'          => __( 'Add New Festival Wire', 'colormag-pro' ),
		'add_new'               => __( 'Add New', 'colormag-pro' ),
		'new_item'              => __( 'New Festival Wire', 'colormag-pro' ),
		'edit_item'             => __( 'Edit Festival Wire', 'colormag-pro' ),
		'update_item'           => __( 'Update Festival Wire', 'colormag-pro' ),
		'view_item'             => __( 'View Festival Wire', 'colormag-pro' ),
		'view_items'            => __( 'View Festival Wire', 'colormag-pro' ),
		'search_items'          => __( 'Search Festival Wire', 'colormag-pro' ),
		'not_found'             => __( 'Not found', 'colormag-pro' ),
		'not_found_in_trash'    => __( 'Not found in Trash', 'colormag-pro' ),
		'featured_image'        => __( 'Featured Image', 'colormag-pro' ),
		'set_featured_image'    => __( 'Set featured image', 'colormag-pro' ),
		'remove_featured_image' => __( 'Remove featured image', 'colormag-pro' ),
		'use_featured_image'    => __( 'Use as featured image', 'colormag-pro' ),
		'insert_into_item'      => __( 'Insert into item', 'colormag-pro' ),
		'uploaded_to_this_item' => __( 'Uploaded to this item', 'colormag-pro' ),
		'items_list'            => __( 'Festival Wire list', 'colormag-pro' ),
		'items_list_navigation' => __( 'Festival Wire list navigation', 'colormag-pro' ),
		'filter_items_list'     => __( 'Filter festival wire list', 'colormag-pro' ),
	);
	$args = array(
		'label'                 => __( 'Festival Wire', 'colormag-pro' ),
		'description'           => __( 'News feed for music festivals', 'colormag-pro' ),
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