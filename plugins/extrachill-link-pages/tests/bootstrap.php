<?php
/**
 * Standalone PHPUnit bootstrap: a fake-WordPress harness for Link Pages.
 *
 * Defines just enough of the WordPress API, backed by $GLOBALS['ec_test'],
 * to load the plugin and exercise it without a WordPress install.
 *
 * @package ExtraChillLinkPages
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

require_once __DIR__ . '/support/class-wp-error.php';
require_once __DIR__ . '/support/class-ectestactivationexception.php';
require_once __DIR__ . '/support/class-ectestmultisiteapiunavailable.php';
require_once __DIR__ . '/support/class-ectestwpdb.php';
require_once __DIR__ . '/support/class-wp-block-type-registry.php';

$GLOBALS['wpdb'] = new EcTestWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the harness installs its fake wpdb.

function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function absint( $value ) {
	return abs( (int) $value ); }
function wp_json_encode( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this is the wp_json_encode() stub.
}
function __( $text ) {
	return $text; }
function _x( $text ) {
	return $text; }
function esc_attr_e( $text ) {
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pass-through stub of the escaping function itself.
}
function esc_html_e( $text ) {
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pass-through stub of the escaping function itself.
}
function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) {
	return esc_attr( $value ); }
function esc_url_raw( $value, $protocols = null ) {
	$url = filter_var( (string) $value, FILTER_SANITIZE_URL );
	if ( $protocols && $url && ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), $protocols, true ) ) {
		return ''; }
	return $url;
}
function esc_js( $value ) {
	return addslashes( (string) $value ); }
function wp_kses_post( $value ) {
	return (string) $value; }
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this is the wp_strip_all_tags() stub.
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this is the wp_parse_url() stub.
}
function wp_unslash( $value ) {
	return $value; }
function sanitize_text_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) ); }
function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_title( $value ) {
	return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ), '-' ); }
function sanitize_hex_color( $value ) {
	return is_string( $value ) && preg_match( '/^#(?:[0-9a-f]{3}){1,2}$/i', $value ) ? strtolower( $value ) : null; }
function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/'; }
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file ); }
function plugins_url( $path ) {
	return 'https://example.test/plugins/extrachill-link-pages/' . ltrim( $path, '/' ); }
function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/'; }
function wp_normalize_path( $value ) {
	return str_replace( '\\', '/', $value ); }
function register_activation_hook( $file, $callback ) {
	$GLOBALS['ec_test']['activation_hook'] = $callback; }
function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['ec_test']['deactivation_hook'] = $callback; }
function register_block_type( $path ) {
	$metadata = json_decode( file_get_contents( rtrim( $path, '/' ) . '/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local block.json.
	WP_Block_Type_Registry::get_instance()->register( $metadata['name'], $path );
	$GLOBALS['ec_test']['registered_blocks'][] = $path;
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ec_test']['actions'][] = array( $hook, $callback, $priority, $accepted_args ); }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ec_test']['filters'][ $hook ][] = array( $callback, $priority, $accepted_args ); }
function apply_filters( $hook, $value, ...$args ) {
	$filters = $GLOBALS['ec_test']['filters'][ $hook ] ?? array();
	usort(
		$filters,
		static function ( $left, $right ) {
			return $left[1] <=> $right[1];
		}
	);
	foreach ( $filters as $filter ) {
		$value = call_user_func_array( $filter[0], array_slice( array_merge( array( $value ), $args ), 0, $filter[2] ) ); }
	return $value;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['ec_test']['fired_actions'][] = array( $hook, $args );
	if ( empty( $GLOBALS['ec_test']['execute_actions'] ) ) {
		return; }
	$actions = array_values(
		array_filter(
			$GLOBALS['ec_test']['actions'] ?? array(),
			static function ( $action ) use ( $hook ) {
				return $hook === $action[0];
			}
		)
	);
	usort(
		$actions,
		static function ( $left, $right ) {
			return $left[2] <=> $right[2];
		}
	);
	foreach ( $actions as $action ) {
		call_user_func_array( $action[1], array_slice( $args, 0, $action[3] ) ); }
}
function did_action( $hook ) {
	return (int) ( $GLOBALS['ec_test']['did_actions'][ $hook ] ?? 0 ); }
function esc_html( $value ) {
	return $value; }
function wp_die( $message ) {
	throw new EcTestActivationException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test exception, never rendered.
}
function is_multisite() {
	return ! empty( $GLOBALS['ec_test']['multisite'] ); }
function get_site_option( $key, $default_value = false ) {
	return $GLOBALS['ec_test']['site_options'][ $key ] ?? $default_value; }
function update_site_option( $key, $value ) {
	$GLOBALS['ec_test']['site_options'][ $key ] = $value;
	return true; }
function get_network_option( $network_id, $key, $default_value = false ) {
	return $GLOBALS['ec_test']['site_options'][ $key ] ?? $default_value; }
function update_network_option( $network_id, $key, $value ) {
	$call = ++$GLOBALS['ec_test']['network_option_write_calls'];
	if ( ! empty( $GLOBALS['ec_test']['fail_network_option_write'] ) || in_array( $call, $GLOBALS['ec_test']['fail_network_option_write_calls'] ?? array(), true ) ) {
		return false;
	} $GLOBALS['ec_test']['site_options'][ $key ] = $value;
	return true; }
function delete_network_option( $network_id, $key ) {
	unset( $GLOBALS['ec_test']['site_options'][ $key ] );
	return true; }
function get_current_network_id() {
	return 1; }
function get_sites( $args = array() ) {
	$GLOBALS['ec_test']['site_queries'][] = $args;
	$ids                                  = array_keys( $GLOBALS['ec_test']['blogs'] );
	sort( $ids, SORT_NUMERIC );
	return array_slice( $ids, (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 100 ) );
}
function flush_rewrite_rules() {
	$blog_id = get_current_blog_id();
	if ( (int) ( $GLOBALS['ec_test']['throw_flush_on_blog'] ?? 0 ) === $blog_id ) {
		throw new RuntimeException( 'flush failed' );
	}
	$GLOBALS['ec_test']['site_flushes'][ $blog_id ]          = ( $GLOBALS['ec_test']['site_flushes'][ $blog_id ] ?? 0 ) + 1;
	$GLOBALS['ec_test']['site_rule_snapshots'][ $blog_id ][] = post_type_exists( 'ec_link_page' );
	++$GLOBALS['ec_test']['flushes'];
}

function ec_test_reset() {
	static $base_actions = null;
	static $base_filters = null;
	if ( null === $base_actions ) {
		$base_actions = $GLOBALS['ec_test']['actions'] ?? array(); }
	if ( null === $base_filters ) {
		$base_filters = $GLOBALS['ec_test']['filters'] ?? array(); }
	unset( $GLOBALS['ec_link_pages_runtime_error'], $GLOBALS['ec_link_pages_queued_site_flushes'], $GLOBALS['ec_link_pages_owns_post_type'] );
	$GLOBALS['_wp_switched_stack'] = array();
	$GLOBALS['switched']           = false;
	$GLOBALS['ec_test']            = array(
		'current_blog_id'            => 4,
		'blog_stack'                 => array(),
		'flushes'                    => 0,
		'next_meta_id'               => 0,
		'next_post_id'               => 100,
		'network_option_write_calls' => 0,
		'meta_write_calls'           => 0,
		'lock_acquires'              => 0,
		'lock_releases'              => 0,
		'registered_post_types'      => array(),
		'multisite'                  => true,
		'actions'                    => $base_actions,
		'filters'                    => $base_filters,
		'did_actions'                => array( 'wp_loaded' => 1 ),
		'blogs'                      => array(
			4  => array(
				'posts'     => array(),
				'post_meta' => array(),
				'terms'     => array(),
			),
			7  => array(
				'posts'     => array(),
				'post_meta' => array(),
				'terms'     => array(),
			),
			// The dedicated Link Pages site (EC_BLOG_ID_LINK_PAGES in
			// extrachill-network). Only reachable by an explicit blog ID —
			// the storage filter below always resolves 4, so ordinary tests
			// never touch it and stay legacy-only.
			13 => array(
				'posts'     => array(),
				'post_meta' => array(),
				'terms'     => array(),
			),
		),
	);
	foreach ( array( 4, 7, 13 ) as $blog_id ) {
		$directory = sys_get_temp_dir() . '/ec-link-pages-blog-' . $blog_id;
		if ( is_dir( $directory ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			// phpcs:disable WordPress.WP.AlternativeFunctions -- resets temp upload dirs; no WP_Filesystem in the harness.
			foreach ( $iterator as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); }
			rmdir( $directory );
		}
		mkdir( $directory, 0777, true );
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}
	foreach ( array( ec_link_page_owner_compatibility_registry(), ec_link_page_operation_provider_registry(), ec_link_page_public_projection_registry() ) as $registry ) {
		$reflection = new ReflectionObject( $registry );
		$property   = $reflection->getProperty( 'providers' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );
	}
	$section_source_registry = ec_link_page_section_source_registry();
	$section_source_property = ( new ReflectionObject( $section_source_registry ) )->getProperty( 'sources' );
	$section_source_property->setAccessible( true );
	$section_source_property->setValue( $section_source_registry, array() );
	add_filter(
		'ec_link_page_terminate_request',
		static function ( $terminate, $url, $status ) {
			$GLOBALS['ec_test']['terminations'][] = array( $url, $status );
			return false;
		},
		10,
		3
	);
	add_filter(
		'ec_link_page_storage_blog_id',
		static function () {
			return 4;
		}
	);
}
function has_filter( $hook ) {
	return ! empty( $GLOBALS['ec_test']['filters'][ $hook ] ); }

function get_current_blog_id() {
	return $GLOBALS['ec_test']['current_blog_id']; }
function get_main_site_id() {
	return 4; }
function switch_to_blog( $blog_id ) {
	if ( (int) ( $GLOBALS['ec_test']['fail_switch_to_blog'] ?? 0 ) === (int) $blog_id ) {
		return false;
	}
	$current                               = get_current_blog_id();
	$GLOBALS['ec_test']['blog_stack'][]    = $current;
	$GLOBALS['_wp_switched_stack'][]       = $current;
	$GLOBALS['ec_test']['current_blog_id'] = (int) $blog_id;
	$GLOBALS['switched']                   = true;
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
/**
 * Multisite-only site lookup.
 *
 * WordPress defines get_site() in wp-includes/ms-site.php, which is loaded
 * only on multisite. Calling it on single site is a fatal "undefined function"
 * error. This harness cannot un-define a function, so it models that boundary
 * instead: invoking get_site() while is_multisite() is false throws, which
 * fails any test whose code path would fatal on a real single-site install.
 *
 * Without this guard the harness silently grants production code a multisite
 * API that will not exist at runtime — the exact gap that let a single-site
 * activation fatal ship undetected (issue #15).
 *
 * @throws EcTestMultisiteApiUnavailable When called while is_multisite() is false.
 */
