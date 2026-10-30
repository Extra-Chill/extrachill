<?php

/**
 * Network Taxonomy Registration
 *
 * Registers the network-wide music taxonomies: location, festival, artist, venue,
 * genre. All include REST API support for block editor integration.
 *
 * Moved verbatim from the theme (extrachill/inc/core/custom-taxonomies.php);
 * theme removal tracked in Extra-Chill/extrachill#82.
 *
 * @package ExtraChillNetwork
 * @since 1.0.0
 */
function extrachill_network_register_taxonomies() {
	if ( ! taxonomy_exists( 'location' ) ) {
		$location_labels = array(
			'name'              => _x( 'Locations', 'taxonomy general name', 'extrachill-network' ),
			'singular_name'     => _x( 'Location', 'taxonomy singular name', 'extrachill-network' ),
			'search_items'      => __( 'Search Locations', 'extrachill-network' ),
			'all_items'         => __( 'All Locations', 'extrachill-network' ),
			'parent_item'       => __( 'Parent Location', 'extrachill-network' ),
			'parent_item_colon' => __( 'Parent Location:', 'extrachill-network' ),
			'edit_item'         => __( 'Edit Location', 'extrachill-network' ),
			'update_item'       => __( 'Update Location', 'extrachill-network' ),
			'add_new_item'      => __( 'Add New Location', 'extrachill-network' ),
			'new_item_name'     => __( 'New Location Name', 'extrachill-network' ),
			'menu_name'         => __( 'Location', 'extrachill-network' ),
		);

		$location_args = array(
			'hierarchical'       => true,
			'labels'             => $location_labels,
			'public'             => true,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_in_quick_edit' => true,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => array(
				'slug'         => 'location',
				'with_front'   => false,
				'hierarchical' => true,
			),
		);

		register_taxonomy( 'location', array( 'post' ), $location_args );
	}

	if ( ! taxonomy_exists( 'festival' ) ) {
		register_taxonomy(
			'festival',
			array( 'post' ),
			array(
				'hierarchical'      => false,
				'labels'            => array(
					'name'          => _x( 'Festivals', 'taxonomy general name', 'extrachill-network' ),
					'singular_name' => _x( 'Festival', 'taxonomy singular name', 'extrachill-network' ),
					'search_items'  => __( 'Search Festivals', 'extrachill-network' ),
					'all_items'     => __( 'All Festivals', 'extrachill-network' ),
					'edit_item'     => __( 'Edit Festival', 'extrachill-network' ),
					'update_item'   => __( 'Update Festival', 'extrachill-network' ),
					'add_new_item'  => __( 'Add New Festival', 'extrachill-network' ),
					'new_item_name' => __( 'New Festival Name', 'extrachill-network' ),
					'menu_name'     => __( 'Festivals', 'extrachill-network' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'festival' ),
				'show_in_rest'      => true,
			)
		);
	}

	if ( ! taxonomy_exists( 'artist' ) ) {
		register_taxonomy(
			'artist',
			array( 'post' ),
			array(
				'hierarchical'      => false,
				'labels'            => array(
					'name'          => _x( 'Artists', 'taxonomy general name', 'extrachill-network' ),
					'singular_name' => _x( 'Artist', 'taxonomy singular name', 'extrachill-network' ),
					'search_items'  => __( 'Search Artists', 'extrachill-network' ),
					'all_items'     => __( 'All Artists', 'extrachill-network' ),
					'edit_item'     => __( 'Edit Artist', 'extrachill-network' ),
					'update_item'   => __( 'Update Artist', 'extrachill-network' ),
					'add_new_item'  => __( 'Add New Artist', 'extrachill-network' ),
					'new_item_name' => __( 'New Artist Name', 'extrachill-network' ),
					'menu_name'     => __( 'Artists', 'extrachill-network' ),
				),
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'artist' ),
				'show_in_rest'      => true,
			)
		);
	}

	if ( ! taxonomy_exists( 'venue' ) ) {
		register_taxonomy(
			'venue',
			array( 'post' ),
			array(
				'hierarchical'      => false,
				'labels'            => array(
					'name'          => _x( 'Venues', 'taxonomy general name', 'extrachill-network' ),
					'singular_name' => _x( 'Venue', 'taxonomy singular name', 'extrachill-network' ),
					'search_items'  => __( 'Search Venues', 'extrachill-network' ),
					'all_items'     => __( 'All Venues', 'extrachill-network' ),
					'edit_item'     => __( 'Edit Venue', 'extrachill-network' ),
					'update_item'   => __( 'Update Venue', 'extrachill-network' ),
					'add_new_item'  => __( 'Add New Venue', 'extrachill-network' ),
					'new_item_name' => __( 'New Venue Name', 'extrachill-network' ),
					'menu_name'     => __( 'Venues', 'extrachill-network' ),
				),
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'venue' ),
				'show_in_rest'      => true,
			)
		);
	}

	if ( ! taxonomy_exists( 'genre' ) ) {
		register_taxonomy(
			'genre',
			array( 'post' ),
			array(
				'hierarchical'      => false,
				'labels'            => array(
					'name'          => _x( 'Genres', 'taxonomy general name', 'extrachill-network' ),
					'singular_name' => _x( 'Genre', 'taxonomy singular name', 'extrachill-network' ),
					'search_items'  => __( 'Search Genres', 'extrachill-network' ),
					'all_items'     => __( 'All Genres', 'extrachill-network' ),
					'edit_item'     => __( 'Edit Genre', 'extrachill-network' ),
					'update_item'   => __( 'Update Genre', 'extrachill-network' ),
					'add_new_item'  => __( 'Add New Genre', 'extrachill-network' ),
					'new_item_name' => __( 'New Genre Name', 'extrachill-network' ),
					'menu_name'     => __( 'Genres', 'extrachill-network' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'genre' ),
				'show_in_rest'      => true,
			)
		);
	}
}
add_action( 'init', 'extrachill_network_register_taxonomies', 0 );
