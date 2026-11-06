<?php
/** Standalone regression: php tests/support/link-page-styles-partial-save-smoke.php */
define( 'ABSPATH', __DIR__ );

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

class WP_Error {
	public function __construct( public $code, public $message ) {}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function add_action( ...$args ) {}
function sanitize_hex_color( $value ) {
	return preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $value ) ? $value : null;
}
function extrachill_artist_platform_ability_artist_permission( $input ) { return true; }
function ec_artist_link_page_owner_reference( $artist_id ) {
	return array( 'artist_id' => $artist_id );
}
function ec_get_link_page_id_for_owner( $owner ) { return 12187; }
function extrachill_artist_platform_sanitize_css_vars( $vars ) {
	return ec_sanitize_link_page_css_vars( $vars );
}
function get_post_meta( ...$args ) {
	throw new RuntimeException( 'Handler must not read caller-blog link page meta' );
}
function update_post_meta( ...$args ) {
	throw new RuntimeException( 'Handler must not write caller-blog link page meta' );
}
function ec_save_link_page( $target, $data ) {
	$GLOBALS['saves'][] = array( $target, $data );
	if ( isset( $GLOBALS['save_error'] ) ) {
		return $GLOBALS['save_error'];
	}
	/* Model storage's merge against the link page blog, not the caller blog. */
	$GLOBALS['stored_vars'] = array_merge( $GLOBALS['stored_vars'], $data['css_vars'] );
	return array( 'link_page_id' => 12187, 'css_vars' => $GLOBALS['stored_vars'] );
}

$root = dirname( __DIR__, 2 );
require dirname( $root ) . '/extrachill-link-pages/inc/storage.php';
require $root . '/inc/abilities/handlers/save-link-page-styles.php';

$GLOBALS['stored_vars'] = array();
$GLOBALS['saves']       = array();
$palette = array(
	'--link-page-background-color'          => '#112233',
	'--link-page-button-bg-color'           => '#FF3D8B',
	'--link-page-background-gradient-start' => '#223344',
	'--link-page-background-gradient-end'   => '#556677',
	'--link-page-text-color'                => '#ffffff',
);
$result = extrachill_artist_platform_ability_save_link_page_styles( array( 'artist_id' => 42, 'css_vars' => $palette ) );
check( ! is_wp_error( $result ), 'Full palette save must succeed' );
$partial = array( '--link-page-text-color' => '  #aabbcc  ' );
$result = extrachill_artist_platform_ability_save_link_page_styles( array( 'artist_id' => 42, 'css_vars' => $partial ) );
check( ! is_wp_error( $result ), 'Partial save must succeed' );
check( array( 'css_vars' => array( '--link-page-text-color' => '#aabbcc' ) ) === $GLOBALS['saves'][1][1], 'Save payload must contain only sanitized incoming keys' );
check( array( 'link_page_id' => 12187, 'owner_reference' => array( 'artist_id' => 42 ) ) === $GLOBALS['saves'][1][0], 'Save must target the owned link page' );
check( array_merge( $palette, array( '--link-page-text-color' => '#aabbcc' ) ) === $result['css_vars'], 'Unsent colors must retain their stored values' );

/* These keys remain accepted; their persistence contract belongs to link-pages. */
$editable = array( '--link-page-card-bg-color' => '#123456', '--link-page-button-hover-text-color' => '#abcdef' );
$result = extrachill_artist_platform_ability_save_link_page_styles( array( 'artist_id' => 42, 'css_vars' => $editable ) );
check( ! is_wp_error( $result ) && array( 'css_vars' => $editable ) === $GLOBALS['saves'][2][1], 'Card and hover text colors must pass through' );
$count = count( $GLOBALS['saves'] );
$result = extrachill_artist_platform_ability_save_link_page_styles( array( 'artist_id' => 42, 'css_vars' => array( '--link-page-text-color' => 'red;display:none' ) ) );
check( is_wp_error( $result ) && $count === count( $GLOBALS['saves'] ), 'Invalid styles must fail before storage' );
$GLOBALS['save_error'] = new WP_Error( 'save_failed', 'Storage failed' );
$result = extrachill_artist_platform_ability_save_link_page_styles( array( 'artist_id' => 42, 'css_vars' => array() ) );
check( $GLOBALS['save_error'] === $result, 'Storage errors must propagate unchanged' );

/* Guard every sibling handler, ignoring comments and whitespace. */
foreach ( glob( $root . '/inc/abilities/handlers/*.php' ) as $file ) {
	$source = '';
	foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
				continue;
			}
			$source .= $token[1];
		} else {
			$source .= $token;
		}
	}
	check( ! preg_match( '/\b(?:get|update|delete)_post_meta\(\$link_page_id\b/', $source ), basename( $file ) . ': raw caller-blog link page meta access' );
}
echo "Link page styles partial save smoke tests passed\n";
