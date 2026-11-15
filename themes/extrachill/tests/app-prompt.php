<?php
/**
 * Android app prompt server-side gating and design-system coverage (extrachill#1416).
 *
 * - Renders nothing until the App Store ID or Play package is configured.
 * - Always ships `hidden` (client-side JS owns UA/dismissal/first-pageview gating).
 * - Uses only classes the theme stylesheet defines (same contract as
 *   extrachill-users OAuthTemplateDesignSystemTest).
 *
 * @package ExtraChill
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone fixture: WP doubles and failure reporting are intentional.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['ap_site_options'] = array();
$GLOBALS['ap_is_article']   = false;

/** Network option double. */
function get_site_option( $option, $default_value = false ) {
	return array_key_exists( $option, $GLOBALS['ap_site_options'] ) ? $GLOBALS['ap_site_options'][ $option ] : $default_value;
}
/** Hook registration double. */
function add_action() {}
/** Filter double. */
function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}
/** Conditional double. */
function is_singular( $post_types = '' ) {
	unset( $post_types );
	return $GLOBALS['ap_is_article'];
}
/** Escaping doubles. */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function esc_html_e( $text, $domain = '' ) {
	unset( $domain );
	echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

require_once dirname( __DIR__ ) . '/inc/core/app-prompt.php';

/**
 * Report a failure and exit non-zero.
 *
 * @param string $message Message.
 */
function ap_fail( $message ) {
	fwrite( STDERR, 'FAIL: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Render the prompt with the given network options.
 *
 * @param array $options Network options.
 * @param bool  $article Whether the page is a single article.
 * @return string
 */
function ap_render( array $options, $article = false ) {
	$GLOBALS['ap_site_options'] = $options;
	$GLOBALS['ap_is_article']   = $article;
	ob_start();
	extrachill_render_app_prompt();
	return (string) ob_get_clean();
}

// 1. Nothing until configured.
if ( '' !== ap_render( array() ) ) {
	ap_fail( 'Prompt rendered with no store ID or Play package configured.' );
}
if ( '' !== ap_render( array( 'extrachill_app_store_id' => '   ' ) ) ) {
	ap_fail( 'Prompt rendered with a blank store ID.' );
}

// 2. Store ID is the launch switch; the default package is used.
$html = ap_render( array( 'extrachill_app_store_id' => '1234567890' ) );
if ( false === strpos( $html, 'https://play.google.com/store/apps/details?id=com.extrachill.app' ) ) {
	ap_fail( 'Store ID set: expected default Play package link, got ' . $html );
}

// 3. Explicit package enables Android alone and wins over the default.
$html = ap_render( array( 'extrachill_play_package' => 'com.example.music' ) );
if ( false === strpos( $html, 'details?id=com.example.music' ) ) {
	ap_fail( 'Explicit package: expected its Play link, got ' . $html );
}

// 4. Empty or invalid package disables the prompt even after launch.
foreach ( array( '', 'not a package', 'com.extrachill.app"><script>' ) as $bad ) {
	if ( '' !== ap_render(
		array(
			'extrachill_app_store_id' => '1234567890',
			'extrachill_play_package' => $bad,
		)
	) ) {
		ap_fail( 'Invalid package rendered the prompt: ' . var_export( $bad, true ) );
	}
}

// 5. Never visible server-side (cache-safe; JS gates UA, dismissal, first pageview).
$html = ap_render( array( 'extrachill_app_store_id' => '1' ) );
if ( ! preg_match( '/<div[^>]*data-ec-app-prompt[^>]*\bhidden>/', $html ) ) {
	ap_fail( 'Prompt must render with the hidden attribute: ' . $html );
}
if ( false === strpos( $html, 'data-ec-app-prompt-dismiss' ) ) {
	ap_fail( 'Prompt must include a dismiss control.' );
}
if ( false === strpos( $html, 'data-article="0"' ) || false === strpos( ap_render( array( 'extrachill_app_store_id' => '1' ), true ), 'data-article="1"' ) ) {
	ap_fail( 'Prompt must flag article pages for first-pageview gating.' );
}

// 6. Only classes the theme stylesheet defines.
$css = (string) file_get_contents( dirname( __DIR__ ) . '/style.css' );
preg_match_all( '/class="([^"]+)"/', $html, $matches );
$classes = array_unique( preg_split( '/\s+/', implode( ' ', $matches[1] ) ) );
foreach ( $classes as $class ) {
	if ( ! preg_match( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $css ) ) {
		ap_fail( "Class .{$class} is not defined in themes/extrachill/style.css." );
	}
}
foreach ( array( 'notice', 'notice-info', 'button-small' ) as $required ) {
	if ( ! in_array( $required, $classes, true ) ) {
		ap_fail( "Prompt should use the theme's .{$required} class." );
	}
}

echo "App prompt renders only when configured, ships hidden, and uses theme classes only.\n";