function get_site( $blog_id ) {
	if ( ! is_multisite() ) {
		throw new EcTestMultisiteApiUnavailable(
			'get_site() is unavailable on single-site WordPress; guard the call with function_exists( \'get_site\' ) or is_multisite().'
		);
	}
	if ( ! isset( $GLOBALS['ec_test']['blogs'][ $blog_id ] ) ) {
		return null;
	}
	return (object) array_merge(
		array(
			'deleted'    => 0,
			'archived'   => 0,
			'spam'       => 0,
			'network_id' => 1,
		),
		$GLOBALS['ec_test']['sites'][ $blog_id ] ?? array()
	);
}
function ec_test_store( $key ) {
	return $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ][ $key ]; }
function get_post( $post_id ) {
	$posts = ec_test_store( 'posts' );
	return $posts[ $post_id ] ?? null; }
function get_post_type( $post_id ) {
	// Core accepts a post object or an ID.
	$post = is_object( $post_id ) ? $post_id : get_post( $post_id );
	return $post->post_type ?? false; }
function get_post_field( $field, $post_id ) {
	$post = get_post( $post_id );
	return $post->{$field} ?? ''; }
function post_type_exists( $post_type ) {
	if ( isset( $GLOBALS['ec_test']['registered_post_types'][ $post_type ] ) ) {
		return true; }
	foreach ( ec_test_store( 'posts' ) as $post ) {
		if ( $post_type === $post->post_type ) {
			return true; }
	}
	return false;
}
function taxonomy_exists( $taxonomy ) {
	foreach ( ec_test_store( 'terms' ) as $term ) {
		if ( $taxonomy === $term->taxonomy ) {
			return true; }
	}
	return false;
}
function get_term( $term_id, $taxonomy = '' ) {
	$terms = ec_test_store( 'terms' );
	$term  = $terms[ $term_id ] ?? null;
	return $term && ( ! $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
}
function register_post_type( $post_type, $args ) {
	$GLOBALS['ec_test']['registered_post_types'][ $post_type ] = $args;
	return (object) $args;
}
function unregister_post_type( $post_type ) {
	$blog_id = get_current_blog_id();
	$GLOBALS['ec_test']['unregister_calls'][ $blog_id ] = ( $GLOBALS['ec_test']['unregister_calls'][ $blog_id ] ?? 0 ) + 1;
	if ( ! empty( $GLOBALS['ec_test']['fail_unregister'] ) ) {
		return new WP_Error( 'invalid_post_type', 'Failed.' ); }
	if ( ! isset( $GLOBALS['ec_test']['registered_post_types'][ $post_type ] ) ) {
		return new WP_Error( 'invalid_post_type', 'Missing.' ); }
	unset( $GLOBALS['ec_test']['registered_post_types'][ $post_type ] );
	return true;
}
function get_post_meta( $post_id, $key = '', $single = false ) {
	$meta = ec_test_store( 'post_meta' );
	if ( '' === $key ) {
		return $meta[ $post_id ] ?? array(); }
	if ( ! array_key_exists( $key, $meta[ $post_id ] ?? array() ) ) {
		return $single ? '' : array(); }
	$value = $meta[ $post_id ][ $key ];
	return $single && is_array( $value ) ? ( $value[0] ?? '' ) : ( $single ? $value : ( is_array( $value ) ? $value : array( $value ) ) );
}
function update_post_meta( $post_id, $key, $value ) {
	++$GLOBALS['ec_test']['meta_write_calls'];
	if ( ! empty( $GLOBALS['ec_test']['require_advisory_lock'] ) && empty( $GLOBALS['ec_test']['advisory_lock_held'] ) ) {
		return false; }
	if ( in_array( $GLOBALS['ec_test']['meta_write_calls'], $GLOBALS['ec_test']['fail_meta_write_calls'] ?? array(), true ) ) {
		return false; }
	// WordPress stores scalars as strings; mirror it so type-sensitive
	// verification is exercised the way production runs it.
	if ( is_bool( $value ) ) {
		$value = $value ? '1' : '';
	} elseif ( is_int( $value ) || is_float( $value ) ) {
		$value = (string) $value;
	}
	$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ][ $key ] = is_array( $value ) ? array( $value ) : $value;
	return true;
}
function delete_post_meta( $post_id, $key, $value = '' ) {
	++$GLOBALS['ec_test']['meta_write_calls'];
	if ( in_array( $GLOBALS['ec_test']['meta_write_calls'], $GLOBALS['ec_test']['fail_meta_write_calls'] ?? array(), true ) ) {
		return false; }
	$meta =& $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ];
	if ( ! array_key_exists( $key, $meta ?? array() ) ) {
		return false; }
	if ( '' !== $value && $meta[ $key ] !== $value ) {
		return false; }
	unset( $meta[ $key ] );
	foreach ( $GLOBALS['ec_test']['meta_rows'] ?? array() as $meta_id => $row ) {
		if ( get_current_blog_id() === (int) $row['blog_id'] && (int) $post_id === (int) $row['post_id'] && $key === $row['meta_key'] ) {
			unset( $GLOBALS['ec_test']['meta_rows'][ $meta_id ] ); }
	}
	return true;
}
function metadata_exists( $type, $post_id, $key ) {
	return array_key_exists( $key, $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ] ?? array() );
}
function add_post_meta( $post_id, $key, $value, $unique = false ) {
	if ( isset( $GLOBALS['ec_test']['before_add'] ) ) {
		$callback = $GLOBALS['ec_test']['before_add'];
		unset( $GLOBALS['ec_test']['before_add'] );
		$callback(); }
	if ( $unique && metadata_exists( 'post', $post_id, $key ) ) {
		return false; }
	$blog_id = get_current_blog_id();
	$GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ] = $value;
	$meta_id                                     = ++$GLOBALS['ec_test']['next_meta_id'];
	$GLOBALS['ec_test']['meta_rows'][ $meta_id ] = array(
		'blog_id'    => $blog_id,
		'post_id'    => $post_id,
		'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- in-memory row, not a query.
		'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- in-memory row, not a query.
	);
	if ( isset( $GLOBALS['ec_test']['after_add'] ) ) {
		$callback = $GLOBALS['ec_test']['after_add'];
		unset( $GLOBALS['ec_test']['after_add'] );
		$callback(); }
	return $meta_id;
}
function get_metadata_by_mid( $type, $meta_id ) {
	if ( 'post' !== $type || ! isset( $GLOBALS['ec_test']['meta_rows'][ $meta_id ] ) ) {
		return false; }
	$row     = $GLOBALS['ec_test']['meta_rows'][ $meta_id ];
	$current = $GLOBALS['ec_test']['blogs'][ $row['blog_id'] ]['post_meta'][ $row['post_id'] ][ $row['meta_key'] ] ?? null;
	$value   = maybe_unserialize( $row['meta_value'] );
	if ( null === $current || ( is_array( $current ) && ! in_array( $value, $current, true ) ) ) {
		unset( $GLOBALS['ec_test']['meta_rows'][ $meta_id ] );
		return false; }
	return (object) $row;
}
function delete_metadata_by_mid( $type, $meta_id ) {
	$row = get_metadata_by_mid( $type, $meta_id );
	if ( ! $row || ! empty( $GLOBALS['ec_test']['fail_delete_mid'] ) ) {
		return false; }
	$current =& $GLOBALS['ec_test']['blogs'][ $row->blog_id ]['post_meta'][ $row->post_id ][ $row->meta_key ];
	if ( is_array( $current ) ) {
		$removed  = false;
		$expected = maybe_unserialize( $row->meta_value );
		$current  = array_values(
			array_filter(
				$current,
				static function ( $value ) use ( $expected, &$removed ) {
					if ( ! $removed && $value === $expected ) {
						$removed = true;
						return false;
					} return true;
				}
			)
		); } else {
		unset( $GLOBALS['ec_test']['blogs'][ $row->blog_id ]['post_meta'][ $row->post_id ][ $row->meta_key ] ); }
		unset( $GLOBALS['ec_test']['meta_rows'][ $meta_id ] );
		return true;
}
function get_posts( $args ) {
	$ids = array();
	foreach ( ec_test_store( 'posts' ) as $id => $post ) {
		if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
			continue; }
		if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && ( $post->post_status ?? 'publish' ) !== $args['post_status'] ) {
			continue; }
		if ( isset( $args['name'] ) && ( $post->post_name ?? '' ) !== $args['name'] ) {
			continue; }
		if ( isset( $args['meta_key'] ) && (string) get_post_meta( $id, $args['meta_key'], true ) !== (string) $args['meta_value'] ) {
			continue; }
		$ids[] = (int) $id;
	}
	sort( $ids, SORT_NUMERIC );
	$limit = $args['posts_per_page'] ?? ( $args['numberposts'] ?? -1 );
	$ids   = array_slice( $ids, (int) ( $args['offset'] ?? 0 ), $limit < 0 ? null : (int) $limit );
	return ( $args['fields'] ?? '' ) === 'ids' ? $ids : array_map( 'get_post', $ids );
}
function wp_insert_post( $data, $wp_error = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	if ( ! empty( $GLOBALS['ec_test']['insert_error'] ) ) {
		return new WP_Error( 'insert_failed', 'Insert failed.' ); }
	if ( isset( $GLOBALS['ec_test']['before_insert_post'] ) ) {
		$callback = $GLOBALS['ec_test']['before_insert_post'];
		unset( $GLOBALS['ec_test']['before_insert_post'] );
		$callback( $data ); }
	$requested = (int) ( $data['import_id'] ?? 0 );
	$id        = $requested && ! get_post( $requested ) && empty( $GLOBALS['ec_test']['force_import_fallback'] ) ? $requested : ++$GLOBALS['ec_test']['next_post_id'];
	while ( get_post( $id ) ) {
		$id = ++$GLOBALS['ec_test']['next_post_id']; }
	$slug = wp_unique_post_slug( $data['post_name'], $id, $data['post_status'] ?? 'draft', $data['post_type'] ?? 'post', (int) ( $data['post_parent'] ?? 0 ) );
	if ( ! empty( $GLOBALS['ec_test']['mutate_insert_slug'] ) ) {
		$slug .= '-2'; }
	$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $id ] = (object) array_merge(
		array(
			'ID'        => $id,
			'post_name' => $slug,
		),
		$data,
		array( 'post_name' => $slug )
	);
	// Core ignores supplied modified timestamps on insert, using post_date.
	$inserted                    = $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $id ];
	$inserted->post_modified     = $data['post_date'] ?? '2030-01-01 00:00:00';
	$inserted->post_modified_gmt = $data['post_date_gmt'] ?? '2030-01-01 00:00:00';
	// Core fills an empty guid after insert; mirror it.
	if ( '' === (string) ( $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $id ]->guid ?? '' ) ) {
		$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $id ]->guid = 'https://generated.test/?p=' . $id;
	}
	if ( isset( $GLOBALS['ec_test']['after_insert_post'] ) ) {
		$callback = $GLOBALS['ec_test']['after_insert_post'];
		unset( $GLOBALS['ec_test']['after_insert_post'] );
		$callback( $id ); }
	return $id;
}
function wp_insert_attachment( $data, $file = false, $parent_post_id = 0, $wp_error = false ) {
	$data['post_type']   = 'attachment';
	$data['post_parent'] = $parent_post_id;
	return wp_insert_post( $data, $wp_error ); }
