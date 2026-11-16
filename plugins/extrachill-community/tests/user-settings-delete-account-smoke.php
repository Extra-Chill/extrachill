<?php
/**
 * Standalone contract test for the user settings Delete account section.
 *
 * Covers the server-side gate in render.php (which state the React section
 * receives) and a source-level design-system check on the section markup in
 * view.tsx: every class must be theme vocabulary from
 * themes/extrachill/style.css, the action must go through the core abilities
 * client, and no bespoke REST route may be introduced.
 *
 * @package ExtraChillCommunity
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;

	public function __construct( $code = '' ) {
		$this->code = $code;
	}

	public function get_error_code() {
		return $this->code;
	}
}

function delete_account_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function is_user_logged_in() {
	return true;
}
function auth_redirect() {
	throw new RuntimeException( 'Logged-in render must not redirect.' );
}
function wp_enqueue_script( $handle ) {}
function ec_get_site_url( $site ) {
	return 'https://artist.example.com';
}
function get_current_user_id() {
	return 7;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}
function esc_url( $value ) {
	return esc_attr( $value );
}
function home_url( $path = '' ) {
	return 'https://community.example.com' . $path;
}

function render_user_settings(): string {
	ob_start();
	require dirname( __DIR__ ) . '/src/blocks/user-settings/render.php';
	return (string) ob_get_clean();
}

function deletion_state( string $html ): string {
	delete_account_assert( 1 === preg_match( '/data-account-deletion="([^"]*)"/', $html, $matches ), 'Container must carry data-account-deletion.' );
	return $matches[1];
}

// Users plugin absent: the section is not offered at all.
$html = render_user_settings();
delete_account_assert( 'unavailable' === deletion_state( $html ), 'Missing ability must hide the section.' );
delete_account_assert( false !== strpos( $html, 'data-home-url="https://community.example.com/"' ), 'Container must carry the home URL for the post-deletion redirect.' );

// The ability's own permission function decides the state.
if ( ! function_exists( 'extrachill_users_can_delete_own_account' ) ) {
	function extrachill_users_can_delete_own_account() {
		return $GLOBALS['_test_can_delete'];
	}
}

$GLOBALS['_test_can_delete'] = true;
delete_account_assert( 'allowed' === deletion_state( render_user_settings() ), 'Permitted members must see the form.' );

$GLOBALS['_test_can_delete'] = new WP_Error( 'account_deletion_refused' );
delete_account_assert( 'staff' === deletion_state( render_user_settings() ), 'Staff refusal must show the contact note.' );

$GLOBALS['_test_can_delete'] = new WP_Error( 'not_authenticated' );
delete_account_assert( 'unavailable' === deletion_state( render_user_settings() ), 'Other refusals must hide the section.' );

$GLOBALS['_test_can_delete'] = false;
delete_account_assert( 'unavailable' === deletion_state( render_user_settings() ), 'A falsy permission result must hide the section.' );

// Source-level checks on the section markup.
$render_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/blocks/user-settings/render.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read.
delete_account_assert( 0 === preg_match( '/current_user_can\(\s*[\'"](administrator|editor|author|subscriber)/', $render_source ), 'Gate must not check role names.' );

$view_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/blocks/user-settings/view.tsx' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read.
$start       = strpos( $view_source, 'export function DeleteAccountSection(' );
delete_account_assert( false !== $start, 'DeleteAccountSection must exist.' );
$end     = strpos( $view_source, "\ntype TabId", $start );
$section = substr( $view_source, $start, false === $end ? null : $end - $start );

delete_account_assert( false !== strpos( $section, "'extrachill/delete-my-account'" ), 'Section must execute the delete-my-account ability.' );
delete_account_assert( false !== strpos( $section, 'client.execute' ), 'Section must use the shared core abilities client.' );
delete_account_assert( false === strpos( $section, 'apiFetch' ), 'Section must not call REST directly.' );
delete_account_assert( false === strpos( $section, 'extrachill/v1' ), 'No bespoke extrachill/v1 route for account deletion.' );

$allowed = array( 'button-danger', 'button-medium', 'notice', 'notice-error', 'notice-success' );

preg_match_all( '/className="([^"]*)"/', $section, $class_lists );
delete_account_assert( ! empty( $class_lists[1] ), 'Section must use theme classes.' );
$used = array();
foreach ( $class_lists[1] as $list ) {
	foreach ( preg_split( '/\s+/', trim( $list ) ) as $class ) {
		delete_account_assert( in_array( $class, $allowed, true ), "Delete account section uses .{$class}, which is not on the design-system allow-list." );
		$used[] = $class;
	}
}
delete_account_assert( in_array( 'button-danger', $used, true ) && in_array( 'button-medium', $used, true ), 'Delete button must be .button-danger .button-medium.' );
delete_account_assert( 1 === preg_match( '/<Notice\s+type="error"/', $section ), 'Errors must render through the .notice .notice-error component.' );

// The shared Notice component renders `notice notice-{type}`.
delete_account_assert( false !== strpos( $view_source, 'className={ `notice notice-${ type }` }' ), 'Notice must render theme notice classes.' );

// Every allow-listed class must exist in the theme stylesheet when the monorepo theme is present.
$theme_css_path = dirname( __DIR__, 3 ) . '/themes/extrachill/style.css';
if ( is_readable( $theme_css_path ) ) {
	$theme_css = (string) file_get_contents( $theme_css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level stylesheet read.
	foreach ( $allowed as $class ) {
		delete_account_assert( 1 === preg_match( '/\.' . preg_quote( $class, '/' ) . '\b/', $theme_css ), "Theme stylesheet must define .{$class}." );
	}
}

echo "PASS: delete account section is gated by the users ability and uses theme classes only.\n";
