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
		'rewrite'           => array(
			'slug'         => '',
			'with_front'   => false,
			'hierarchical' => true,
		),
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
		'getting-started' => array(
			'name'        => 'Getting Started',
			'description' => 'New to Extra Chill? Start here to learn the basics.',
		),
		'artist-platform' => array(
			'name'        => 'Extra Chill Artist Platform',
			'description' => 'Create your artist profile, link pages, and grow your audience.',
		),
		'community'       => array(
			'name'        => 'Extra Chill Community',
			'description' => 'Connect with fellow music lovers in our forums.',
		),
		'events'          => array(
			'name'        => 'Extra Chill Events',
			'description' => 'Find and share music events happening near you.',
		),
		'stream'          => array(
			'name'        => 'Extra Chill Stream',
			'description' => 'Watch and broadcast live music streams.',
		),
		'newsletter'      => array(
			'name'        => 'Extra Chill Newsletter',
			'description' => 'Stay updated with our curated music news.',
		),
		'shop'            => array(
			'name'        => 'Extra Chill Shop',
			'description' => 'Browse and purchase music merchandise.',
		),
		'chat'            => array(
			'name'        => 'Extra Chill Chat',
			'description' => 'Get help from our AI assistant.',
		),
		'horoscopes'      => array(
			'name'        => 'Extra Chill Horoscopes',
			'description' => 'Daily wook-themed horoscopes for the music community.',
		),
		'account'         => array(
			'name'        => 'Your Account',
			'description' => 'Manage your profile, settings, and preferences.',
		),
	);

	foreach ( $platforms as $slug => $data ) {
		if ( ! term_exists( $slug, 'ec_doc_platform' ) ) {
			wp_insert_term(
				$data['name'],
				'ec_doc_platform',
				array(
					'slug'        => $slug,
					'description' => $data['description'],
				)
			);
		}
	}
}
