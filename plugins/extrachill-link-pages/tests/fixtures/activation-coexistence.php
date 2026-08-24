<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'EC_LINK_PAGE_POST_TYPE', 'artist_link_page' );
define( 'EC_LINK_PAGE_OWNER_META_KEY', '_ec_link_page_owner_reference' );
$GLOBALS['state'] = array( 'post_types' => array( 'artist_link_page' => array() ), 'registrations' => 1, 'flushes' => 0 );
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function add_action() {}
function do_action() {}
function did_action() { return 1; }
function register_activation_hook( $file, $callback ) { $GLOBALS['activate'] = $callback; }
function register_deactivation_hook() {}
function post_type_exists( $type ) { return isset( $GLOBALS['state']['post_types'][ $type ] ); }
function register_post_type( $type, $args ) { ++$GLOBALS['state']['registrations']; }
function flush_rewrite_rules() { ++$GLOBALS['state']['flushes']; }
function is_multisite() { return false; }
function esc_html( $value ) { return $value; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function get_site_option( $key, $default = false ) { return $default; }
function __( $text ) { return $text; }
function _x( $text ) { return $text; }

require_once dirname( __DIR__, 2 ) . '/inc/owner-reference.php';
require_once dirname( __DIR__, 2 ) . '/inc/operations.php';
require_once dirname( __DIR__, 2 ) . '/extrachill-link-pages.php';
call_user_func( $GLOBALS['activate'], false );
echo json_encode( array( 'ready' => ec_link_pages_runtime_ready(), 'registrations' => $GLOBALS['state']['registrations'], 'flushes' => $GLOBALS['state']['flushes'] ) );
