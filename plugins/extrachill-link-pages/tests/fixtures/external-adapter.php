<?php

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['state'] = array();
class WP_Error { public function __construct( $code, $message ) {} }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function add_action() {}
function do_action() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function post_type_exists() { return false; }
function register_post_type() {}
function flush_rewrite_rules() {}
function __( $text ) { return $text; }
function _x( $text ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

$root = dirname( __DIR__, 2 );
require_once $root . '/extrachill-link-pages.php';
$external = getenv( 'ARTIST_PLATFORM_WORKTREE' ) ?: '/var/lib/datamachine/workspace/extrachill-artist-platform@refactor-152-link-pages-runtime-handoff';
require_once $external . '/inc/link-pages/artist-owner-compatibility.php';
require_once $external . '/inc/link-pages/artist-owner-operations.php';
ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
ec_register_link_page_operation_provider( 'artist-platform', 'ec_artist_link_page_operation_provider' );
echo json_encode( array(
	'ready' => ec_link_pages_runtime_ready(),
	'owner_providers' => array_column( ec_link_page_owner_compatibility_registry()->snapshot(), 'name' ),
	'operation_providers' => array_column( ec_link_page_operation_provider_registry()->snapshot(), 'name' ),
) );
