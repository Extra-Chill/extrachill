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

class EcTestWpdb {
	private $locks = array();
	public function prepare( $query, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function get_blog_prefix( $blog_id ) { return 'wp_' . (int) $blog_id . '_'; }
	public function get_row( $query ) {
		if ( preg_match( '/FROM wp_(\d+)_posts WHERE ID = (\d+)/', $query, $matches ) ) {
			$post = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['posts'][ (int) $matches[2] ] ?? null;
			return $post ? (object) array( 'ID' => $post->ID, 'post_type' => $post->post_type, 'post_status' => $post->post_status ?? 'publish' ) : null;
		}
		if ( preg_match( "/FROM wp_(\d+)_term_taxonomy WHERE term_id = (\d+) AND taxonomy = '([^']+)'/", $query, $matches ) ) {
			$term = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['terms'][ (int) $matches[2] ] ?? null;
			return $term && $term->taxonomy === $matches[3] ? (object) array( 'term_id' => $term->term_id, 'taxonomy' => $term->taxonomy ) : null;
		}
		if ( preg_match( '/FROM wp_(\d+)_term_taxonomy WHERE term_id = (\d+)/', $query, $matches ) ) {
			$term = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['terms'][ (int) $matches[2] ] ?? null;
			return $term ? (object) array( 'term_id' => $term->term_id, 'taxonomy' => $term->taxonomy ) : null;
		}
		return null;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			++$GLOBALS['ec_test']['lock_acquires'];
			if ( ! empty( $GLOBALS['ec_test']['fail_advisory_lock'] ) || ! empty( $this->locks ) ) { return '0'; }
			$this->locks[] = $query;
			$GLOBALS['ec_test']['advisory_lock_held'] = true;
			return '1';
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) { ++$GLOBALS['ec_test']['lock_releases']; $this->locks = array(); $GLOBALS['ec_test']['advisory_lock_held'] = false; }
		return '1';
	}
}

