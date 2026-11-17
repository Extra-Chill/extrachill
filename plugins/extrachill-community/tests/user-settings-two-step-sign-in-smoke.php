<?php
/**
 * Standalone contract test for the Settings → Security two-step sign-in section.
 *
 * Covers the server-side gate in render.php (the section exists only when
 * extrachill-users reports Two Factor's management routes, and the QR library
 * Two Factor registers is reused) and source-level checks on the section:
 * every class must be theme vocabulary from themes/extrachill/style.css, all
 * calls go to Two Factor's own REST routes, and nothing links members to a
 * WordPress or Two Factor screen.
 *
 * @package ExtraChillCommunity
 */

define( 'ABSPATH', __DIR__ . '/' );

function two_step_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['_enqueued']   = array();
$GLOBALS['_registered'] = array( 'two-factor-qr-code-generator' );

function is_user_logged_in() {
	return true;
}
function auth_redirect() {
	throw new RuntimeException( 'Logged-in render must not redirect.' );
}
function wp_enqueue_script( $handle ) {
	$GLOBALS['_enqueued'][] = $handle;
}
function wp_script_is( $handle, $status ) {
	return 'registered' === $status && in_array( $handle, $GLOBALS['_registered'], true );
}
function ec_get_site_url( $site ) {
	return 'https://artist.example.com';
}
function get_current_user_id() {
	return 7;
}
function is_wp_error( $value ) {
	return false;
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
	$GLOBALS['_enqueued'] = array();
	ob_start();
	require dirname( __DIR__ ) . '/src/blocks/user-settings/render.php';
	return (string) ob_get_clean();
}

function two_factor_state( string $html ): string {
	two_step_assert( 1 === preg_match( '/data-two-factor="([^"]*)"/', $html, $matches ), 'Container must carry data-two-factor.' );
	return $matches[1];
}

// Users plugin absent: nothing new renders and the QR library is not loaded.
$html = render_user_settings();
two_step_assert( '0' === two_factor_state( $html ), 'Missing users helper must hide the section.' );
two_step_assert( ! in_array( 'two-factor-qr-code-generator', $GLOBALS['_enqueued'], true ), 'QR library must not load when the section is hidden.' );
two_step_assert( false === strpos( $html, 'profile.php' ) && false === strpos( $html, 'wp-admin' ), 'Hidden section must not fall back to a WordPress screen.' );

// The users helper decides.
if ( ! function_exists( 'extrachill_users_two_factor_settings_available' ) ) {
	function extrachill_users_two_factor_settings_available() {
		return $GLOBALS['_test_two_factor'];
	}
}

$GLOBALS['_test_two_factor'] = false;
$html                        = render_user_settings();
two_step_assert( '0' === two_factor_state( $html ), 'Unavailable routes must hide the section.' );
two_step_assert( ! in_array( 'two-factor-qr-code-generator', $GLOBALS['_enqueued'], true ), 'QR library must not load for unavailable routes.' );

$GLOBALS['_test_two_factor'] = true;
$html                        = render_user_settings();
two_step_assert( '1' === two_factor_state( $html ), 'Available routes must show the section.' );
two_step_assert( in_array( 'two-factor-qr-code-generator', $GLOBALS['_enqueued'], true ), 'Section must reuse Two Factor\'s registered QR library.' );

$GLOBALS['_registered'] = array();
$html                   = render_user_settings();
two_step_assert( '1' === two_factor_state( $html ), 'A missing QR library must not hide the section.' );
two_step_assert( ! in_array( 'two-factor-qr-code-generator', $GLOBALS['_enqueued'], true ), 'Unregistered QR library must not be enqueued.' );

// Source-level checks on the section.
$block_dir = dirname( __DIR__ ) . '/src/blocks/user-settings/';
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template reads.
$section = (string) file_get_contents( $block_dir . 'two-step-sign-in.tsx' );
$model   = (string) file_get_contents( $block_dir . 'two-step-model.ts' );
$view    = (string) file_get_contents( $block_dir . 'view.tsx' );
// phpcs:enable

