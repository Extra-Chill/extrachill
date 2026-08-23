<?php
/**
 * Regression tests for indexed search routing and readiness.
 *
 * Run with: php tests/SearchIndexReadinessTest.php
 *
 * @package ExtraChill\Search
 */

declare( strict_types=1 );

if ( ! function_exists( 'get_current_blog_id' ) ) :

define( 'ABSPATH', __DIR__ . '/' );

$current_blog_id = 1;
$blog_stack      = array();
$actions         = array();
$is_admin        = false;

class Search_Index_Test_WPDB {
	public $posts = 'c8c_posts';
	public $last_error = '';
	public $rows = array();
	public $queries = array();

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[is]/', (string) $arg, $query, 1 );
		}
		return $query;
	}

	public function get_results( $query ) {
		$this->queries[] = $query;
		return $this->rows;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return 0;
	}

	public function _real_escape( $value ) {
		return addslashes( $value );
	}
}

class WP_Query {
	public static $instances = array();
	private $vars;
	private $main;
	private $search;

	public function __construct( $vars = array(), $main = false, $search = false ) {
		$this->vars   = $vars;
		$this->main   = $main;
		$this->search = $search;
		self::$instances[] = $vars;
	}

	public function get( $key ) {
		return $this->vars[ $key ] ?? '';
	}

	public function set( $key, $value ) {
		$this->vars[ $key ] = $value;
	}

	public function is_main_query() {
		return $this->main;
	}

	public function is_search() {
		return $this->search;
	}

	public function have_posts() {
		return false;
	}
}

$wpdb = new Search_Index_Test_WPDB();

function fulltext_rows( $name = 'ft_search', $columns = array( 'post_title', 'post_excerpt', 'post_content' ) ) {
	$rows = array();
	foreach ( $columns as $index => $column ) {
		$rows[] = (object) array(
			'Key_name'      => $name,
			'Seq_in_index'  => $index + 1,
			'Column_name'   => $column,
		);
	}
	return $rows;
}

function get_current_blog_id() {
	global $current_blog_id;
	return $current_blog_id;
}

function switch_to_blog( $blog_id ) {
	global $blog_stack, $current_blog_id, $wpdb;
	$blog_stack[]    = $current_blog_id;
	$current_blog_id = $blog_id;
	$wpdb->posts     = 1 === $blog_id ? 'c8c_posts' : 'c8c_' . $blog_id . '_posts';
	return true;
}

function restore_current_blog() {
	global $blog_stack, $current_blog_id, $wpdb;
	$current_blog_id = array_pop( $blog_stack );
	$wpdb->posts     = 1 === $current_blog_id ? 'c8c_posts' : 'c8c_' . $current_blog_id . '_posts';
	return true;
}

function do_action( $name, ...$args ) {
	global $actions;
	$actions[] = array( $name, $args );
}

function is_admin() {
	global $is_admin;
	return $is_admin;
}

function is_user_logged_in() {
	return false;
}

function __( $text ) {
	return $text;
}

function esc_html__( $text ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function extrachill_get_network_sites() {
	return array( array( 'id' => 7, 'url' => 'events.extrachill.com' ) );
}

function extrachill_normalize_search_term( $term ) {
	return trim( $term );
}

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
	}
}

function assert_contains( $needle, $haystack, $message ) {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/inc/core/index-health.php';
require_once dirname( __DIR__ ) . '/inc/core/search-algorithm.php';

// Exact index readiness and stale request-cache refresh.
$wpdb->rows = fulltext_rows();
assert_same( true, extrachill_has_fulltext_index(), 'Exact FULLTEXT index was not detected.' );
$wpdb->rows = array();
assert_same( true, extrachill_has_fulltext_index(), 'Request cache did not retain readiness.' );
assert_same( false, extrachill_has_fulltext_index( true ), 'Refresh did not invalidate stale readiness.' );

// Column drift must not count as ready, regardless of index name.
$wpdb->rows = fulltext_rows( 'custom_name', array( 'post_title', 'post_content' ) );
$status     = extrachill_get_fulltext_index_status( true );
assert_same( false, $status['ready'], 'Mismatched FULLTEXT columns were accepted.' );
assert_same( 'column_mismatch', $status['reason'], 'Column drift was not diagnosed.' );

// Dry-run repair is site-scoped and uses the Events table prefix without mutation.
$wpdb->rows    = array();
$wpdb->queries = array();
$repair        = extrachill_ensure_fulltext_index( 7, false );
assert_same( 'add_index', $repair['action'], 'Missing index did not produce a repair preview.' );
assert_same( false, $repair['applied'], 'Dry-run repair mutated schema.' );
assert_contains( 'ALTER TABLE c8c_7_posts', $repair['sql'], 'Repair did not target the Events posts table.' );
assert_same( 1, get_current_blog_id(), 'Repair leaked switched-blog state.' );
$wpdb->rows = fulltext_rows();
$ready      = extrachill_ensure_fulltext_index( 7, false );
assert_same( 'none', $ready['action'], 'Ready index did not make repair idempotent.' );

// Main frontend searches route through FULLTEXT and skip discarded exact totals.
$wpdb->rows = fulltext_rows();
extrachill_get_fulltext_index_status( true );
$query      = new WP_Query( array( 's' => 'live music' ), true, true );
extrachill_route_frontend_search( $query );
assert_same( 'live music', $query->get( 'extrachill_fulltext_term' ), 'Frontend caller bypassed FULLTEXT routing.' );
assert_same( true, $query->get( 'no_found_rows' ), 'Discarded main query still requested exact totals.' );
$search_sql = extrachill_fulltext_posts_search( ' AND legacy LIKE scan', $query );
$order_sql  = extrachill_fulltext_posts_orderby( 'post_date DESC', $query );
assert_contains( 'MATCH(c8c_posts.post_title, c8c_posts.post_excerpt, c8c_posts.post_content)', $search_sql, 'Search SQL did not use MATCH().' );
assert_same( false, false !== strpos( $search_sql, 'LIKE' ), 'FULLTEXT SQL retained LIKE predicates.' );
assert_contains( "c8c_posts.post_password = ''", $search_sql, 'Public FULLTEXT SQL exposed password-protected posts.' );
assert_contains( "AGAINST('+live* +music*' IN BOOLEAN MODE) DESC", $order_sql, 'FULLTEXT relevance order was not applied.' );

$secondary_query = new WP_Query( array( 's' => 'live music' ), false, true );
extrachill_route_frontend_search( $secondary_query );
assert_same( '', $secondary_query->get( 'extrachill_fulltext_term' ), 'Secondary query was incorrectly routed as public search.' );

// Missing readiness fails closed for both public and programmatic callers.
$wpdb->rows = array();
extrachill_get_fulltext_index_status( true );
$query      = new WP_Query( array( 's' => 'festival' ), true, true );
extrachill_route_frontend_search( $query );
assert_same( '', $query->get( 's' ), 'Missing index retained native search input.' );
assert_same( array( 0 ), $query->get( 'post__in' ), 'Missing index did not fail the public query closed.' );
try {
	extrachill_fulltext_query( array(), 'festival' );
	throw new RuntimeException( 'Missing index unexpectedly ran a programmatic query.' );
} catch ( RuntimeException $error ) {
	assert_contains( 'Search index is not ready', $error->getMessage(), 'Unexpected missing-index exception.' );
}

fwrite( STDOUT, "Search index readiness tests passed.\n" );

endif;
