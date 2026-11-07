<?php
/** Standalone preview contract tests; run with php, no WordPress required. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
	public $code;
	public function __construct( $code, $message ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function apply_filters( $name, $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function add_action( ...$args ) {}
function __( $text, $domain ) { return $text; }
function wp_register_ability( $name, $config ) { $GLOBALS['abilities'][$name] = $config; }
function extrachill_artist_platform_ability_artist_permission( $input ) { return $GLOBALS['allowed']; }
function ec_artist_link_page_owner_reference( $id ) { return 'artist:' . $id; }
function ec_read_link_page( $owner ) {
	$GLOBALS['read_owner'] = $owner;
	return $GLOBALS['missing'] ? new WP_Error( 'not_found', 'Missing' ) : ec_read_link_page_persistence( 12 );
}
function ec_get_link_page_storage_blog_id() { return 13; }
function get_current_blog_id() { return 13; }
function ec_link_page_post_type() { return 'link_page'; }
function get_post_type( $id ) { return 'link_page'; }
function metadata_exists( $type, $id, $key ) { return false; }
function get_post_meta( $id, $key, $single ) {
	if ( '_link_page_custom_css_vars' === $key ) {
		return array( '--link-page-card-bg-color' => '#fff', '--link-page-link-text-color' => '#000000', '--link-page-button-bg-color' => '#ffffff' );
	}
	return '';
}
function get_post_field( $key, $id ) { return 'Test'; }
function ec_get_link_page_public_url( $id ) { return 'https://extrachill.link/test'; }
function check( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
require_once dirname( __DIR__, 3 ) . '/extrachill-link-pages/inc/storage.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities/handlers/get-link-page-data.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities/handlers/preview-link-page.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities/registry.php';
$GLOBALS['allowed'] = true;
$GLOBALS['missing'] = false;
extrachill_artist_platform_register_abilities();
$ability = $GLOBALS['abilities']['extrachill/preview-link-page'];
check( 'extrachill_artist_platform_ability_artist_permission' === $ability['permission_callback'], 'Same save permission' );
check( $ability['meta']['annotations']['readonly'], 'Read-only registration' );
$preview = extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 7, 'link_page_id' => 999 ) );
check( 'artist:7' === $GLOBALS['read_owner'], 'Only artist-owned page can be selected' );
check( 'https://extrachill.link/test' === $preview['public_url'], 'Canonical public URL' );
check( array( 'width' => 390, 'height' => 844 ) === $preview['viewport'], 'Mobile default' );
check( '#fff' === $preview['resolved_styles']['--link-page-card-bg-color'], 'Stored card color is preserved' );
check( '#000000' === $preview['resolved_styles']['--link-page-button-hover-text-color'], 'Derived hover text' );
check( 21.0 === $preview['contrast_report']['button_text_on_button']['ratio'], 'Black/white WCAG ratio' );
check( $preview['contrast_report']['icons_on_background'] === $preview['contrast_report']['page_text_on_background'], 'Icons default to page text, not button text' );
check( 'unavailable' === $preview['screenshot']['status'] && null === $preview['screenshot']['url'], 'Honest screenshot fallback' );
$styles = $preview['resolved_styles'];
$styles['--link-page-background-type'] = 'image';
/* A translucent card over a missing image has no measurable backdrop. */
$styles['--link-page-card-bg-color'] = 'rgba(0, 0, 0, 0.4)';
$report = extrachill_artist_platform_preview_contrast( $styles, array( 'overlay_enabled' => true ) );
check( 'unknown' === $report['page_text_on_card']['status'], 'Image cannot falsely pass' );
check( 'pass' === $report['button_text_on_button']['status'], 'Opaque button still measurable' );
$color = extrachill_artist_platform_preview_composite( array( 0, 0, 0, 0.4 ), array( 255, 255, 255, 1 ) );
check( 153.0 === $color[0], 'Alpha compositing' );
check( null === extrachill_artist_platform_preview_color( 'var(--unknown)' ), 'Unsupported colors unknown' );
check( null === extrachill_artist_platform_preview_color( 'rgb(999,0,0)' ), 'Invalid color rejected' );
$custom = extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 7, 'viewport' => array( 'width' => 800, 'height' => 600 ) ) );
check( 800 === $custom['viewport']['width'], 'Custom viewport' );
$invalid = extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 7, 'viewport' => array( 'width' => 0, 'height' => 844 ) ) );
check( 'invalid_viewport' === $invalid->code, 'Invalid viewport rejected' );
check( 'missing_artist_id' === extrachill_artist_platform_ability_preview_link_page( array() )->code, 'Missing artist rejected' );
$GLOBALS['allowed'] = false;
check( 'artist_access_denied' === extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 7 ) )->code, 'Unauthorized direct execution rejected' );
$GLOBALS['allowed'] = true;
$GLOBALS['missing'] = true;
check( 'no_link_page' === extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 7 ) )->code, 'Missing page rejected' );
echo "preview-link-page smoke: PASS\n";
