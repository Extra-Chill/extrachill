<?php

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class EcTestActivationException extends RuntimeException {}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function __( $text ) { return $text; }
function _x( $text ) { return $text; }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/' ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function register_activation_hook( $file, $callback ) { $GLOBALS['ec_test']['activation_hook'] = $callback; }
function register_deactivation_hook( $file, $callback ) { $GLOBALS['ec_test']['deactivation_hook'] = $callback; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['ec_test']['actions'][] = array( $hook, $callback, $priority ); }
function do_action( $hook, ...$args ) { $GLOBALS['ec_test']['fired_actions'][] = array( $hook, $args ); }
function did_action( $hook ) { return (int) ( $GLOBALS['ec_test']['did_actions'][ $hook ] ?? 0 ); }
function esc_html( $value ) { return $value; }
function wp_die( $message ) { throw new EcTestActivationException( $message ); }
function is_multisite() { return ! empty( $GLOBALS['ec_test']['multisite'] ); }
function get_site_option( $key, $default = false ) { return $GLOBALS['ec_test']['site_options'][ $key ] ?? $default; }
function get_sites( $args = array() ) {
	$GLOBALS['ec_test']['site_queries'][] = $args;
	$ids = array_keys( $GLOBALS['ec_test']['blogs'] );
	sort( $ids, SORT_NUMERIC );
	return array_slice( $ids, (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 100 ) );
}
function flush_rewrite_rules() {
	$blog_id = get_current_blog_id();
	if ( (int) ( $GLOBALS['ec_test']['throw_flush_on_blog'] ?? 0 ) === $blog_id ) {
		throw new RuntimeException( 'flush failed' );
	}
	$GLOBALS['ec_test']['site_flushes'][ $blog_id ] = ( $GLOBALS['ec_test']['site_flushes'][ $blog_id ] ?? 0 ) + 1;
	$GLOBALS['ec_test']['site_rule_snapshots'][ $blog_id ][] = post_type_exists( EC_LINK_PAGE_POST_TYPE );
	++$GLOBALS['ec_test']['flushes'];
}

function ec_test_reset() {
	unset( $GLOBALS['ec_link_pages_runtime_error'], $GLOBALS['ec_link_pages_queued_site_flushes'], $GLOBALS['ec_link_pages_owns_post_type'] );
	$GLOBALS['_wp_switched_stack'] = array();
	$GLOBALS['switched'] = false;
	$GLOBALS['ec_test'] = array(
		'current_blog_id' => 4,
		'blog_stack' => array(),
		'flushes' => 0,
		'next_meta_id' => 0,
		'registered_post_types' => array(),
		'multisite' => true,
		'did_actions' => array( 'wp_loaded' => 1 ),
		'blogs' => array(
			4 => array( 'posts' => array(), 'post_meta' => array(), 'terms' => array() ),
			7 => array( 'posts' => array(), 'post_meta' => array(), 'terms' => array() ),
		),
	);
	foreach ( array( ec_link_page_owner_compatibility_registry(), ec_link_page_operation_provider_registry() ) as $registry ) {
		$reflection = new ReflectionObject( $registry );
		$property = $reflection->getProperty( 'providers' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );
	}
}

