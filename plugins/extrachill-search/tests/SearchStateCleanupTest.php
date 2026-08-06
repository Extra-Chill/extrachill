<?php
/**
 * Regression tests for search request-global state cleanup.
 *
 * Run with: php tests/SearchStateCleanupTest.php
 *
 * @package ExtraChill\Search
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$current_blog_id = 1;
$blog_stack      = array();
$filters         = array();

class Search_State_Test_WPDB {
	public $posts = 'wp_posts';

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_results() {
		return array( (object) array( 'Index_type' => 'FULLTEXT' ) );
	}

	public function _real_escape( $value ) {
		return addslashes( $value );
	}
}

class WP_Query {
	public static $throw = false;

	public function __construct( $args = array() ) {
		if ( self::$throw ) {
			throw new Error( 'Query failure' );
		}
	}

	public function have_posts() {
		return false;
	}
}

$wpdb = new Search_State_Test_WPDB();

function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	global $filters;
	$filters[ $hook_name ][ $priority ][] = $callback;
	return true;
}

function remove_filter( $hook_name, $callback, $priority = 10 ) {
	global $filters;
	if ( empty( $filters[ $hook_name ][ $priority ] ) ) {
		return false;
	}

	foreach ( $filters[ $hook_name ][ $priority ] as $index => $registered_callback ) {
		if ( $registered_callback === $callback ) {
			unset( $filters[ $hook_name ][ $priority ][ $index] );
			$filters[ $hook_name ][ $priority ] = array_values( $filters[ $hook_name ][ $priority ] );
			return true;
		}
	}

	return false;
}

function apply_filters( $hook_name, $value, ...$args ) {
	global $filters;
	if ( empty( $filters[ $hook_name ] ) ) {
		return $value;
	}

	ksort( $filters[ $hook_name ] );
	foreach ( $filters[ $hook_name ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}

	return $value;
}

function switch_to_blog( $blog_id ) {
	global $blog_stack, $current_blog_id, $wpdb;
	$blog_stack[]    = $current_blog_id;
	$current_blog_id = $blog_id;
	$wpdb->posts     = 'wp_' . $blog_id . '_posts';
	return true;
}

function restore_current_blog() {
	global $blog_stack, $current_blog_id, $wpdb;
	$current_blog_id = array_pop( $blog_stack );
	$wpdb->posts     = 1 === $current_blog_id ? 'wp_posts' : 'wp_' . $current_blog_id . '_posts';
	return true;
}

function get_current_blog_id() {
	global $current_blog_id;
	return $current_blog_id;
}

function get_blog_details( $blog_id ) {
	return (object) array(
		'blogname' => 'Test Site',
		'siteurl'  => 'https://example.test',
	);
}

function extrachill_get_site_post_types() {
	return array( 2 => array( 'post' ) );
}

function extrachill_resolve_site_urls() {
	return array( 2 );
}

function extrachill_normalize_search_term( $term ) {
	return trim( $term );
}

function is_multisite() {
	return true;
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}

function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $item ) use ( $field ) {
			return is_array( $item ) ? $item[ $field ] : $item->{$field};
		},
		$list
	);
}

function wp_get_referer() {
	return false;
}

function do_action() {}

function __return_empty_array() {
	return array();
}

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message );
	}
}

function reset_search_state() {
	global $blog_stack, $current_blog_id, $filters, $wpdb;
	$blog_stack       = array();
	$current_blog_id  = 1;
	$filters          = array();
	$wpdb->posts      = 'wp_posts';
	WP_Query::$throw = false;
}

require_once dirname( __DIR__ ) . '/inc/core/search-algorithm.php';

reset_search_state();
add_filter( 'extrachill_search_site_post_types', '__return_empty_array' );
extrachill_network_search( 'test', array( 'example.test' ) );
assert_same( 1, get_current_blog_id(), 'Empty post types leaked the switched blog context.' );
assert_same( array(), $blog_stack, 'Empty post types left entries on the blog stack.' );

reset_search_state();
add_filter(
	'extrachill_search_site_post_types',
	static function () {
		throw new Error( 'Post type failure' );
	}
);
try {
	extrachill_network_search( 'test', array( 'example.test' ) );
	throw new RuntimeException( 'Expected the search error to propagate.' );
} catch ( Error $error ) {
	assert_same( 'Post type failure', $error->getMessage(), 'Unexpected search error propagated.' );
}
assert_same( 1, get_current_blog_id(), 'A thrown Throwable leaked the switched blog context.' );
assert_same( array(), $blog_stack, 'A thrown Throwable left entries on the blog stack.' );

reset_search_state();
$unrelated_filter = static function ( $search ) {
	return $search . ' unrelated';
};
add_filter( 'posts_search', $unrelated_filter, 10 );
WP_Query::$throw = true;
try {
	extrachill_word_level_search_fallback(
		'test',
		array( 2 ),
		array(
			'post_status' => array( 'publish' ),
			'orderby'     => 'date',
			'order'       => 'DESC',
			'meta_query'  => null,
			'tax_query'   => null,
		)
	);
	throw new RuntimeException( 'Expected the fallback query error to propagate.' );
} catch ( Error $error ) {
	assert_same( 'Query failure', $error->getMessage(), 'Unexpected fallback error propagated.' );
}
assert_same( 1, get_current_blog_id(), 'Fallback failure leaked the switched blog context.' );
assert_same( array(), $blog_stack, 'Fallback failure left entries on the blog stack.' );
assert_same( array( $unrelated_filter ), $filters['posts_search'][10], 'Fallback cleanup removed an unrelated posts_search callback.' );

fwrite( STDOUT, "Search state cleanup tests passed.\n" );
