<?php

$mode       = $argv[1] ?? '';
$artist     = dirname( __DIR__, 2 );
$standalone = getenv( 'LINK_PAGES_WORKTREE' ) ?: '/var/lib/datamachine/workspace/extrachill-link-pages@feat-3-public-runtime';

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR', $artist . '/' );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

$GLOBALS['smoke'] = array(
	'active'                => 'external' === $mode,
	'actions'               => array(),
	'activation_callbacks'  => array(),
	'registered_post_types' => array(),
	'registration_counts'   => array(),
	'flushes'               => 0,
	'current_blog_id'       => 4,
	'filters'               => array(),
	'site_options'          => array(),
);

function get_option( $name, $default = false ) {
	if ( 'active_plugins' === $name && $GLOBALS['smoke']['active'] ) {
		return array( 'extrachill-link-pages/extrachill-link-pages.php' );
	}
	return $default;
}

function get_site_option( $name, $default = false ) {
	return $GLOBALS['smoke']['site_options'][ $name ] ?? $default;
}
function update_site_option( $name, $value ) { $GLOBALS['smoke']['site_options'][ $name ] = $value; return true; }

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['smoke']['actions'][ $hook ][ $priority ][] = $callback;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['smoke']['filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['smoke']['filters'][ $hook ] ?? array() as $filter ) {
		$value = call_user_func_array( $filter[0], array_slice( array_merge( array( $value ), $args ), 0, $filter[2] ) );
	}
	return $value;
}
function has_filter( $hook ) { return ! empty( $GLOBALS['smoke']['filters'][ $hook ] ); }
function get_current_blog_id() { return $GLOBALS['smoke']['current_blog_id']; }
function get_main_site_id() { return 4; }
function get_site( $blog_id ) { return 4 === (int) $blog_id ? (object) array( 'blog_id' => 4 ) : null; }
function switch_to_blog( $blog_id ) { $GLOBALS['smoke']['current_blog_id'] = (int) $blog_id; return true; }
function restore_current_blog() { $GLOBALS['smoke']['current_blog_id'] = 4; return true; }
function is_multisite() { return true; }
function ec_get_blog_id( $type ) { return 'artist' === $type ? 4 : 0; }

add_filter(
	'ec_link_page_storage_blog_id',
	static function () {
		return 4;
	}
);

function do_action( $hook ) {
	$priorities = $GLOBALS['smoke']['actions'][ $hook ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			call_user_func( $callback );
		}
	}
}

function register_activation_hook( $file, $callback ) {
	$GLOBALS['smoke']['activation_callbacks'][ plugin_basename( $file ) ] = $callback;
}

function register_deactivation_hook() {
}

function post_type_exists( $post_type ) {
	return isset( $GLOBALS['smoke']['registered_post_types'][ $post_type ] );
}

function register_post_type( $post_type, $args ) {
	$GLOBALS['smoke']['registered_post_types'][ $post_type ] = $args;
	$GLOBALS['smoke']['registration_counts'][ $post_type ]   = ( $GLOBALS['smoke']['registration_counts'][ $post_type ] ?? 0 ) + 1;
	return (object) $args;
}

function flush_rewrite_rules() {
	++$GLOBALS['smoke']['flushes'];
}

function __( $text ) {
	return $text;
}

function _x( $text ) {
	return $text;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

require_once $artist . '/inc/link-pages/runtime-handoff.php';
require_once $artist . '/inc/core/artist-platform-post-types.php';
add_action( 'plugins_loaded', 'extrachill_artist_platform_boot_link_pages_runtime', 20 );

if ( in_array( $mode, array( 'activation', 'activation-site' ), true ) ) {
	$before_boot = function_exists( 'ec_get_link_page_owner' );
	do_action( 'plugins_loaded' );
	$after_boot = function_exists( 'ec_get_link_page_owner' );
	$storage_provider_before_standalone = 4 === (int) apply_filters( 'ec_link_page_storage_blog_id', 0 );
	require_once $standalone . '/extrachill-link-pages.php';
	$activation_callbacks = array_values( $GLOBALS['smoke']['activation_callbacks'] );
	call_user_func( $activation_callbacks[0], 'activation' === $mode );

	echo json_encode(
		array(
			'before_boot'          => $before_boot,
			'after_boot'           => $after_boot,
			'ready'                => ec_link_pages_runtime_ready(),
			'contract_matches'      => true === extrachill_artist_platform_validate_link_pages_runtime(),
			'link_registrations'   => $GLOBALS['smoke']['registration_counts']['artist_link_page'] ?? 0,
			'owner_providers'      => count( ec_link_page_owner_compatibility_registry()->snapshot() ),
			'operation_providers'  => count( ec_link_page_operation_provider_registry()->snapshot() ),
			'flushes'              => $GLOBALS['smoke']['flushes'],
			'storage_provider_before_standalone' => $storage_provider_before_standalone,
			'storage_blog_id'      => ec_get_link_page_storage_blog_id(),
		)
	);
	exit;
}

if ( 'external' === $mode ) {
	$before_standalone = function_exists( 'ec_get_link_page_owner' );
	require_once $standalone . '/extrachill-link-pages.php';
	$after_standalone = function_exists( 'ec_get_link_page_owner' );
	do_action( 'plugins_loaded' );
	$second_boot = extrachill_artist_platform_boot_link_pages_runtime();
	ec_register_link_page_post_type_if_ready();

	echo json_encode(
		array(
			'before_standalone'    => $before_standalone,
			'after_standalone'     => $after_standalone,
			'ready'                => ec_link_pages_runtime_ready(),
			'contract_matches'      => true === extrachill_artist_platform_validate_link_pages_runtime(),
			'second_boot'          => true === $second_boot,
			'link_registrations'   => $GLOBALS['smoke']['registration_counts']['artist_link_page'] ?? 0,
			'owner_providers'      => count( ec_link_page_owner_compatibility_registry()->snapshot() ),
			'operation_providers'  => count( ec_link_page_operation_provider_registry()->snapshot() ),
			'projection_providers' => count( ec_link_page_public_projection_registry()->snapshot() ),
		)
	);
	exit;
}

exit( 2 );
