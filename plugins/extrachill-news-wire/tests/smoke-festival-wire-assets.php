<?php
/**
 * Focused smoke coverage for Festival Wire archive asset enqueuing.
 *
 * Guards against issue #16: the JavaScript handle must be distinct from the
 * stylesheet handle and must not declare stylesheet handles
 * (extrachill-root / extrachill-style) as script dependencies, which would
 * emit a "WP_Scripts::add was called incorrectly" doing_it_wrong notice.
 *
 * Run with: php tests/smoke-festival-wire-assets.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// Stylesheet handles registered by the Extra Chill theme. A script that lists
// any of these as a dependency triggers the doing_it_wrong notice because they
// live in WP_Styles, not WP_Scripts.
$theme_stylesheet_handles = array( 'extrachill-root', 'extrachill-style' );

$enqueued_styles  = array();
$enqueued_scripts = array();
$is_archive       = false;

function add_action() {}
function add_filter() {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file )  { return 'https://example.test/' . basename( dirname( $file ) ) . '/'; }
function is_post_type_archive( $post_type ) { global $is_archive; return $is_archive && 'festival_wire' === $post_type; }
function is_singular( $post_type ) { return false; }
function is_front_page() { return false; }
function is_home() { return false; }
function home_url() { return 'https://wire.extrachill.com'; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	global $enqueued_styles;
	$enqueued_styles[] = compact( 'handle', 'src', 'deps', 'ver', 'media' );
}
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	global $enqueued_scripts;
	$enqueued_scripts[] = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
}

require dirname( __DIR__ ) . '/extrachill-news-wire.php';

function expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

// Enqueue on a Festival Wire archive request.
$is_archive = true;
enqueue_festival_wire_assets();

expect( 1 === count( $enqueued_styles ), 'The archive must enqueue exactly one stylesheet.' );
expect( 1 === count( $enqueued_scripts ), 'The archive must enqueue exactly one script.' );

$style  = $enqueued_styles[0];
$script = $enqueued_scripts[0];

expect( 'extrachill-festival-wire' === $style['handle'], 'The stylesheet must keep the extrachill-festival-wire handle.' );
expect( $style['handle'] !== $script['handle'], 'The script must use a handle distinct from the stylesheet.' );
expect( is_array( $script['deps'] ), 'The script dependencies must be an array.' );
expect( (string) filemtime( dirname( __DIR__ ) . '/assets/festival-wire.css' ) === $style['ver'], 'The stylesheet version must preserve its modification time as a string.' );
expect( (string) filemtime( dirname( __DIR__ ) . '/assets/festival-wire.js' ) === $script['ver'], 'The script version must preserve its modification time as a string.' );

$invalid_script_deps = array_intersect( $script['deps'], $theme_stylesheet_handles );
expect( empty( $invalid_script_deps ), 'The script must not declare stylesheet handles as dependencies: ' . implode( ', ', $invalid_script_deps ) );

// Regression guard for the exact notice from issue #16.
expect( ! in_array( 'extrachill-root', $script['deps'], true ), 'extrachill-root must not be a script dependency.' );
expect( ! in_array( 'extrachill-style', $script['deps'], true ), 'extrachill-style must not be a script dependency.' );

print "Festival Wire asset dependency smoke test passed.\n";
