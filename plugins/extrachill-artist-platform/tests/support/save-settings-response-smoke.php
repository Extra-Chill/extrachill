<?php
/** Standalone regression: php plugins/extrachill-artist-platform/tests/support/save-settings-response-smoke.php */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['blog_id'] = 4;
$GLOBALS['meta'] = array();
$GLOBALS['attachment_reads'] = array();
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function get_current_blog_id() { return $GLOBALS['blog_id']; }
function ec_get_link_page_storage_blog_id() { return 13; }
function ec_with_link_page_storage_blog( $callback ) {
	$previous = $GLOBALS['blog_id'];
	$GLOBALS['blog_id'] = 13;
	try { return $callback(); } finally { $GLOBALS['blog_id'] = $previous; }
}
function ec_link_page_post_type() { return 'link_page'; }
function get_post_type( $id ) { return 13 === get_current_blog_id() && 12187 === $id ? 'link_page' : 'artist_profile'; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ get_current_blog_id() ][ $id ][ $key ] ?? ''; }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['meta'][ get_current_blog_id() ][ $id ][ $key ] ); }
function get_post_field( $field, $id ) { return 'QRiSG'; }
function get_the_title( $id ) { return 'Artist title'; }
function get_post( $id ) { return (object) array( 'ID' => $id ); }
function get_post_thumbnail_id( $id ) { return 999; }
function get_the_post_thumbnail_url( $id, $size ) { return 'https://artist.example/wrong.jpg'; }
function wp_get_attachment_url( $id ) {
	$GLOBALS['attachment_reads'][] = get_current_blog_id();
	return 13 === get_current_blog_id() && 13646 === $id ? 'https://extrachill.link/uploads/profile.jpg' : false;
}
function extrachill_artist_platform_social_links() {
	return new class { public function get( $id ) { return array(); } };
}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function extrachill_artist_platform_ability_artist_permission( $input ) { return true; }
function ec_get_blog_id( $site ) { return 4; }
function ec_normalize_link_page_owner_reference( $owner ) { return 'post:4:artist_profile:' . $owner['object_id']; }
function ec_get_link_page_id_for_owner( $owner ) { return 12187; }

// Model only the operation routing and write boundary; execute the real ability,
// owner-provider response builder and storage reader (including blog switching).
function ec_handle_link_page_save( $id, $data ) {
	$GLOBALS['meta'][13][ $id ]['_link_page_profile_image_id'] = $data['profile_image_id'];
	return true;
}
function smoke_resolved() { return array( 'owner' => array( 'object_id' => 77 ), 'link_page_id' => 12187 ); }
function ec_save_link_page( $target, $data ) {
	return ec_with_link_page_storage_blog( static function () use ( $data ) {
		return ec_artist_link_page_operation_save( smoke_resolved(), $data );
	} );
}
function ec_read_link_page( $target ) {
	return ec_with_link_page_storage_blog( static function () {
		return ec_artist_link_page_operation_read( smoke_resolved() );
	} );
}

$plugin = dirname( __DIR__, 2 );
require_once dirname( $plugin ) . '/extrachill-link-pages/inc/storage.php';
require_once $plugin . '/inc/core/filters/data.php';
require_once $plugin . '/inc/link-pages/artist-owner-operations.php';
require_once $plugin . '/inc/abilities/handlers/save-link-page-settings.php';
require_once $plugin . '/inc/abilities/handlers/get-link-page-data.php';

function smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
foreach ( array( 4, 13, 1 ) as $blog ) {
	$GLOBALS['blog_id'] = $blog;
	foreach ( array( 13646, 0, 55555 ) as $image_id ) {
		$saved = extrachill_artist_platform_ability_save_link_page_settings( array( 'artist_id' => 77, 'profile_image_id' => $image_id ) );
		$read = extrachill_artist_platform_ability_get_link_page_data( array( 'artist_id' => 77 ) );
		$expected = 13646 === $image_id ? 'https://extrachill.link/uploads/profile.jpg' : '';
		smoke_assert( $expected === $saved['profile_img_url'], 'Save must return page-owned URL, including removal/missing media.' );
		smoke_assert( $saved['profile_img_url'] === $read['profile_img_url'], 'Save and read URLs must agree.' );
		smoke_assert( $image_id === $saved['settings']['profile_image_id'], 'Settings must report the saved page-owned ID.' );
		smoke_assert( $blog === get_current_blog_id(), 'Abilities must restore the caller blog.' );
	}
}
$GLOBALS['meta'][13][12187]['_link_page_profile_image_id'] = 13646;
$GLOBALS['blog_id'] = 4;
$data = ec_get_link_page_data( 77, 12187 );
smoke_assert( 'https://extrachill.link/uploads/profile.jpg' === $data['profile_img_url'], 'Direct reads must resolve media on storage blog.' );
$preview = ec_get_link_page_data( 77, 12187, array( 'profile_img_url' => 'https://preview.example/image.jpg' ) );
smoke_assert( 'https://preview.example/image.jpg' === $preview['profile_img_url'], 'Live preview override must remain supported.' );
smoke_assert( 4 === get_current_blog_id(), 'Direct read must restore caller blog.' );
smoke_assert( array( 13 ) === array_values( array_unique( $GLOBALS['attachment_reads'] ) ), 'Attachment URLs must only be resolved on blog 13.' );
echo "save-settings-response smoke passed\n";
