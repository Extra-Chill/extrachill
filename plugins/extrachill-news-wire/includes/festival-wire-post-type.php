<?php
/**
 * Festival Wire Custom Post Type Registration
 *
 * Registers the festival_wire custom post type with proper labels,
 * taxonomies, and capabilities. Includes location taxonomy integration.
 *
 * @package ExtraChillNewsWire
 * @since 0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Festival Wire custom post type
 *
 * Creates festival_wire post type with full WordPress features including:
 * - Gutenberg editor support
 * - REST API integration
 * - Archive and single page templates
 * - Festival and location taxonomies
 *
 * @since 0.1.0
 */
function register_festival_wire_cpt() {

	// Post type labels for admin interface
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
	// Post type configuration
	$args = array(
		        'label'                 => __( 'Festival Wire', 'extrachill' ),
        'description'           => __( 'News feed for music festivals', 'extrachill' ),
		'labels'                => $labels,
		'supports'              => array( 'title', 'editor', 'excerpt', 'author', 'thumbnail', 'revisions', 'custom-fields' ),
		'taxonomies'			=> array( 'festival' ),

		'hierarchical'          => false,
		'public'                => true,
		'show_ui'               => true,
		'show_in_menu'          => true,
		'menu_position'         => 5,
		'menu_icon'             => 'dashicons-megaphone',
		'show_in_admin_bar'     => true,
		'show_in_nav_menus'     => true,
		'can_export'            => true,
		'has_archive'           => true,
		'rewrite'               => array( 'slug' => 'festival-wire' ),
		'exclude_from_search'   => false,
		'publicly_queryable'    => true,
		'capability_type'       => 'post',
		'show_in_rest'          => true,
	);
	register_post_type( 'festival_wire', $args );

}
add_action( 'init', 'register_festival_wire_cpt', 0 );

/**
 * Add location taxonomy to Festival Wire posts
 *
 * Registers existing location taxonomy for use with Festival Wire posts.
 * Location taxonomy is defined in the theme's core functionality.
 *
 * @since 0.1.0
 */
function add_location_to_festival_wire() {
	register_taxonomy_for_object_type( 'location', 'festival_wire' );
}
add_action( 'init', 'add_location_to_festival_wire' ); 