$GLOBALS['wpdb'] = new EcTestWpdb();

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function __( $text ) { return $text; }
function _x( $text ) { return $text; }
function esc_attr_e( $text ) { echo $text; }
function esc_html_e( $text ) { echo $text; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return esc_attr( $value ); }
function esc_url_raw( $value, $protocols = null ) {
	$url = filter_var( (string) $value, FILTER_SANITIZE_URL );
	if ( $protocols && $url && ! in_array( strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ), $protocols, true ) ) { return ''; }
	return $url;
}
function esc_js( $value ) { return addslashes( (string) $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_title( $value ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ), '-' ); }
function sanitize_hex_color( $value ) { return is_string( $value ) && preg_match( '/^#(?:[0-9a-f]{3}){1,2}$/i', $value ) ? strtolower( $value ) : null; }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/' ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function plugins_url( $path ) { return 'https://example.test/plugins/extrachill-link-pages/' . ltrim( $path, '/' ); }
function register_activation_hook( $file, $callback ) { $GLOBALS['ec_test']['activation_hook'] = $callback; }
function register_deactivation_hook( $file, $callback ) { $GLOBALS['ec_test']['deactivation_hook'] = $callback; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['ec_test']['actions'][] = array( $hook, $callback, $priority, $accepted_args ); }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['ec_test']['filters'][ $hook ][] = array( $callback, $priority, $accepted_args ); }
function apply_filters( $hook, $value, ...$args ) {
	$filters = $GLOBALS['ec_test']['filters'][ $hook ] ?? array();
	usort( $filters, static function ( $left, $right ) { return $left[1] <=> $right[1]; } );
	foreach ( $filters as $filter ) { $value = call_user_func_array( $filter[0], array_slice( array_merge( array( $value ), $args ), 0, $filter[2] ) ); }
	return $value;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['ec_test']['fired_actions'][] = array( $hook, $args );
	if ( empty( $GLOBALS['ec_test']['execute_actions'] ) ) { return; }
	$actions = array_values( array_filter( $GLOBALS['ec_test']['actions'] ?? array(), static function ( $action ) use ( $hook ) { return $hook === $action[0]; } ) );
	usort( $actions, static function ( $left, $right ) { return $left[2] <=> $right[2]; } );
	foreach ( $actions as $action ) { call_user_func_array( $action[1], array_slice( $args, 0, $action[3] ) ); }
}
function did_action( $hook ) { return (int) ( $GLOBALS['ec_test']['did_actions'][ $hook ] ?? 0 ); }
function esc_html( $value ) { return $value; }
function wp_die( $message ) { throw new EcTestActivationException( $message ); }
function is_multisite() { return ! empty( $GLOBALS['ec_test']['multisite'] ); }
function get_site_option( $key, $default = false ) { return $GLOBALS['ec_test']['site_options'][ $key ] ?? $default; }
function update_site_option( $key, $value ) { $GLOBALS['ec_test']['site_options'][ $key ] = $value; return true; }
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
	static $base_actions = null;
	static $base_filters = null;
	if ( null === $base_actions ) { $base_actions = $GLOBALS['ec_test']['actions'] ?? array(); }
	if ( null === $base_filters ) { $base_filters = $GLOBALS['ec_test']['filters'] ?? array(); }
	unset( $GLOBALS['ec_link_pages_runtime_error'], $GLOBALS['ec_link_pages_queued_site_flushes'], $GLOBALS['ec_link_pages_owns_post_type'] );
	$GLOBALS['_wp_switched_stack'] = array();
	$GLOBALS['switched'] = false;
	$GLOBALS['ec_test'] = array(
		'current_blog_id' => 4,
		'blog_stack' => array(),
		'flushes' => 0,
		'next_meta_id' => 0,
		'next_post_id' => 100,
		'meta_write_calls' => 0,
		'lock_acquires' => 0,
		'lock_releases' => 0,
		'registered_post_types' => array(),
		'multisite' => true,
		'actions' => $base_actions,
		'filters' => $base_filters,
		'did_actions' => array( 'wp_loaded' => 1 ),
		'blogs' => array(
			4 => array( 'posts' => array(), 'post_meta' => array(), 'terms' => array() ),
			7 => array( 'posts' => array(), 'post_meta' => array(), 'terms' => array() ),
		),
	);
	foreach ( array( ec_link_page_owner_compatibility_registry(), ec_link_page_operation_provider_registry(), ec_link_page_public_projection_registry() ) as $registry ) {
		$reflection = new ReflectionObject( $registry );
		$property = $reflection->getProperty( 'providers' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );
	}
	add_filter( 'ec_link_page_terminate_request', static function ( $terminate, $url, $status ) { $GLOBALS['ec_test']['terminations'][] = array( $url, $status ); return false; }, 10, 3 );
	add_filter( 'ec_link_page_storage_blog_id', static function () { return 4; } );
}
function has_filter( $hook ) { return ! empty( $GLOBALS['ec_test']['filters'][ $hook ] ); }

