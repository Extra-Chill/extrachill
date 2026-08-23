<?php
/**
 * Regression tests for search request-global state cleanup.
 *
 * Run with: php tests/SearchStateCleanupTest.php
 *
 * @package ExtraChill\Search
 */

declare( strict_types=1 );

if ( ! function_exists( 'get_current_blog_id' ) ) :

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$current_blog_id = 1;
$blog_stack      = array();
$filters         = array();
$content_queries = array();
$query_vars      = array();
$search_scope    = 'site';

class Search_State_Test_WPDB {
	public $posts = 'wp_posts';

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_results() {
		return array(
			(object) array( 'Key_name' => 'ft_search', 'Seq_in_index' => 1, 'Column_name' => 'post_title' ),
			(object) array( 'Key_name' => 'ft_search', 'Seq_in_index' => 2, 'Column_name' => 'post_excerpt' ),
			(object) array( 'Key_name' => 'ft_search', 'Seq_in_index' => 3, 'Column_name' => 'post_content' ),
		);
	}

	public function _real_escape( $value ) {
		return addslashes( $value );
	}
}

class WP_Query {
	public static $throw = false;
	public static $instances = array();
	public static $posts_by_blog = array();
	public $found_posts = 0;
	public $max_num_pages = 0;
	private $args = array();
	private $posts = array();
	private $index = 0;
	private $main = false;
	private $search = false;

	public function __construct( $args = array(), $main = false, $search = false ) {
		global $content_queries, $current_blog_id;
		if ( self::$throw ) {
			throw new Error( 'Query failure' );
		}

		$this->args   = $args;
		$this->main   = $main;
		$this->search = $search;
		self::$instances[] = array( 'blog_id' => $current_blog_id, 'args' => $args );

		if ( ! empty( $args['extrachill_fulltext_term'] ) ) {
			$content_queries[] = extrachill_fulltext_posts_search( '', $this );
		}

		foreach ( self::$posts_by_blog[ $current_blog_id ] ?? array() as $post ) {
			if ( ! in_array( $post->post_status, (array) ( $args['post_status'] ?? array( 'publish' ) ), true ) ) {
				continue;
			}
			if ( ! is_user_logged_in() && '' !== $post->post_password ) {
				continue;
			}
			$this->posts[] = $post;
		}
	}

	public function get( $key ) {
		return $this->args[ $key ] ?? '';
	}

	public function set( $key, $value ) {
		$this->args[ $key ] = $value;
	}

	public function is_main_query() {
		return $this->main;
	}

	public function is_search() {
		return $this->search;
	}

	public function have_posts() {
		return $this->index < count( $this->posts );
	}