foreach ( array(
	'users/${ userId }/providers',
	'/totp/enrollment',
	'/totp`',
	'/generate-backup-codes',
	'/revalidate',
) as $route ) {
	two_step_assert( false !== strpos( $section, $route ), "Section must call Two Factor's {$route} route." );
}
two_step_assert( false !== strpos( $section, "const NS = 'two-factor/1.0';" ), 'Section must use the Two Factor REST namespace.' );
two_step_assert( false !== strpos( $section, 'apiFetch' ), 'Section must use cookie-authenticated wp.apiFetch.' );
two_step_assert( false === strpos( $section, 'extrachill/v1' ), 'No bespoke extrachill/v1 route for two-step sign-in.' );
two_step_assert( 1 === preg_match( '/twoFactor\s*&&\s*userId\s*>\s*0\s*&&/', $view ), 'Security tab must render the section only when the server enabled it.' );

foreach ( array( $section, $model ) as $source ) {
	two_step_assert( 0 === preg_match( '/wp-admin|profile\.php|wp-login\.php/', $source ), 'Section must never link to a WordPress or Two Factor screen.' );
	two_step_assert( 0 === preg_match( '/\b(TOTP|2FA|two-factor authentication|OTP)\b/', visible_strings( $source ) ), 'Member copy must say two-step sign-in, not technical terms.' );
}

/**
 * Strip comments and identifiers that are not member copy: keep only
 * JSX text and string literals that a member could see.
 *
 * @param string $source TypeScript source.
 * @return string Visible strings joined by newlines.
 */
function visible_strings( string $source ): string {
	$source = (string) preg_replace( '#/\*.*?\*/#s', '', $source );
	$source = (string) preg_replace( '#^\s*//.*$#m', '', $source );
	preg_match_all( "/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|>[^<>{}]+</", $source, $matches );
	return implode( "\n", $matches[0] );
}

$allowed = array(
	'button-1',
	'button-2',
	'button-3',
	'button-danger',
	'button-small',
	'notice',
	'notice-info',
	'notice-success',
	'notice-error',
);

preg_match_all( '/className="([^"]*)"/', $section, $class_lists );
preg_match_all( "/'((?:button|notice)-[a-z0-9-]+(?: [a-z0-9-]+)*)'/", $section, $string_classes );
$lists = array_merge( $class_lists[1], $string_classes[1] );
two_step_assert( ! empty( $lists ), 'Section must use theme classes.' );
$used = array();
foreach ( $lists as $list ) {
	foreach ( preg_split( '/\s+/', trim( $list ) ) as $class ) {
		two_step_assert( in_array( $class, $allowed, true ), "Two-step sign-in section uses .{$class}, which is not on the design-system allow-list." );
		$used[] = $class;
	}
}
two_step_assert( in_array( 'button-danger', $used, true ), 'Turning off two-step sign-in must use .button-danger.' );
two_step_assert( false !== strpos( $section, 'className={ `notice notice-${ notice.type }` }' ), 'Notices must render theme notice classes.' );
two_step_assert( 0 === preg_match( '/className=\{\s*`(?!notice notice-\$\{ notice\.type \})/', $section ), 'Dynamic class names must stay on the allow-list.' );

// Every allow-listed class must exist in the theme stylesheet when the monorepo theme is present.
$theme_css_path = dirname( __DIR__, 3 ) . '/themes/extrachill/style.css';
if ( is_readable( $theme_css_path ) ) {
	$theme_css = (string) file_get_contents( $theme_css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level stylesheet read.
	foreach ( $allowed as $class ) {
		two_step_assert( 1 === preg_match( '/\.' . preg_quote( $class, '/' ) . '\b/', $theme_css ), "Theme stylesheet must define .{$class}." );
	}
}

echo "PASS: two-step sign-in section is gated by the users helper, calls Two Factor's routes, and uses theme classes only.\n";