function get_current_blog_id() { return $GLOBALS['ec_test']['current_blog_id']; }
function switch_to_blog( $blog_id ) {
	if ( (int) ( $GLOBALS['ec_test']['fail_switch_to_blog'] ?? 0 ) === (int) $blog_id ) {
		return false;
	}
	$current = get_current_blog_id();
	$GLOBALS['ec_test']['blog_stack'][] = $current;
	$GLOBALS['_wp_switched_stack'][] = $current;
	$GLOBALS['ec_test']['current_blog_id'] = (int) $blog_id;
	$GLOBALS['switched'] = true;
	return true;
}
function restore_current_blog() {
	if ( $GLOBALS['ec_test']['blog_stack'] ) {
		$GLOBALS['ec_test']['current_blog_id'] = array_pop( $GLOBALS['ec_test']['blog_stack'] );
	}
	if ( $GLOBALS['_wp_switched_stack'] ) {
		array_pop( $GLOBALS['_wp_switched_stack'] );
	}
	$GLOBALS['switched'] = (bool) $GLOBALS['_wp_switched_stack'];
	return true;
}
function get_site( $blog_id ) {
	if ( ! isset( $GLOBALS['ec_test']['blogs'][ $blog_id ] ) ) {
		return null;
	}
	return (object) array_merge( array( 'deleted' => 0, 'archived' => 0, 'spam' => 0 ), $GLOBALS['ec_test']['sites'][ $blog_id ] ?? array() );
}
function ec_test_store( $key ) { return $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ][ $key ]; }
function get_post( $post_id ) { $posts = ec_test_store( 'posts' ); return $posts[ $post_id ] ?? null; }
function get_post_type( $post_id ) { $post = get_post( $post_id ); return $post->post_type ?? false; }
function post_type_exists( $post_type ) {
	if ( isset( $GLOBALS['ec_test']['registered_post_types'][ $post_type ] ) ) { return true; }
	foreach ( ec_test_store( 'posts' ) as $post ) { if ( $post_type === $post->post_type ) { return true; } }
	return false;
}
function taxonomy_exists( $taxonomy ) {
	foreach ( ec_test_store( 'terms' ) as $term ) { if ( $taxonomy === $term->taxonomy ) { return true; } }
	return false;
}
function get_term( $term_id, $taxonomy = '' ) {
	$terms = ec_test_store( 'terms' );
	$term = $terms[ $term_id ] ?? null;
	return $term && ( ! $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
}
function register_post_type( $post_type, $args ) {
	$GLOBALS['ec_test']['registered_post_types'][ $post_type ] = $args;
	return (object) $args;
}
function unregister_post_type( $post_type ) {
	$blog_id = get_current_blog_id();
	$GLOBALS['ec_test']['unregister_calls'][ $blog_id ] = ( $GLOBALS['ec_test']['unregister_calls'][ $blog_id ] ?? 0 ) + 1;
	if ( ! empty( $GLOBALS['ec_test']['fail_unregister'] ) ) { return new WP_Error( 'invalid_post_type', 'Failed.' ); }
	if ( ! isset( $GLOBALS['ec_test']['registered_post_types'][ $post_type ] ) ) { return new WP_Error( 'invalid_post_type', 'Missing.' ); }
	unset( $GLOBALS['ec_test']['registered_post_types'][ $post_type ] );
	return true;
}
function get_post_meta( $post_id, $key, $single = false ) {
	$meta = ec_test_store( 'post_meta' );
	if ( ! array_key_exists( $key, $meta[ $post_id ] ?? array() ) ) { return $single ? '' : array(); }
	$value = $meta[ $post_id ][ $key ];
	return $single && is_array( $value ) ? ( $value[0] ?? '' ) : ( $single ? $value : ( is_array( $value ) ? $value : array( $value ) ) );
}
function metadata_exists( $type, $post_id, $key ) {
	return array_key_exists( $key, $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ] ?? array() );
}
function add_post_meta( $post_id, $key, $value, $unique = false ) {
	if ( isset( $GLOBALS['ec_test']['before_add'] ) ) { $callback = $GLOBALS['ec_test']['before_add']; unset( $GLOBALS['ec_test']['before_add'] ); $callback(); }
	if ( $unique && metadata_exists( 'post', $post_id, $key ) ) { return false; }
	$blog_id = get_current_blog_id();
	$GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ] = $value;
	$meta_id = ++$GLOBALS['ec_test']['next_meta_id'];
	$GLOBALS['ec_test']['meta_rows'][ $meta_id ] = array( 'blog_id' => $blog_id, 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value );
	if ( isset( $GLOBALS['ec_test']['after_add'] ) ) { $callback = $GLOBALS['ec_test']['after_add']; unset( $GLOBALS['ec_test']['after_add'] ); $callback(); }
	return $meta_id;
}
function get_metadata_by_mid( $type, $meta_id ) {
	if ( 'post' !== $type || ! isset( $GLOBALS['ec_test']['meta_rows'][ $meta_id ] ) ) { return false; }
	$row = $GLOBALS['ec_test']['meta_rows'][ $meta_id ];
	$current = $GLOBALS['ec_test']['blogs'][ $row['blog_id'] ]['post_meta'][ $row['post_id'] ][ $row['meta_key'] ] ?? null;
	if ( null === $current || ( is_array( $current ) && ! in_array( $row['meta_value'], $current, true ) ) ) { unset( $GLOBALS['ec_test']['meta_rows'][ $meta_id ] ); return false; }
	return (object) $row;
}
function delete_metadata_by_mid( $type, $meta_id ) {
	$row = get_metadata_by_mid( $type, $meta_id );
	if ( ! $row || ! empty( $GLOBALS['ec_test']['fail_delete_mid'] ) ) { return false; }
	$current =& $GLOBALS['ec_test']['blogs'][ $row->blog_id ]['post_meta'][ $row->post_id ][ $row->meta_key ];
	if ( is_array( $current ) ) { $removed = false; $current = array_values( array_filter( $current, static function ( $value ) use ( $row, &$removed ) { if ( ! $removed && $value === $row->meta_value ) { $removed = true; return false; } return true; } ) ); }
	else { unset( $GLOBALS['ec_test']['blogs'][ $row->blog_id ]['post_meta'][ $row->post_id ][ $row->meta_key ] ); }
	unset( $GLOBALS['ec_test']['meta_rows'][ $meta_id ] );
	return true;
}
function get_posts( $args ) {
	$ids = array();
	foreach ( ec_test_store( 'posts' ) as $id => $post ) {
		if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) { continue; }
		if ( isset( $args['meta_key'] ) && (string) get_post_meta( $id, $args['meta_key'], true ) !== (string) $args['meta_value'] ) { continue; }
		$ids[] = (int) $id;
	}
	sort( $ids, SORT_NUMERIC );
	return array_slice( $ids, (int) ( $args['offset'] ?? 0 ), ( $args['posts_per_page'] ?? -1 ) < 0 ? null : (int) $args['posts_per_page'] );
}
function __return_true() { return true; }

if ( getenv( 'LINK_PAGES_USE_FALLBACK' ) ) {
	define( 'EC_LINK_PAGE_POST_TYPE', 'artist_link_page' );
	define( 'EC_LINK_PAGE_OWNER_META_KEY', '_ec_link_page_owner_reference' );
	$fallback = getenv( 'ARTIST_PLATFORM_WORKTREE' ) ?: '/var/lib/datamachine/workspace/extrachill-artist-platform@refactor-152-link-pages-runtime-handoff';
	require_once $fallback . '/inc/link-pages/owner-reference.php';
	require_once $fallback . '/inc/link-pages/operations.php';
}
require_once dirname( __DIR__ ) . '/extrachill-link-pages.php';
ec_test_reset();