	public function the_post() {
		global $post;
		$post = $this->posts[ $this->index ];
		++$this->index;
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
	return array( 2 => array( 'post' ), 3 => array( 'post' ) );
}

function extrachill_resolve_site_urls( $site_urls ) {
	$map = array( 'site2.test' => 2, 'site3.test' => 3, 'example.test' => 2 );
	return array_values(
		array_filter(
			array_map(
				static function ( $site_url ) use ( $map ) {
					return $map[ $site_url ] ?? null;
				},
				$site_urls
			)
		)
	);
}

function extrachill_get_network_sites() {
	return array( array( 'id' => 2 ), array( 'id' => 3 ) );
}

function extrachill_normalize_search_term( $term ) {
	return trim( $term );
}

function is_multisite() {
	return true;
}

function is_admin() {
	return false;
}

function is_user_logged_in() {
	return false;
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
	global $blog_stack, $content_queries, $current_blog_id, $filters, $query_vars, $search_scope, $wpdb;
	$blog_stack       = array();
	$content_queries  = array();
	$current_blog_id  = 1;
	$filters          = array();
	$query_vars       = array();
	$search_scope     = 'site';
	$wpdb->posts      = 'wp_posts';
	WP_Query::$throw  = false;
	WP_Query::$instances = array();
	WP_Query::$posts_by_blog = array();
}

require_once __DIR__ . '/fixtures/SearchRequestFunctions.php';
require_once dirname( __DIR__ ) . '/inc/core/index-health.php';
require_once dirname( __DIR__ ) . '/inc/core/search-algorithm.php';
require_once dirname( __DIR__ ) . '/templates/template-functions.php';

// A frontend request executes no discarded main SQL and one query per target.
reset_search_state();
WP_Query::$posts_by_blog = array(
	2 => array( search_test_post( 21 ), search_test_post( 22 ), search_test_post( 23 ), search_test_post( 24, 'draft' ), search_test_post( 25, 'publish', 'secret' ) ),
	3 => array( search_test_post( 31 ) ),
);
$main_query = new WP_Query( array( 's' => 'needlefest' ), true, true );
extrachill_route_frontend_search( $main_query );
assert_same( array(), extrachill_short_circuit_frontend_search( null, $main_query ), 'Frontend main query was not short-circuited.' );
assert_same( array(), $content_queries, 'Frontend main query executed a discarded content search.' );

$query_vars = array( 's' => 'needlefest', 'paged' => 1 );
$site_page  = extrachill_get_search_results();
assert_same( 1, count( $content_queries ), 'Site request did not execute one canonical content search.' );
assert_same( false, false !== strpos( $content_queries[0], '*' ), 'Canonical search retained broad prefix expansion.' );
assert_same( 3, $site_page['total'], 'Status or password constraints changed rendered totals.' );
assert_same( array( 21, 22 ), wp_list_pluck( $site_page['results'], 'ID' ), 'Rendered site results changed.' );
$site_query = WP_Query::$instances[ count( WP_Query::$instances ) - 1 ]['args'];
assert_same( 'ids', $site_query['fields'], 'Canonical query filesorted complete post rows.' );
assert_same( 200, $site_query['posts_per_page'], 'Production candidate window is no longer bounded at 200 rows.' );

$content_queries    = array();
$query_vars['paged'] = 2;
$site_page_two      = extrachill_get_search_results();
assert_same( 1, count( $content_queries ), 'Pagination reran or skipped the site content query.' );
assert_same( array( 23 ), wp_list_pluck( $site_page_two['results'], 'ID' ), 'Exact pagination changed.' );

$content_queries    = array();
$query_vars['paged'] = 1;
$search_scope       = 'network';
$network_instance_offset = count( WP_Query::$instances );
$network_page       = extrachill_get_search_results();
assert_same( 2, count( $content_queries ), 'Network request did not execute one content query per targeted site.' );
assert_same( 4, $network_page['total'], 'Multisite aggregation changed the rendered total.' );
$network_instances = array_slice( WP_Query::$instances, $network_instance_offset );
assert_same( array( 2, 3 ), wp_list_pluck( $network_instances, 'blog_id' ), 'Network routing omitted a targeted site.' );

// Broad and selective requests retain one bounded FULLTEXT query per site.
$content_queries = array();
extrachill_network_search( 'music', array( 'site2.test' ) );
extrachill_network_search( 'needlefest 21', array( 'site2.test' ) );
assert_same( 2, count( $content_queries ), 'Broad or selective search duplicated the canonical query.' );
assert_same( false, false !== strpos( $content_queries[0], '+music*' ), 'Broad search expanded the indexed token prefix.' );

// Empty canonical and fallback result sets remain empty without leaking sites.
reset_search_state();
WP_Query::$posts_by_blog = array( 2 => array() );
$empty_results = extrachill_network_search( 'absent', array( 'site2.test' ), array( 'return_count' => true ) );
assert_same( array(), $empty_results['results'], 'Empty search returned hydrated results.' );
assert_same( 0, $empty_results['total'], 'Empty search returned a non-zero total.' );
assert_same( 1, get_current_blog_id(), 'Empty search leaked the switched blog context.' );

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

endif;
