<?php
/**
 * Documentation Post Type Registration
 *
 * Registers the ec_doc custom post type for user documentation articles.
 *
 * @package ExtraChillDocs
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'extrachill_docs_register_post_type' );

/**
 * Registers the ec_doc custom post type.
 *
 * @since 0.1.0
 * @return void
 */
function extrachill_docs_register_post_type() {
	$labels = array(
		'name'               => 'Documentation',
		'singular_name'      => 'Doc',
		'menu_name'          => 'Documentation',
		'add_new'            => 'Add New',
		'add_new_item'       => 'Add New Doc',
		'edit_item'          => 'Edit Doc',
		'new_item'           => 'New Doc',
		'view_item'          => 'View Doc',
		'search_items'       => 'Search Docs',
		'not_found'          => 'No docs found',
		'not_found_in_trash' => 'No docs found in trash',
		'all_items'          => 'All Docs',
	);

	$args = array(
		'labels'             => $labels,
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'query_var'          => true,
		'rewrite'            => false,
		'capability_type'    => 'post',
		'has_archive'        => true,
		'hierarchical'       => true,
		'menu_position'      => 5,
		'menu_icon'          => 'dashicons-book-alt',
		'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'revisions' ),
		'show_in_rest'       => true,
		'taxonomies'         => array( 'ec_doc_platform' ),
	);

	register_post_type( 'ec_doc', $args );
}