function wp_update_post( $data, $wp_error = false ) {
	if ( ! empty( $GLOBALS['probe_profile_context'] ) ) {
		$scope                            = $GLOBALS['ec_link_page_lock_scope'] ?? array();
		$GLOBALS['profile_lock_blog']     = $scope['blog_id'] ?? 0;
		$GLOBALS['profile_lock_page']     = $scope['link_page_id'] ?? 0;
		$GLOBALS['profile_mutation_blog'] = get_current_blog_id();
	}
	$post = get_post( $data['ID'] ?? 0 );
	if ( ! $post ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post.' ) : 0; }
	foreach ( $data as $key => $value ) {
		if ( 'ID' !== $key ) {
			$post->{$key} = $value; }
	}
	// Core rewrites modified timestamps on every update, regardless of input.
	$post->post_modified     = '2030-01-01 00:00:00';
	$post->post_modified_gmt = '2030-01-01 00:00:00';
	// Core fills an empty guid on update too.
	if ( '' === (string) ( $post->guid ?? '' ) ) {
		$post->guid = 'https://generated.test/?p=' . $post->ID;
	}
	return (int) $post->ID;
}
function wp_delete_post( $post_id ) {
	if ( ! empty( $GLOBALS['ec_test']['fail_wp_delete_post'] ) ) {
		return false; }
	unset( $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $post_id ], $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ] );
	return (object) array( 'ID' => $post_id );
}
function wp_unique_post_slug( $slug, $post_id, $post_status, $post_type, $post_parent ) {
	if ( in_array( $post_status, array( 'draft', 'pending', 'auto-draft' ), true ) || 'revision' === $post_type ) {
		return $slug; }
	$candidate = $slug;
	$suffix    = 2;
	$conflicts = static function ( $value ) use ( $post_id, $post_type, $post_parent ) {
		foreach ( ec_test_store( 'posts' ) as $id => $post ) {
			if ( (int) $id === (int) $post_id || ( $post->post_name ?? '' ) !== $value ) {
				continue; }
			if ( 'attachment' === $post_type || 'attachment' === $post->post_type || ( $post_type === $post->post_type && (int) ( $post->post_parent ?? 0 ) === $post_parent ) ) {
				return true; }
		}
		return false;
	};
	while ( $conflicts( $candidate ) ) {
		$candidate = $slug . '-' . ( $suffix++ ); }
	return $candidate;
}
function wp_get_attachment_url( $id ) {
	return $id ? 'https://media.example/' . $id . '.jpg' : false; }
