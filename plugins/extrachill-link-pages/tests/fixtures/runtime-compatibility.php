<?php
/**
 * Fixture: validation against incompatible preloaded runtimes.
 *
 * Runs in its own PHP process; RUNTIME_MODE selects the scenario and the
 * validation/activation results are printed as JSON.
 *
 * @package ExtraChillLinkPages
 */

$runtime_mode = getenv( 'RUNTIME_MODE' );
if ( ! $runtime_mode ) {
	$runtime_mode = 'partial';
}
define( 'ABSPATH', __DIR__ . '/' );
if ( 'wrong_constants' === $runtime_mode ) {
	define( 'EC_LINK_PAGE_OWNER_META_KEY', '_wrong_key' );
}
if ( 'wrong_api' === $runtime_mode ) {
	define( 'EC_LINK_PAGES_RUNTIME_API_VERSION', '1' );
}

require_once dirname( __DIR__ ) . '/support/class-wp-error.php';
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/'; }
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file ); }
function add_action() {}
function do_action() {}
function did_action() {
	return 1; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function post_type_exists( $post_type ) {
	return isset( $GLOBALS['post_types'][ $post_type ] ); }
function register_post_type( $post_type, $args ) {
	$GLOBALS['post_types'][ $post_type ] = $args; }
function flush_rewrite_rules() {}
function is_multisite() {
	return false; }
function __( $text ) {
	return $text; }
function _x( $text ) {
	return $text; }
function esc_html( $value ) {
	return $value; }
function get_site_option( $key, $default_value = false ) {
	return $default_value; }
function update_site_option() {
	return true; }
function get_current_blog_id() {
	return 1; }
function get_main_site_id() {
	return 1; }
function get_site( $id ) {
	return (object) array( 'blog_id' => $id ); }
function apply_filters( $hook, $value ) {
	return $value; }
function switch_to_blog() {
	return true; }
function restore_current_blog() {
	return true; }

if ( 'partial' === $runtime_mode ) {
	function ec_link_page_owner_compatibility_registry() {}
}
if ( 'partial_storage' === $runtime_mode ) {
	function ec_link_page_defaults() {
		return array(); }
}
if ( 'partial_lifecycle' === $runtime_mode ) {
	function ec_get_link_page_storage_blog_id() {
		return 1; }
}

if ( 'incompatible_signature' === $runtime_mode ) {
	function ec_link_page_owner_compatibility_registry( $unexpected ) {}
	function ec_register_link_page_owner_compatibility_provider( $name, $callback, $priority = 10 ) {}
	function ec_parse_link_page_owner_reference( $reference ) {}
	function ec_format_link_page_owner_reference( $owner ) {}
	function ec_normalize_link_page_owner_reference( $owner ) {}
	function ec_get_stored_link_page_owner_references( $id ) {}
	function ec_validate_link_page_owner_compatibility_claim( $claim, $operation, $context ) {}
	function ec_restore_link_page_owner_provider_context( $blog_id, $stack, $switched ) {}
	function ec_invoke_link_page_owner_compatibility_provider( $provider, $operation, $context ) {}
	function ec_collect_raw_link_page_owner_compatibility_claims( $operation, $context ) {}
	function ec_reconcile_link_page_owner_candidate( $id, $reference ) {}
	function ec_collect_link_page_owner_compatibility_claims( $operation, $context ) {}
	function ec_get_link_page_owner( $id ) {}
	function ec_get_link_page_id_for_owner( $owner, $allowed = array() ) {}
	function ec_validate_link_page_owner_candidate_ids( $ids ) {}
	function ec_assign_link_page_owner( $id, $owner, $replace = 0 ) {}
	function ec_compensate_link_page_owner_assignment( $id, $reference, $meta_id, $error ) {}
	function ec_halt_link_page_owner_backfill( $result, $id, $code, $offset ) {}
	function ec_backfill_link_page_owner_references( $limit = 100, $offset = 0 ) {}
}

if ( 'readiness_exception' === $runtime_mode ) {
	function ec_link_pages_runtime_ready() {
		throw new RuntimeException( 'failed' ); }
}

$root = dirname( __DIR__, 2 );
if ( 'operations_only' === $runtime_mode ) {
	require_once $root . '/inc/operations.php';
}
require_once $root . '/extrachill-link-pages.php';
$validation = ec_validate_link_pages_runtime();
$activation = function_exists( 'ec_prepare_link_pages_activation' ) ? ec_prepare_link_pages_activation( false ) : $validation;
echo json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone process; WordPress is not loaded.
	array(
		'validation'       => is_wp_error( $validation ) ? $validation->get_error_code() : true,
		'activation'       => is_wp_error( $activation ) ? $activation->get_error_code() : true,
		'owner_loaded'     => function_exists( 'ec_get_link_page_owner' ),
		'operation_loaded' => function_exists( 'ec_save_link_page' ),
	)
);