function get_current_blog_id() { return $GLOBALS['ec_test']['current_blog_id']; }
function get_main_site_id() { return 4; }
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
function get_post_field( $field, $post_id ) { $post = get_post( $post_id ); return $post->{$field} ?? ''; }
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
function get_post_meta( $post_id, $key = '', $single = false ) {
	$meta = ec_test_store( 'post_meta' );
	if ( '' === $key ) { return $meta[ $post_id ] ?? array(); }
	if ( ! array_key_exists( $key, $meta[ $post_id ] ?? array() ) ) { return $single ? '' : array(); }
	$value = $meta[ $post_id ][ $key ];
	return $single && is_array( $value ) ? ( $value[0] ?? '' ) : ( $single ? $value : ( is_array( $value ) ? $value : array( $value ) ) );
}
function update_post_meta( $post_id, $key, $value ) {
	++$GLOBALS['ec_test']['meta_write_calls'];
	if ( ! empty( $GLOBALS['ec_test']['require_advisory_lock'] ) && empty( $GLOBALS['ec_test']['advisory_lock_held'] ) ) { return false; }
	if ( in_array( $GLOBALS['ec_test']['meta_write_calls'], $GLOBALS['ec_test']['fail_meta_write_calls'] ?? array(), true ) ) { return false; }
	$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ][ $key ] = is_array( $value ) ? array( $value ) : $value;
	return true;
}
function delete_post_meta( $post_id, $key, $value = '' ) {
	++$GLOBALS['ec_test']['meta_write_calls'];
	if ( in_array( $GLOBALS['ec_test']['meta_write_calls'], $GLOBALS['ec_test']['fail_meta_write_calls'] ?? array(), true ) ) { return false; }
	$meta =& $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ];
	if ( ! array_key_exists( $key, $meta ?? array() ) ) { return false; }
	if ( '' !== $value && $meta[ $key ] !== $value ) { return false; }
	unset( $meta[ $key ] ); return true;
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
		if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && ( $post->post_status ?? 'publish' ) !== $args['post_status'] ) { continue; }
		if ( isset( $args['name'] ) && ( $post->post_name ?? '' ) !== $args['name'] ) { continue; }
		if ( isset( $args['meta_key'] ) && (string) get_post_meta( $id, $args['meta_key'], true ) !== (string) $args['meta_value'] ) { continue; }
		$ids[] = (int) $id;
	}
	sort( $ids, SORT_NUMERIC );
	$limit = $args['posts_per_page'] ?? ( $args['numberposts'] ?? -1 );
	$ids = array_slice( $ids, (int) ( $args['offset'] ?? 0 ), $limit < 0 ? null : (int) $limit );
	return ( $args['fields'] ?? '' ) === 'ids' ? $ids : array_map( 'get_post', $ids );
}
function wp_insert_post( $data, $wp_error = false ) {
	if ( ! empty( $GLOBALS['ec_test']['insert_error'] ) ) { return new WP_Error( 'insert_failed', 'Insert failed.' ); }
	$id = ++$GLOBALS['ec_test']['next_post_id'];
	$slug = $data['post_name'];
	if ( ! empty( $GLOBALS['ec_test']['mutate_insert_slug'] ) ) { $slug .= '-2'; }
	$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $id ] = (object) array_merge( array( 'ID' => $id, 'post_name' => $slug ), $data, array( 'post_name' => $slug ) );
	return $id;
}
function wp_update_post( $data, $wp_error = false ) {
	if ( ! empty( $GLOBALS['probe_profile_context'] ) ) {
		$scope = $GLOBALS['ec_link_page_lock_scope'] ?? array();
		$GLOBALS['profile_lock_blog'] = $scope['blog_id'] ?? 0;
		$GLOBALS['profile_lock_page'] = $scope['link_page_id'] ?? 0;
		$GLOBALS['profile_mutation_blog'] = get_current_blog_id();
	}
	$post = get_post( $data['ID'] ?? 0 );
	if ( ! $post ) { return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post.' ) : 0; }
	foreach ( $data as $key => $value ) { if ( 'ID' !== $key ) { $post->{$key} = $value; } }
	return (int) $post->ID;
}
function wp_delete_post( $post_id ) { unset( $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $post_id ], $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ] ); return (object) array( 'ID' => $post_id ); }
function wp_get_attachment_url( $id ) { return $id ? 'https://media.example/' . $id . '.jpg' : false; }
function current_time() { return (int) ( $GLOBALS['ec_test']['now'] ?? time() ); }
function current_datetime() { return new DateTimeImmutable( '@' . (int) ( $GLOBALS['ec_test']['now'] ?? time() ) ); }
function get_option( $key, $default = false ) { return $GLOBALS['ec_test']['options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['ec_test']['options'][ $key ] = $value; return true; }
function add_rewrite_tag() {}
function add_rewrite_rule( $regex, $query, $position ) { $GLOBALS['ec_test']['rewrite_rules'][] = compact( 'regex', 'query', 'position' ); }
function status_header( $status ) { $GLOBALS['ec_test']['status'] = $status; }
function wp_redirect( $url, $status ) { $GLOBALS['ec_test']['redirect'] = array( $url, $status, false ); return empty( $GLOBALS['ec_test']['fail_redirect'] ); }
function wp_safe_redirect( $url, $status ) { $GLOBALS['ec_test']['redirect'] = array( $url, $status, true ); return empty( $GLOBALS['ec_test']['fail_redirect'] ); }
function is_singular( $type ) { return ! empty( $GLOBALS['ec_test']['singular'] ) && $type === $GLOBALS['ec_test']['singular']; }
function get_queried_object() { return $GLOBALS['wp_query']->queried_object ?? null; }
function get_the_modified_date( $format, $post_id ) { return '2026-08-23T00:00:00+00:00'; }
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