function wp_upload_dir() {
	return array( 'basedir' => sys_get_temp_dir() . '/ec-link-pages-blog-' . get_current_blog_id() ); }
function wp_get_attachment_metadata( $id ) {
	return get_post_meta( $id, '_wp_attachment_metadata', true ); }
function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- this is the wp_mkdir_p() stub.
}
function wp_delete_file( $path ) {
	return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- this is the wp_delete_file() stub.
}
function clean_post_cache( $post_id ) {
	$GLOBALS['ec_test']['cleaned_post_caches'][] = (int) $post_id; }
function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['ec_test']['deleted_caches'][] = array( $key, $group );
	return true; }
function wp_generate_uuid4() {
	static $sequence = 0;
	return sprintf( '00000000-0000-4000-8000-%012d', ++$sequence ); }
function wp_slash( $value ) {
	return $value; }
function maybe_unserialize( $value ) {
	$result = is_string( $value ) ? @unserialize( $value ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- mirrors core maybe_unserialize() on harness-owned data.
	return false !== $result || 'b:0;' === $value ? $result : $value; }
function wp_register_ability( $name, $args ) {
	$GLOBALS['ec_test']['abilities'][ $name ] = $args; }
function wp_get_ability( $name ) {
	return $GLOBALS['ec_test']['abilities'][ $name ] ?? null; }
function current_user_can( $capability ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	return true; }
function current_time() {
	return (int) ( $GLOBALS['ec_test']['now'] ?? time() ); }
function current_datetime() {
	return new DateTimeImmutable( '@' . (int) ( $GLOBALS['ec_test']['now'] ?? time() ) ); }
function get_option( $key, $default_value = false ) {
	return $GLOBALS['ec_test']['options'][ $key ] ?? $default_value; }
function update_option( $key, $value ) {
	$GLOBALS['ec_test']['options'][ $key ] = $value;
	return true; }
function add_rewrite_tag() {}
function add_rewrite_rule( $regex, $query, $position ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- params are captured via compact().
	$GLOBALS['ec_test']['rewrite_rules'][] = compact( 'regex', 'query', 'position' ); }
function status_header( $status ) {
	$GLOBALS['ec_test']['status'] = $status; }
function wp_redirect( $url, $status ) {
	$GLOBALS['ec_test']['redirect'] = array( $url, $status, false );
	return empty( $GLOBALS['ec_test']['fail_redirect'] ); }
function wp_safe_redirect( $url, $status ) {
	$GLOBALS['ec_test']['redirect'] = array( $url, $status, true );
	return empty( $GLOBALS['ec_test']['fail_redirect'] ); }
function is_singular( $type ) {
	return ! empty( $GLOBALS['ec_test']['singular'] ) && $type === $GLOBALS['ec_test']['singular']; }
function get_queried_object() {
	return $GLOBALS['wp_query']->queried_object ?? null; }
function setup_postdata( $post ) {
	$GLOBALS['ec_test']['postdata_setup'][] = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
	return true; }
function get_the_ID() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- stubs a WordPress core function name.
	$post = $GLOBALS['post'] ?? null;
	return is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : false; }
