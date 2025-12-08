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

add_action( 'init', 'extrachill_docs_register_taxonomy' );

/**
 * Registers the ec_doc_platform taxonomy.
 *
 * @since 0.1.0
 * @return void
 */
function extrachill_docs_register_taxonomy() {
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

/**
 * Seeds default platform terms on plugin activation.
 *
 * @since 0.1.0
 * @return void
 */
function extrachill_docs_seed_platforms() {
	// Ensure taxonomy is registered before seeding.
	extrachill_docs_register_taxonomy();

	$platforms = array(
		'artist-platform' => 'Artist Platform',
		'community'       => 'Community',
		'events-calendar' => 'Events',
		'stream'          => 'Stream',
		'newsletter'      => 'Newsletter',
		'shop'            => 'Shop',
		'chat'            => 'Chat',
		'blog'            => 'Blog',
		'horoscopes'      => 'Horoscopes',
	);

	foreach ( $platforms as $slug => $name ) {
		if ( ! term_exists( $slug, 'ec_doc_platform' ) ) {
			wp_insert_term( $name, 'ec_doc_platform', array( 'slug' => $slug ) );
		}
	}
}
