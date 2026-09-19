<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
	public function get_error_data() {
		return $this->data; }
}

class EcTestActivationException extends RuntimeException {}

/** Raised when production code reaches for a multisite-only API on single site. */
class EcTestMultisiteApiUnavailable extends RuntimeException {}

class EcTestWpdb {
	public $postmeta           = 'wp_postmeta';
	public $posts              = 'wp_posts';
	public $comments           = 'wp_comments';
	public $term_relationships = 'wp_term_relationships';
	public $last_error         = '';
	public $insert_id          = 0;
	private $locks             = array();
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function get_blog_prefix( $blog_id ) {
		return 'wp_' . (int) $blog_id . '_'; }
	public function get_results( $query, $output = null ) {
		$blog_id = get_current_blog_id();
		if ( false !== strpos( $query, 'SELECT * FROM wp_posts WHERE post_type' ) ) {
			$posts = array_values(
				array_filter(
					$GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'],
					static function ( $post ) {
						return EC_LINK_PAGE_POST_TYPE === $post->post_type;
					}
				)
			);
			usort(
				$posts,
				static function ( $left, $right ) {
					return $left->ID <=> $right->ID;
				}
			);
			return $posts;
		}
		if ( preg_match( '/SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN \(([^)]+)\)/', $query, $matches ) ) {
			$ids  = array_map( 'intval', explode( ',', $matches[1] ) );
			$rows = array();
			foreach ( $GLOBALS['ec_test']['meta_rows'] ?? array() as $meta_id => $row ) {
				if ( $blog_id === (int) $row['blog_id'] && in_array( (int) $row['post_id'], $ids, true ) ) {
					$rows[ $meta_id ] = array_intersect_key( $row, array_flip( array( 'post_id', 'meta_key', 'meta_value' ) ) );
				}
			}
			ksort( $rows, SORT_NUMERIC );
			return array_values( $rows );
		}
		return array();
	}
	public function get_col( $query ) {
		$posts = $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'];
		$ids   = array();
		if ( preg_match( "/post_type = 'attachment' AND post_parent = (\d+)/", $query, $matches ) ) {
			foreach ( $posts as $id => $post ) {
				if ( 'attachment' === $post->post_type && (int) $post->post_parent === (int) $matches[1] ) {
					$ids[] = (int) $id; }
			}
		} elseif ( preg_match( "/post_name = '([^']+)'/", $query, $matches ) ) {
			foreach ( $posts as $id => $post ) {
				if ( $matches[1] === $post->post_name ) {
					$ids[] = (int) $id; }
			}
		}
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}
	public function get_row( $query ) {
		if ( preg_match( '/FROM wp_postmeta WHERE meta_id = (\d+)/', $query, $matches ) ) {
			$row = $GLOBALS['ec_test']['meta_rows'][ (int) $matches[1] ] ?? null;
			return $row && get_current_blog_id() === (int) $row['blog_id'] ? array_intersect_key( $row, array_flip( array( 'post_id', 'meta_key', 'meta_value' ) ) ) : null;
		}
		if ( preg_match( '/FROM wp_(\d+)_posts WHERE ID = (\d+)/', $query, $matches ) ) {
			$post = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['posts'][ (int) $matches[2] ] ?? null;
			return $post ? (object) array(
				'ID'          => $post->ID,
				'post_type'   => $post->post_type,
				'post_status' => $post->post_status ?? 'publish',
			) : null;
		}
		if ( preg_match( "/FROM wp_(\d+)_term_taxonomy WHERE term_id = (\d+) AND taxonomy = '([^']+)'/", $query, $matches ) ) {
			$term = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['terms'][ (int) $matches[2] ] ?? null;
			return $term && $term->taxonomy === $matches[3] ? (object) array(
				'term_id'  => $term->term_id,
				'taxonomy' => $term->taxonomy,
			) : null;
		}
		if ( preg_match( '/FROM wp_(\d+)_term_taxonomy WHERE term_id = (\d+)/', $query, $matches ) ) {
			$term = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['terms'][ (int) $matches[2] ] ?? null;
			return $term ? (object) array(
				'term_id'  => $term->term_id,
				'taxonomy' => $term->taxonomy,
			) : null;
		}
		return null;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			++$GLOBALS['ec_test']['lock_acquires'];
			preg_match( "/GET_LOCK\('([^']+)'/", $query, $matches );
			$lock_name = $matches[1] ?? $query;
			if ( ! empty( $GLOBALS['ec_test']['fail_advisory_lock'] ) || isset( $this->locks[ $lock_name ] ) ) {
				return '0'; }
			$this->locks[ $lock_name ]                = true;
			$GLOBALS['ec_test']['advisory_lock_held'] = true;
			return '1';
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			++$GLOBALS['ec_test']['lock_releases'];
			preg_match( "/RELEASE_LOCK\('([^']+)'/", $query, $matches );
			unset( $this->locks[ $matches[1] ?? $query ] );
			$GLOBALS['ec_test']['advisory_lock_held'] = ! empty( $this->locks );
			return '1';
		}
		if ( false !== strpos( $query, 'COUNT(*)' ) ) {
			return '0'; }
		return '1';
	}
	public function insert( $table, $data, $formats = null ) {
		if ( $this->postmeta !== $table || ! empty( $GLOBALS['ec_test']['fail_raw_meta_insert'] ) ) {
			return false; }
		$meta_id                                     = ++$GLOBALS['ec_test']['next_meta_id'];
		$this->insert_id                             = $meta_id;
		$GLOBALS['ec_test']['meta_rows'][ $meta_id ] = array_merge( array( 'blog_id' => get_current_blog_id() ), $data );
		$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $data['post_id'] ][ $data['meta_key'] ][] = maybe_unserialize( $data['meta_value'] );
		if ( isset( $GLOBALS['ec_test']['after_raw_meta_insert'] ) ) {
			$callback = $GLOBALS['ec_test']['after_raw_meta_insert'];
			unset( $GLOBALS['ec_test']['after_raw_meta_insert'] );
			$callback( $meta_id );
		}
		return 1;
	}
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( $this->posts !== $table || ! isset( $where['ID'] ) || ! get_post( $where['ID'] ) ) {
			return false; }
		foreach ( $data as $key => $value ) {
			$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $where['ID'] ]->{$key} = $value; }
		return 1;
	}
}

