<?php
/** Standalone QR page ad opt-in contract: php tests/qr-code-ads-smoke.php */
// phpcs:disable -- Test doubles and WordPress stubs.
define( 'ABSPATH', __DIR__ );
$GLOBALS['logged_in'] = false;
$GLOBALS['posts']     = array();
class WP_Post {
	public function __construct( public int $ID, public string $post_content ) {}
}
function add_filter( ...$args ) {}
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function has_block( $name, $post ) { return false !== strpos( $post->post_content, '<!-- wp:' . $name ); }
function check( $condition, $message ) {
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $message . PHP_EOL;
	if ( ! $condition ) { exit( 1 ); }
}
require dirname( __DIR__ ) . '/src/blocks/qr-code-generator/index.php';
$GLOBALS['posts'][10] = new WP_Post( 10, '<!-- wp:extrachill/qr-code-generator /-->' );
$GLOBALS['posts'][11] = new WP_Post( 11, '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );
check( true === extrachill_content_blocks_qr_page_allows_ads( false, 10 ), 'QR page allows ads for logged-out visitors' );
check( false === extrachill_content_blocks_qr_page_allows_ads( false, 11 ), 'other pages stay ad-free' );
check( false === extrachill_content_blocks_qr_page_allows_ads( false, 999 ), 'missing post stays ad-free' );
$GLOBALS['logged_in'] = true;
check( false === extrachill_content_blocks_qr_page_allows_ads( false, 10 ), 'QR page is ad-free for logged-in visitors' );
check( true === extrachill_content_blocks_qr_page_allows_ads( true, 11 ), 'existing opt-ins are preserved' );
echo "PASS: QR page ad opt-in smoke complete\n";
