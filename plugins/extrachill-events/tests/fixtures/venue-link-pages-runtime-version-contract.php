<?php
// phpcs:disable -- Purpose-built WordPress runtime stubs must share one isolated fixture.
/**
 * Self-contained runtime contract fixture for
 * LinkPagesProvider::validate_runtime().
 *
 * The provider trusts the Link Pages runtime's own validation instead of
 * keeping a copy of its function signatures. These stubs report the outcome
 * each mode asks for so the provider's error mapping can be proven.
 *
 * Usage: php venue-link-pages-runtime-version-contract.php <mode>
 *   valid      - active, valid, ready runtime with a storage blog.
 *   inactive   - Link Pages is not configured as an active plugin.
 *   missing    - active, but the runtime never loaded its validator.
 *   invalid    - ec_validate_link_pages_runtime() returns a WP_Error.
 *   not-ready  - ec_link_pages_runtime_ready() returns false.
 *   no-storage - the canonical storage blog does not resolve.
 *
 * @package ExtraChillEvents\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

$mode = $argv[1] ?? 'valid';

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
	global $mode;
	return 'active_plugins' === $name && 'inactive' !== $mode ? array( 'extrachill-link-pages/extrachill-link-pages.php' ) : $default; }
function get_site_option( $name, $default = false ) {
	unset( $name );
	return $default; }
function add_action() {}
function do_action() {}
function update_option() {}
function delete_option() {}

if ( 'missing' !== $mode ) {
	function ec_validate_link_pages_runtime( $check_readiness = true ) {
		global $mode;
		unset( $check_readiness );
		return 'invalid' === $mode ? new WP_Error( 'ec_link_pages_runtime_incompatible', 'The Link Pages runtime uses an incompatible function contract.' ) : true; }
	function ec_link_pages_runtime_ready() {
		global $mode;
		return 'not-ready' !== $mode; }
	function ec_get_link_page_storage_blog_id() {
		global $mode;
		return 'no-storage' === $mode ? 0 : 13; }

	$link_pages_engine = getenv( 'LINK_PAGES_WORKTREE' ) ? getenv( 'LINK_PAGES_WORKTREE' ) : dirname( __DIR__, 3 ) . '/extrachill-link-pages';
	require_once $link_pages_engine . '/inc/class-owned-link-pages.php';
}

require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Providers/LinkPagesProvider.php';

$result = ExtraChillEvents\Providers\LinkPagesProvider::validate_runtime();

echo json_encode(
	array(
		'mode'  => $mode,
		'valid' => true === $result,
		'error' => is_wp_error( $result ) ? $result->get_error_code() : '',
		'cause' => is_wp_error( $result ) ? ( $result->get_error_data()['cause'] ?? '' ) : '',
	)
);