$GLOBALS['wpdb'] = new EcTestWpdb();

function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function absint( $value ) {
	return abs( (int) $value ); }
function wp_json_encode( $value ) {
	return json_encode( $value ); }
function __( $text ) {
	return $text; }
function _x( $text ) {
	return $text; }
function esc_attr_e( $text ) {
	echo $text; }
function esc_html_e( $text ) {
	echo $text; }
function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) {
	return esc_attr( $value ); }
function esc_url_raw( $value, $protocols = null ) {
	$url = filter_var( (string) $value, FILTER_SANITIZE_URL );
	if ( $protocols && $url && ! in_array( strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ), $protocols, true ) ) {
		return ''; }
	return $url;
}
function esc_js( $value ) {
	return addslashes( (string) $value ); }
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); }
function wp_unslash( $value ) {
	return $value; }
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) ); }
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
class WP_Block_Type_Registry {
	private static $instance;
	private $registered = array();
	public static function get_instance() {
		return self::$instance ?? ( self::$instance = new self() ); }
	public function is_registered( $name ) {
		return isset( $this->registered[ $name ] ); }
	public function register( $name, $source ) {
		$this->registered[ $name ] = $source; }
	public function source( $name ) {
		return $this->registered[ $name ] ?? ''; }
	public function reset() {
		$this->registered = array(); }
}
function register_block_type( $path ) {
	$metadata = json_decode( file_get_contents( rtrim( $path, '/' ) . '/block.json' ), true );
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
	throw new EcTestActivationException( $message ); }
function is_multisite() {
	return ! empty( $GLOBALS['ec_test']['multisite'] ); }
function get_site_option( $key, $default = false ) {
	return $GLOBALS['ec_test']['site_options'][ $key ] ?? $default; }
function update_site_option( $key, $value ) {
	$GLOBALS['ec_test']['site_options'][ $key ] = $value;
	return true; }