function get_the_modified_date( $format, $post_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	return '2026-08-23T00:00:00+00:00'; }
function __return_true() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- stubs a WordPress core function name.
	return true; }
function get_bloginfo( $key = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	return 'UTF-8'; }
function get_site_icon_url( $size = 512 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	return ''; }
function wp_print_styles() {
	$GLOBALS['ec_test']['printed_styles'] = true; }
function wp_print_footer_scripts() {}
function language_attributes() {}
function get_template_directory_uri() {
	return 'https://example.test/themes/extrachill'; }
function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- params are captured via compact().
	$GLOBALS['ec_test']['registered_styles'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' ); }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- params are captured via compact().
	$GLOBALS['ec_test']['enqueued_styles'][] = $handle;
	if ( '' !== $src ) {
		$GLOBALS['ec_test']['registered_styles'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
	}
}
function wp_style_is( $handle, $status = 'enqueued' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	return in_array( $handle, $GLOBALS['ec_test']['enqueued_styles'] ?? array(), true ); }
function wp_add_inline_style( $handle, $data ) {
	$GLOBALS['ec_test']['inline_styles'][ $handle ][] = $data;
	return true; }
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	$GLOBALS['ec_test']['enqueued_scripts'][] = $handle; }
function wp_script_is( $handle, $status = 'registered' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	return in_array( $handle, $GLOBALS['ec_test']['registered_script_handles'] ?? array(), true ); }

// Seed a default blog before the plugin file's top-level self-validation runs
// so blog reads aren't against an unset array key.
$GLOBALS['ec_test']['current_blog_id'] = $GLOBALS['ec_test']['current_blog_id'] ?? 4;
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://storage.test/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url( $blog_id = null, $path = '' ) {
		return home_url( $path );
	}
}
// Fixture host answers, as the Extra Chill host integration does in production.
add_filter(
	'ec_link_page_public_host',
	static function () {
		return 'extrachill.link';
	}
);
add_filter(
	'ec_link_page_root_slug',
	static function () {
		return 'extra-chill';
	}
);
require_once dirname( __DIR__ ) . '/extrachill-link-pages.php';
ec_test_reset();
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $key, $default_value = '' ) {
		return $GLOBALS['ec_test']['query_vars'][ $key ] ?? $default_value;
	}
}
if ( ! function_exists( 'get_blog_option' ) ) {
	function get_blog_option( $blog_id, $option, $default_value = false ) {
		return $GLOBALS['ec_test']['blog_options'][ (int) $blog_id ][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( '__return_false' ) ) {
	function __return_false() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- stubs a WordPress core function name.
		return false;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://storage.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}
