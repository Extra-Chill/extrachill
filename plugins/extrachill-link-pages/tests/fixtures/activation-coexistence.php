<?php
/**
 * Fixture: activating alongside a preloaded fallback runtime.
 *
 * Runs in its own PHP process and prints a JSON activation report.
 *
 * @package ExtraChillLinkPages
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EC_LINK_PAGE_OWNER_META_KEY', '_ec_link_page_owner_reference' );
$GLOBALS['state'] = array(
	'post_types'    => array( 'ec_link_page' => array() ),
	'registrations' => 1,
	'flushes'       => 0,
);
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
function register_activation_hook( $file, $callback ) {
	$GLOBALS['activate'] = $callback; }
function register_deactivation_hook() {}
function post_type_exists( $type ) {
	return isset( $GLOBALS['state']['post_types'][ $type ] ); }
function register_post_type( $type, $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the WordPress core signature.
	++$GLOBALS['state']['registrations']; }
function flush_rewrite_rules() {
	++$GLOBALS['state']['flushes']; }
function is_multisite() {
	return false; }
function esc_html( $value ) {
	return $value; }
function wp_die( $message ) {
	throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test exception, never rendered.
}
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
function __( $text ) {
	return $text; }
function _x( $text ) {
	return $text; }
function ec_get_link_page_public_urls() {
	return array( 'fallback' ); }
function ec_generate_css_variables_style_block() {
	return 'fallback'; }
function extrachill_add_rewrite_rules() {
	return 'fallback'; }
function extrachill_artist_link_page_sitemap_urls( $urls ) {
	return $urls; }

require_once dirname( __DIR__, 2 ) . '/inc/owner-reference.php';
require_once dirname( __DIR__, 2 ) . '/inc/operations.php';
require_once dirname( __DIR__, 2 ) . '/extrachill-link-pages.php';
ec_register_link_page_public_compatibility_aliases();
call_user_func( $GLOBALS['activate'], false );
echo json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone process; WordPress is not loaded.
	array(
		'ready'         => ec_link_pages_runtime_ready(),
		'registrations' => $GLOBALS['state']['registrations'],
		'flushes'       => $GLOBALS['state']['flushes'],
		'fallback'      => ec_get_link_page_public_urls(),
	)
);
