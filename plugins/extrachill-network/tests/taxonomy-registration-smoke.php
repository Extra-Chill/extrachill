<?php
/**
 * Network taxonomy registration structure tests (no WordPress or MySQL required).
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['taxonomy_registration_test'] = array(
	'registered'  => array(),
	'existing'    => array(),
	'actions'     => array(),
	'text_domain' => 'extrachill-network',
);

function taxonomy_registration_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

function taxonomy_registration_normalize_args( $args ) {
	ksort( $args );
	foreach ( $args as $key => $value ) {
		if ( is_array( $value ) ) {
			$args[ $key ] = taxonomy_registration_normalize_args( $value );
		}
	}
	return $args;
}

function taxonomy_registration_normalize_expected( $args ) {
	// WordPress fills label and rewrite defaults at registration time; the
	// declared args are what the registration file controls.
	return taxonomy_registration_normalize_args( $args );
}

// WordPress function stubs.
function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function taxonomy_exists( $taxonomy ) {
	return isset( $GLOBALS['taxonomy_registration_test']['existing'][ $taxonomy ] );
}

function register_taxonomy( $taxonomy, $object_type, $args = array() ) {
	$GLOBALS['taxonomy_registration_test']['registered'][ $taxonomy ] = array(
		'object_type' => $object_type,
		'args'        => taxonomy_registration_normalize_args( $args ),
	);
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['taxonomy_registration_test']['actions'][] = array(
		'hook'     => $hook_name,
		'callback' => $callback,
		'priority' => $priority,
	);
}

function apply_filters( $hook_name, $value, ...$args ) {
	return $value;
}

require_once dirname( __DIR__ ) . '/inc/taxonomy/register.php';

// Hook contract matches the theme registration it replaces.
$actions = $GLOBALS['taxonomy_registration_test']['actions'];
taxonomy_registration_assert(
	1 === count( $actions ) && 'init' === $actions[0]['hook'] && 'extrachill_network_register_taxonomies' === $actions[0]['callback'] && 0 === $actions[0]['priority'],
	'registration is hooked on init priority 0'
);

$GLOBALS['taxonomy_registration_test']['registered'] = array();
extrachill_network_register_taxonomies();
$registered = $GLOBALS['taxonomy_registration_test']['registered'];

taxonomy_registration_assert(
	array( 'location', 'festival', 'artist', 'venue', 'genre' ) === array_keys( $registered ),
	'all five network taxonomies are declared'
);

$expected = array(
	'location' => array(
		'object_type' => array( 'post' ),
		'args'        => array(
			'hierarchical'       => true,
			'labels'             => array(
				'name'              => 'Locations',
				'singular_name'     => 'Location',
				'search_items'      => 'Search Locations',
				'all_items'         => 'All Locations',
				'parent_item'       => 'Parent Location',
				'parent_item_colon' => 'Parent Location:',
				'edit_item'         => 'Edit Location',
				'update_item'       => 'Update Location',
				'add_new_item'      => 'Add New Location',
				'new_item_name'     => 'New Location Name',
				'menu_name'         => 'Location',
			),
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
		),
	),
	'festival' => array(
		'object_type' => array( 'post' ),
		'args'        => array(
			'hierarchical'      => false,
			'labels'            => array(
				'name'          => 'Festivals',
				'singular_name' => 'Festival',
				'search_items'  => 'Search Festivals',
				'all_items'     => 'All Festivals',
				'edit_item'     => 'Edit Festival',
				'update_item'   => 'Update Festival',
				'add_new_item'  => 'Add New Festival',
				'new_item_name' => 'New Festival Name',
				'menu_name'     => 'Festivals',
			),
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'festival' ),
			'show_in_rest'      => true,
		),
	),
	'artist'   => array(
		'object_type' => array( 'post' ),
		'args'        => array(
			'hierarchical'      => false,
			'labels'            => array(
				'name'          => 'Artists',
				'singular_name' => 'Artist',
				'search_items'  => 'Search Artists',
				'all_items'     => 'All Artists',
				'edit_item'     => 'Edit Artist',
				'update_item'   => 'Update Artist',
				'add_new_item'  => 'Add New Artist',
				'new_item_name' => 'New Artist Name',
				'menu_name'     => 'Artists',
			),
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'artist' ),
			'show_in_rest'      => true,
		),
	),
	'venue'    => array(
		'object_type' => array( 'post' ),
		'args'        => array(
			'hierarchical'      => false,
			'labels'            => array(
				'name'          => 'Venues',
				'singular_name' => 'Venue',
				'search_items'  => 'Search Venues',
				'all_items'     => 'All Venues',
				'edit_item'     => 'Edit Venue',
				'update_item'   => 'Update Venue',
				'add_new_item'  => 'Add New Venue',
				'new_item_name' => 'New Venue Name',
				'menu_name'     => 'Venues',
			),
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'venue' ),
			'show_in_rest'      => true,
		),
	),
	'genre'    => array(
		'object_type' => array( 'post' ),
		'args'        => array(
			'hierarchical'      => false,
			'labels'            => array(
				'name'          => 'Genres',
				'singular_name' => 'Genre',
				'search_items'  => 'Search Genres',
				'all_items'     => 'All Genres',
				'edit_item'     => 'Edit Genre',
				'update_item'   => 'Update Genre',
				'add_new_item'  => 'Add New Genre',
				'new_item_name' => 'New Genre Name',
				'menu_name'     => 'Genres',
			),
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'genre' ),
			'show_in_rest'      => true,
		),
	),
);

foreach ( $expected as $taxonomy => $expectation ) {
	taxonomy_registration_assert(
		$expectation['object_type'] === $registered[ $taxonomy ]['object_type'],
		"{$taxonomy} is registered for object type post only"
	);
	taxonomy_registration_assert(
		taxonomy_registration_normalize_expected( $expectation['args'] ) === $registered[ $taxonomy ]['args'],
		"{$taxonomy} registration args are complete and unchanged"
	);
}

// taxonomy_exists guards: pre-registered taxonomies are never re-registered.
$GLOBALS['taxonomy_registration_test']['existing'] = array_fill_keys( array_keys( $expected ), true );
$GLOBALS['taxonomy_registration_test']['registered'] = array();
extrachill_network_register_taxonomies();
taxonomy_registration_assert(
	array() === $GLOBALS['taxonomy_registration_test']['registered'],
	'taxonomy_exists guards skip registration when taxonomies already exist'
);

// Policy: venue joins the network term policy without changing existing rows.
require_once dirname( __DIR__ ) . '/inc/taxonomy/network-terms.php';
$policy = extrachill_network_get_term_policy();
taxonomy_registration_assert(
	array( 'artist', 'festival', 'genre', 'location', 'venue' ) === $policy['targets']['main']['post'],
	'main post target policy includes venue and genre'
);
taxonomy_registration_assert(
	array( 'events' ) === $policy['sources']['venue'],
	'venue source policy is the events site only'
);
taxonomy_registration_assert(
	! in_array( 'venue', $policy['targets']['community']['topic'] ?? array(), true ),
	'community topic target policy does not include venue'
);

// Policy: genre is sourced from main and projects onto the artist network.
taxonomy_registration_assert(
	array( 'main' ) === $policy['sources']['genre'],
	'genre source policy is the main site only'
);
taxonomy_registration_assert(
	in_array( 'genre', $policy['targets']['community']['topic'] ?? array(), true ),
	'community topic target policy includes genre'
);
taxonomy_registration_assert(
	array( 'genre' ) === ( $policy['targets']['events']['data_machine_events'] ?? null ),
	'events data_machine_events target policy includes genre'
);
taxonomy_registration_assert(
	array( 'genre' ) === ( $policy['targets']['artist']['artist_profile'] ?? null ),
	'artist artist_profile target policy includes genre'
);
