<?php
/**
 * Documentation Platform Taxonomy Registration
 *
 * Registers the ec_doc_platform taxonomy for organizing docs by network site.
 *
 * @package ExtraChillDocs
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'extrachill_docs_register_platform_taxonomy' );

/**
 * Registers the ec_doc_platform taxonomy.
 *
 * @since 0.1.0
 * @return void
 */
function extrachill_docs_register_platform_taxonomy() {
	$labels = array(
		'name'              => 'Platforms',
		'singular_name'     => 'Platform',
		'search_items'      => 'Search Platforms',
		'all_items'         => 'All Platforms',
		'parent_item'       => 'Parent Platform',
		'parent_item_colon' => 'Parent Platform:',
		'edit_item'         => 'Edit Platform',
		'update_item'       => 'Update Platform',
		'add_new_item'      => 'Add New Platform',
		'new_item_name'     => 'New Platform Name',
		'menu_name'         => 'Platforms',
	);

	$args = array(
		'labels'            => $labels,
		'hierarchical'      => true,
		'public'            => true,
		'show_ui'           => true,
		'show_admin_column' => true,
		'show_in_nav_menus' => true,
		'show_tagcloud'     => false,
		'query_var'         => true,
		'rewrite'           => false,
		'show_in_rest'      => true,
	);

	register_taxonomy( 'ec_doc_platform', array( 'ec_doc' ), $args );
}
