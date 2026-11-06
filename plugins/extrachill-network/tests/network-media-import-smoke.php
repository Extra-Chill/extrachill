<?php
/**
 * Regression for Extra-Chill/extrachill#1167: network-media-upload accepts URL
 * and base64 image sources, targets a network site by blog_id, and checks
 * upload_files on that site. Standalone; no WordPress required.
 */

declare( strict_types=1 );

$abspath = sys_get_temp_dir() . '/network-media-import-smoke-' . getmypid() . '/';
@mkdir( $abspath . 'wp-admin/includes', 0777, true );
foreach ( array( 'file', 'media', 'image' ) as $include ) {
	file_put_contents( $abspath . "wp-admin/includes/{$include}.php", '<?php' );
}
define( 'ABSPATH', $abspath );

class WP_Error {
	public $code;
	public function __construct( $code, $message = '', $data = null ) {
		$this->code = $code;
	}
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function __( $text, $domain = '' ) {
	return $text;
}
function add_action( ...$args ) {}
function wp_parse_url( $url, $component ) {
	return parse_url( $url, $component );
}
function wp_http_validate_url( $url ) {
	return false !== filter_var( $url, FILTER_VALIDATE_URL ) && ! str_contains( $url, '127.0.0.1' ) && ! str_contains( $url, '192.168.' );
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['abilities'][ $name ] = $args;
}
function download_url( $url, $timeout ) {
	$GLOBALS['downloaded'][] = $url;
	return new WP_Error( 'stub_download' );
}
$GLOBALS['blog']      = 7;
$GLOBALS['stack']     = array();
$GLOBALS['logged_in'] = true;
$GLOBALS['caps']      = array( 13 => true );
function is_user_logged_in() {
	return $GLOBALS['logged_in'];
}
function get_site( $id ) {
	return in_array( $id, array( 1, 4, 13 ), true ) ? (object) array( 'deleted' => 0, 'archived' => 0, 'spam' => 0 ) : null;
}
function switch_to_blog( $id ) {
	$GLOBALS['stack'][] = $GLOBALS['blog'];
	$GLOBALS['blog']    = $id;
}
function restore_current_blog() {
	$GLOBALS['blog'] = array_pop( $GLOBALS['stack'] );
}
function current_user_can( $cap ) {
	return 'upload_files' === $cap && ! empty( $GLOBALS['caps'][ $GLOBALS['blog'] ] );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

require_once dirname( __DIR__ ) . '/inc/Abilities/NetworkMediaAbilities.php';
$media = ( new ReflectionClass( \ExtraChillNetwork\Abilities\NetworkMediaAbilities::class ) )->newInstanceWithoutConstructor();
$media->register();

$upload = $GLOBALS['abilities']['extrachill/network-media-upload'];
$props  = $upload['input_schema']['properties'];
$check( empty( $upload['input_schema']['required'] ), 'tmp_name is no longer required' );
$check( isset( $props['blog_id'], $props['source_url'], $props['image_base64'], $props['crop'] ), 'Schema exposes blog_id, source_url, image_base64, crop' );
$check( true === $upload['meta']['show_in_rest'], 'Upload stays REST/MCP visible' );

/* Permission follows the target site. Caller has upload_files on 13 only. */
$check( $media->permissionCallback( array( 'blog_id' => 13 ) ), 'Upload allowed on a site where the caller can upload' );
$check( ! $media->permissionCallback( array() ), 'Default target (main site) denied without upload_files there' );
$check( ! $media->permissionCallback( array( 'blog_id' => 4 ) ), 'Denied on a site without upload_files' );
$check( ! $media->permissionCallback( array( 'blog_id' => 99 ) ), 'Denied for a non-existent site' );
$check( ! $media->listPermissionCallback( array( 'blog_id' => 13 ) ), 'List permission ignores blog_id and checks the main site' );
$check( 7 === $GLOBALS['blog'] && array() === $GLOBALS['stack'], 'Permission checks restore the blog' );
$GLOBALS['logged_in'] = false;
$check( ! $media->permissionCallback( array( 'blog_id' => 13 ) ), 'Logged-out callers denied' );
$GLOBALS['logged_in'] = true;

$code = static function ( $result ) {
	return is_wp_error( $result ) ? $result->code : 'ok';
};

foreach ( array(
	array(),
	array( 'source_url' => 'https://example.com/a.png', 'image_base64' => 'iVBORw0KGgo=' ),
	array( 'tmp_name' => '/tmp/x', 'source_url' => 'https://example.com/a.png' ),
) as $input ) {
	$check( 'invalid_source' === $code( $media->executeUpload( $input + array( 'blog_id' => 13 ) ) ), 'Exactly one source required' );
}
$check( 'invalid_blog' === $code( $media->executeUpload( array( 'blog_id' => 99, 'source_url' => 'https://example.com/a.png' ) ) ), 'Unknown blog rejected' );
foreach ( array( 'file:///etc/passwd', 'ftp://example.com/a.png', 'http://127.0.0.1/a.png', 'http://192.168.1.4/a.png', 'not a url' ) as $url ) {
	$check( 'invalid_source_url' === $code( $media->executeUpload( array( 'blog_id' => 13, 'source_url' => $url ) ) ), "Unsafe URL rejected: {$url}" );
}
$check( empty( $GLOBALS['downloaded'] ), 'Nothing downloaded for rejected URLs' );
foreach ( array( '', 'not base64!!', 42 ) as $bad ) {
	$check( 'invalid_source' === $code( $media->executeUpload( array( 'blog_id' => 13, 'image_base64' => $bad ) ) ), 'Invalid base64 rejected' );
}
$huge = str_repeat( 'A', 7 * 1024 * 1024 + 16 );
$check( 'file_too_large' === $code( $media->executeUpload( array( 'blog_id' => 13, 'image_base64' => $huge ) ) ), 'Oversized base64 rejected before decode' );
foreach ( array( 'top', array( 'x' => 0, 'y' => 0, 'width' => 1 ), array( 'x' => 0.5, 'y' => 0, 'width' => 0.6, 'height' => 1 ), array( 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 1 ) ) as $crop ) {
	$check( 'invalid_crop' === $code( $media->executeUpload( array( 'blog_id' => 13, 'source_url' => 'https://example.com/a.png', 'crop' => $crop ) ) ), 'Invalid crop rejected' );
}
$check( 'invalid_crop' === $code( $media->executeUpload( array( 'tmp_name' => '/tmp/x', 'name' => 'a.png', 'type' => 'image/png', 'size' => 1, 'crop' => array( 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1 ) ) ) ), 'Crop refused for multipart uploads' );
$box = \ExtraChillNetwork\Abilities\NetworkMediaAbilities::cropBox( array( 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.667 ) );
$check( is_array( $box ) && 0.667 === $box['height'], 'Top-two-thirds crop accepted' );
$check( 'stub_download' === $code( $media->executeUpload( array( 'blog_id' => 13, 'source_url' => 'https://cdn.discordapp.com/attachments/1/2/a.png?ex=1' ) ) ), 'Public chat attachment URL reaches download' );
$check( 7 === $GLOBALS['blog'], 'Blog restored after import' );

echo "network-media-import smoke: OK\n";
