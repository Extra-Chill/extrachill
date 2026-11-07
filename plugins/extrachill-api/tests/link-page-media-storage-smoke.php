<?php
/* Standalone regression coverage: php tests/link-page-media-storage-smoke.php */

$fixture = sys_get_temp_dir() . '/ec-media-smoke-' . getmypid() . '/';
mkdir( $fixture . 'wp-admin/includes', 0777, true );
file_put_contents( $fixture . 'wp-admin/includes/image.php', '<?php' );
define( 'ABSPATH', $fixture );
register_shutdown_function( static function () use ( $fixture ) {
	unlink( $fixture . 'wp-admin/includes/image.php' );
	rmdir( $fixture . 'wp-admin/includes' );
	rmdir( $fixture . 'wp-admin' );
	rmdir( $fixture );
} );

function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
class WP_Error {
	public $code;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
}
class WP_REST_Request {
	public $context;
	public function __construct( $context ) { $this->context = $context; }
	public function get_param( $key ) { return 'context' === $key ? $this->context : 27; }
	public function get_file_params() {
		return array( 'file' => array( 'name' => 'image.png', 'tmp_name' => '/tmp/image.png', 'size' => 123 ) );
	}
}
$blog = 4;
$mode = '';
$events = array();
$missing = false;
function add_action( ...$args ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function get_current_blog_id() { return $GLOBALS['blog']; }
function ec_get_link_page_storage_blog_id() { return 13; }
function ec_with_link_page_storage_blog( $callback ) {
	$previous = $GLOBALS['blog'];
	$GLOBALS['blog'] = 13;
	try { return $callback(); } finally { $GLOBALS['blog'] = $previous; }
}
function wp_check_filetype_and_ext( ...$args ) { return array( 'type' => 'image/png' ); }
function get_current_user_id() { return 8; }
function wp_handle_upload( ...$args ) {
	$GLOBALS['events'][] = array( 'upload', get_current_blog_id() );
	if ( 'upload_error' === $GLOBALS['mode'] ) { return array( 'error' => 'Failed' ); }
	return array( 'file' => '/tmp/image.png', 'url' => 'https://link.example/image.png', 'type' => 'image/png' );
}
function wp_insert_attachment( ...$args ) {
	$GLOBALS['events'][] = array( 'insert', get_current_blog_id() );
	return 'insert_error' === $GLOBALS['mode'] ? new WP_Error( 'insert_failed' ) : 91;
}
function wp_generate_attachment_metadata( ...$args ) { return array(); }
function wp_update_attachment_metadata( ...$args ) {
	$GLOBALS['events'][] = array( 'metadata', get_current_blog_id() );
}
function wp_delete_attachment( $id, $force ) {
	$GLOBALS['events'][] = array( 'delete', get_current_blog_id(), $id );
	return true;
}
function wp_get_attachment_url( $id ) {
	check( 13 === get_current_blog_id(), 'Response URL must resolve on storage blog' );
	return 'https://link.example/image.png';
}
function wp_prepare_attachment_for_js( $id ) {
	check( 13 === get_current_blog_id(), 'Response attachment must resolve on storage blog' );
	return array( 'id' => $id );
}
function rest_ensure_response( $data ) { return $data; }
function update_post_meta( ...$args ) { check( false, 'Unexpected direct post meta write' ); }
function set_post_thumbnail( ...$args ) { check( false, 'Transport must not write artist thumbnail' ); }
function delete_post_thumbnail( ...$args ) { check( false, 'Transport must not remove artist thumbnail' ); }
function extrachill_artist_platform_ability_artist_permission( $input ) { return true; }
function ec_artist_link_page_owner_reference( $id ) { return 'artist:4:' . $id; }
function ec_get_link_page_id_for_owner( $reference ) { return 40; }
function ec_save_link_page( $target, $data ) {
	check( 13 === get_current_blog_id(), 'Save must run on storage blog' );
	check( 40 === $target['link_page_id'], 'Save must target canonical link page' );
	$GLOBALS['events'][] = array( 'save', get_current_blog_id(), $data );
	if ( 'throw' === $GLOBALS['mode'] ) { throw new RuntimeException( 'Save failed' ); }
	return 'save_error' === $GLOBALS['mode'] ? new WP_Error( 'save_failed' ) : $data;
}
function wp_get_ability( $name ) {
	if ( $GLOBALS['missing'] ) { return null; }
	return new class( $name ) {
		private $name;
		public function __construct( $name ) { $this->name = $name; }
		public function execute( $input ) {
			check( 13 === get_current_blog_id(), 'Ability must execute on storage blog' );
			if ( 'extrachill/get-link-page-data' === $this->name ) {
				return array( 'profile_image_id' => 71, 'background_image_id' => 72 );
			}
			check( 'extrachill/save-link-page-settings' === $this->name, 'Unexpected ability' );
			return extrachill_artist_platform_ability_save_link_page_settings( $input );
		}
	};
}
require __DIR__ . '/../inc/routes/media/upload.php';
require __DIR__ . '/../../extrachill-artist-platform/inc/abilities/handlers/save-link-page-settings.php';

$source = file_get_contents( __DIR__ . '/../inc/routes/media/upload.php' );
check( ! preg_match( '/update_post_meta\s*\(\s*\$link_page_id\b/', $source ), 'Raw link page meta write remains' );
foreach ( array( 'link_page_profile' => 'profile_image_id', 'link_page_background' => 'background_image_id' ) as $context => $field ) {
	$request = new WP_REST_Request( $context );
	foreach ( array( '', 'upload_error', 'insert_error', 'save_error', 'throw' ) as $mode ) {
		$events = array();
		try {
			$result = extrachill_api_media_upload_handler( $request );
			check( 'throw' !== $mode, 'Expected save exception' );
			check( '' === $mode ? 91 === $result['attachment_id'] : is_wp_error( $result ), 'Wrong upload result: ' . $mode );
		} catch ( RuntimeException $error ) {
			check( 'throw' === $mode, 'Unexpected exception' );
		}
		check( 4 === $blog, 'Upload must restore caller blog: ' . $mode );
		foreach ( $events as $event ) { check( 13 === $event[1], 'Media operation ran on caller blog' ); }
		if ( '' === $mode ) {
			check( in_array( array( 'save', 13, array( $field => 91 ) ), $events, true ), 'Image did not use settings save path' );
			check( ! in_array( array( 'delete', 13, 71 ), $events, true ), 'Shared old media was deleted' );
		}
		if ( 'save_error' === $mode ) {
			check( in_array( array( 'delete', 13, 91 ), $events, true ), 'Failed save must clean up new attachment' );
		}
	}
	$mode = '';
	$events = array();
	$result = extrachill_api_media_delete_handler( $request );
	check( true === $result['deleted'], 'Delete failed' );
	check( in_array( array( 'save', 13, array( $field => 0 ) ), $events, true ), 'Removal must use settings save path' );
	check( 4 === $blog, 'Delete must restore caller blog' );
	$mode = 'save_error';
	check( is_wp_error( extrachill_api_media_delete_handler( $request ) ), 'Removal must propagate save error' );
	check( 4 === $blog, 'Failed removal must restore caller blog' );
}
$mode = '';
$missing = true;
check( is_wp_error( extrachill_api_media_upload_handler( new WP_REST_Request( 'link_page_profile' ) ) ), 'Missing ability must fail' );
check( 4 === $blog, 'Missing ability must restore caller blog' );
echo "Link Page media storage smoke test passed\n";
