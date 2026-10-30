<?php
// phpcs:disable -- Purpose-built WordPress runtime stubs must share one isolated fixture.
/**
 * Self-contained runtime-version contract fixture for
 * VenueLinkPagesProvider::validate_runtime().
 *
 * Stubs the complete v3 function contract (unaffected by the v3→v4 bump) plus
 * the one v4-only addition, ec_link_page_post_type(), so the three modes below
 * exercise the exact regression from #899: a Link Pages runtime declaring API
 * version '4' (extrachill-link-pages.php:861 in production) must no longer be
 * rejected by a provider pinned to '3'.
 *
 * Usage: php venue-link-pages-runtime-version-contract.php <mode>
 *   v3               - legacy runtime, version '3', no post-type resolver.
 *   v4-complete       - current production shape: version '4' plus the
 *                        ec_link_page_post_type() resolver added in
 *                        extrachill-link-pages PR #34/#35.
 *   v4-missing-resolver - version '4' but the post-type resolver is absent
 *                        (a genuinely incomplete/incompatible v4 runtime).
 *
 * @package ExtraChillEvents\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) {
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
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function get_option( $name, $default = false ) {
	return 'active_plugins' === $name ? array( 'extrachill-link-pages/extrachill-link-pages.php' ) : $default; }
function get_site_option( $name, $default = false ) {
	unset( $name );
	return $default; }
function add_action() {}
function do_action() {}
function update_option() {}
function delete_option() {}

$mode = $argv[1] ?? 'v3';
define( 'EC_LINK_PAGES_RUNTIME_API_VERSION', 'v3' === $mode ? '3' : '4' );

// The complete v3 signature contract that VenueLinkPagesProvider::validate_runtime()
// reflects against. None of these arities changed across the v3->v4 bump
// (verified against extrachill-link-pages PR #34/#35, #52, #53).
function ec_validate_link_pages_runtime( $check_readiness = true ) {
	unset( $check_readiness );
	return true; }
function ec_link_pages_runtime_ready() {
	return true; }
function ec_get_link_page_storage_blog_id() {
	return 13; }
function ec_can_register_link_page_owner_compatibility_provider( $a, $b, $c = 10 ) {
	unset( $a, $b, $c );
	return true; }
function ec_register_link_page_owner_compatibility_provider( $a, $b, $c = 10 ) {
	unset( $a, $b, $c );
	return true; }
function ec_can_register_link_page_operation_provider( $a, $b, $c = 10 ) {
	unset( $a, $b, $c );
	return true; }
function ec_register_link_page_operation_provider( $a, $b, $c = 10 ) {
	unset( $a, $b, $c );
	return true; }
function ec_can_register_link_page_public_projection_provider( $a, $b, $c = 10 ) {
	unset( $a, $b, $c );
	return true; }
function ec_register_link_page_public_projection_provider( $a, $b, $c = 10 ) {
	unset( $a, $b, $c );
	return true; }
function ec_provision_owned_link_page( $a, $b, $c, $d = false, $e = null ) {
	unset( $a, $b, $c, $d, $e ); }
function ec_provision_owned_link_page_composed( $a, $b, $c, $d, $e = false, $f = null ) {
	unset( $a, $b, $c, $d, $e, $f ); }
function ec_invoke_link_page_provision_precondition( $a, $b ) {
	unset( $a, $b ); }
function ec_save_link_page_public_projection_snapshot( $a, $b, $c ) {
	unset( $a, $b, $c ); }
function ec_read_link_page_public_projection_snapshot( $a, $b = null ) {
	unset( $a, $b ); }
function ec_with_link_page_storage_blog( $a ) {
	unset( $a ); }
function ec_with_link_page_lock_scope( $a, $b, $c = null ) {
	unset( $a, $b, $c ); }
function ec_get_link_page_id_for_owner( $a, $b = null ) {
	unset( $a, $b ); }
function ec_normalize_link_page_owner_reference( $a ) {
	unset( $a ); }
function ec_parse_link_page_owner_reference( $a ) {
	unset( $a ); }
function ec_get_link_page_owner( $a ) {
	unset( $a ); }
function ec_read_link_page_persistence( $a, $b = null ) {
	unset( $a, $b ); }
function ec_save_link_page_persistence_locked( $a, $b ) {
	unset( $a, $b ); }
function ec_save_link_page_persistence_composed( $a, $b, $c ) {
	unset( $a, $b, $c ); }
function ec_snapshot_link_page_meta( $a, $b ) {
	unset( $a, $b ); }
function ec_write_link_page_meta( $a, $b, $c, $d = null ) {
	unset( $a, $b, $c, $d ); }
function ec_restore_link_page_meta_snapshots( $a, $b ) {
	unset( $a, $b ); }
function ec_get_link_page_public_url( $a ) {
	unset( $a ); }
function ec_compensate_created_link_page( $a ) {
	unset( $a ); }
function ec_purge_link_page_after_mutation( $a ) {
	unset( $a ); }
function ec_get_stored_link_page_owner_references( $a ) {
	unset( $a ); }
function ec_read_link_page( $a ) {
	unset( $a ); }
function ec_save_link_page( $a, $b ) {
	unset( $a, $b ); }
function ec_link_page_id_meta_keys() {
	return array(); }

// The one v4-only addition (extrachill-link-pages PR #34/#35): the post-type
// resolver that replaced the pinned 'artist_link_page' literal.
if ( 'v4-missing-resolver' !== $mode ) {
	function ec_link_page_post_type( $blog_id = null ) {
		unset( $blog_id );
		return 'artist_link_page'; }
}

require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Providers/VenueLinkPagesProvider.php';

$result = ExtraChillEvents\Providers\VenueLinkPagesProvider::validate_runtime();

echo json_encode(
	array(
		'mode'  => $mode,
		'valid' => true === $result,
		'error' => is_wp_error( $result ) ? $result->get_error_code() : '',
	)
);