function get_network_option( $network_id, $key, $default = false ) {
	return $GLOBALS['ec_test']['site_options'][ $key ] ?? $default; }
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
	$GLOBALS['ec_test']['site_rule_snapshots'][ $blog_id ][] = post_type_exists( EC_LINK_PAGE_POST_TYPE );
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
		'current_blog_id'       => 4,
		'blog_stack'            => array(),
		'flushes'               => 0,
		'next_meta_id'          => 0,
		'next_post_id'          => 100,
		'network_option_write_calls' => 0,
		'meta_write_calls'      => 0,
		'lock_acquires'         => 0,
		'lock_releases'         => 0,
		'registered_post_types' => array(),
		'multisite'             => true,
		'actions'               => $base_actions,
		'filters'               => $base_filters,
		'did_actions'           => array( 'wp_loaded' => 1 ),
		'blogs'                 => array(
			4 => array(
				'posts'     => array(),
				'post_meta' => array(),
				'terms'     => array(),
			),
			7 => array(
				'posts'     => array(),
				'post_meta' => array(),
				'terms'     => array(),
			),
		),
	);
	foreach ( array( 4, 7 ) as $blog_id ) {
		$directory = sys_get_temp_dir() . '/ec-link-pages-blog-' . $blog_id;
		if ( is_dir( $directory ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); }
			rmdir( $directory );
		}
		mkdir( $directory, 0777, true );
	}
	foreach ( array( ec_link_page_owner_compatibility_registry(), ec_link_page_operation_provider_registry(), ec_link_page_public_projection_registry() ) as $registry ) {
		$reflection = new ReflectionObject( $registry );
		$property   = $reflection->getProperty( 'providers' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );
	}
	$migration_registry = ec_link_page_migration_participant_registry();
	$reflection         = new ReflectionObject( $migration_registry );
	$property           = $reflection->getProperty( 'participants' );
	$property->setAccessible( true );
	$property->setValue( $migration_registry, array() );
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
	$post = get_post( $post_id );
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
		'meta_key'   => $key,
		'meta_value' => $value,
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
function wp_insert_post( $data, $wp_error = false ) {
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
	if ( isset( $GLOBALS['ec_test']['after_insert_post'] ) ) {
		$callback = $GLOBALS['ec_test']['after_insert_post'];
		unset( $GLOBALS['ec_test']['after_insert_post'] );
		$callback( $id ); }
	return $id;
}
function wp_insert_attachment( $data, $file = false, $parent = 0, $wp_error = false ) {
	$data['post_type']   = 'attachment';
	$data['post_parent'] = $parent;
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
			if ( (int) $id === (int) $post_id || $value !== ( $post->post_name ?? '' ) ) {
				continue; }
			if ( 'attachment' === $post_type || 'attachment' === $post->post_type || ( $post_type === $post->post_type && (int) ( $post->post_parent ?? 0 ) === $post_parent ) ) {
				return true; }
		}
		return false;
	};
	while ( $conflicts( $candidate ) ) {
		$candidate = $slug . '-' . $suffix++; }
	return $candidate;
}
function wp_get_attachment_url( $id ) {
	return $id ? 'https://media.example/' . $id . '.jpg' : false; }
function wp_upload_dir() {
	return array( 'basedir' => sys_get_temp_dir() . '/ec-link-pages-blog-' . get_current_blog_id() ); }
function wp_get_attachment_metadata( $id ) {
	return get_post_meta( $id, '_wp_attachment_metadata', true ); }
function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_delete_file( $path ) {
	return unlink( $path ); }
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
	$result = is_string( $value ) ? @unserialize( $value ) : false;
	return false !== $result || 'b:0;' === $value ? $result : $value; }
function wp_register_ability( $name, $args ) {
	$GLOBALS['ec_test']['abilities'][ $name ] = $args; }
function wp_get_ability( $name ) {
	return $GLOBALS['ec_test']['abilities'][ $name ] ?? null; }
function current_user_can( $capability ) {
	return true; }
function current_time() {
	return (int) ( $GLOBALS['ec_test']['now'] ?? time() ); }
function current_datetime() {
	return new DateTimeImmutable( '@' . (int) ( $GLOBALS['ec_test']['now'] ?? time() ) ); }
function get_option( $key, $default = false ) {
	return $GLOBALS['ec_test']['options'][ $key ] ?? $default; }
function update_option( $key, $value ) {
	$GLOBALS['ec_test']['options'][ $key ] = $value;
	return true; }
function add_rewrite_tag() {}
function add_rewrite_rule( $regex, $query, $position ) {
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
function get_the_ID() {
	$post = $GLOBALS['post'] ?? null;
	return is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : false; }
function get_the_modified_date( $format, $post_id ) {
	return '2026-08-23T00:00:00+00:00'; }
function __return_true() {
	return true; }

if ( getenv( 'LINK_PAGES_USE_FALLBACK' ) ) {
	define( 'EC_LINK_PAGE_POST_TYPE', 'artist_link_page' );
	define( 'EC_LINK_PAGE_OWNER_META_KEY', '_ec_link_page_owner_reference' );
	$fallback = getenv( 'ARTIST_PLATFORM_WORKTREE' ) ?: '/var/lib/datamachine/workspace/extrachill-artist-platform';
	if ( ! is_dir( $fallback . '/inc/link-pages' ) ) {
		fwrite( STDERR, 'The bundled link-pages fallback requires the Extra Chill Artist Platform sibling checkout. Set ARTIST_PLATFORM_WORKTREE to an extrachill-artist-platform checkout containing inc/link-pages (expected default: ' . $fallback . ")\n" );
		exit( 1 );
	}
	require_once $fallback . '/inc/link-pages/owner-reference.php';
	require_once $fallback . '/inc/link-pages/operations.php';
}
require_once dirname( __DIR__ ) . '/extrachill-link-pages.php';
ec_test_reset();
