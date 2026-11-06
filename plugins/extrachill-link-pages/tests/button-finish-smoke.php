<?php
/* Standalone regression coverage for #1170: php tests/button-finish-smoke.php. */
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( $code, $message ) {}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function apply_filters( $hook, $value ) { return $value; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function absint( $value ) { return abs( (int) $value ); }
function check_finish( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/inc/storage.php';
require dirname( __DIR__ ) . '/inc/public-runtime.php';

$key = '--link-page-button-finish';
check_finish( 'flat' === ec_link_page_defaults()['styles'][ $key ], 'Default must be flat.' );
foreach ( array( 'flat', 'gloss' ) as $finish ) {
	check_finish( array( $key => $finish ) === ec_sanitize_link_page_css_vars( array( $key => $finish ) ), 'Valid finish rejected.' );
}
foreach ( array( 'shiny', '', 'GLOSS', 'gloss; color:red', 'gloss/*x*/', array(), null ) as $invalid ) {
	check_finish( is_wp_error( ec_sanitize_link_page_css_vars( array( $key => $invalid ) ) ), 'Invalid finish accepted.' );
}
$gloss = ec_link_page_css_variables_style_block( array( $key => 'gloss' ) );
check_finish( false !== strpos( $gloss, $key . ':gloss;' ), 'Renderer must emit the finish variable.' );
check_finish( false !== strpos( $gloss, '--link-page-button-sheen:linear-gradient(' ), 'Gloss must render its highlight.' );
check_finish( false !== strpos( $gloss, 'rgba(255,255,255,.45)' ), 'Highlight must be translucent.' );
foreach ( array( 'flat', 'invalid' ) as $finish ) {
	$output = ec_link_page_css_variables_style_block( array( $key => $finish ) );
	check_finish( false !== strpos( $output, '--link-page-button-sheen:none;' ), 'Non-gloss must disable the sheen.' );
}
$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/extrch-links.css' );
check_finish( false !== strpos( $css, 'background-image: var(--link-page-button-sheen, none)' ), 'CSS must consume the rendered sheen.' );
check_finish( false !== strpos( $css, '.extrch-link-button-wrapper:active' ), 'Tap state must retain the finish.' );

/* Exercise the actual ability handler with only its external WordPress/storage boundary stubbed. */
function extrachill_artist_platform_ability_artist_permission( $input ) { return true; }
function ec_artist_link_page_owner_reference( $id ) { return array( 'artist_id' => $id ); }
function ec_get_link_page_id_for_owner( $owner ) { return 42; }
function get_post_meta( $id, $key, $single ) { return array( '--link-page-button-radius' => '8px' ); }
function extrachill_artist_platform_sanitize_css_vars( $vars ) { return ec_sanitize_link_page_css_vars( $vars ); }
function ec_save_link_page( $target, $data ) { return $data; }
require dirname( __DIR__, 2 ) . '/extrachill-artist-platform/inc/abilities/handlers/save-link-page-styles.php';
$result = extrachill_artist_platform_ability_save_link_page_styles( array( 'artist_id' => 7, 'css_vars' => array( $key => 'gloss' ) ) );
check_finish( 'gloss' === $result['css_vars'][ $key ], 'Ability must save the finish.' );
check_finish( '8px' === $result['css_vars']['--link-page-button-radius'], 'Ability must preserve existing styles.' );
check_finish( is_wp_error( extrachill_artist_platform_ability_save_link_page_styles( array( 'artist_id' => 7, 'css_vars' => array( $key => 'shiny' ) ) ) ), 'Ability must reject invalid finishes.' );
echo "Button finish smoke test passed.\n";
