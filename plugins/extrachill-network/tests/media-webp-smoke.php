<?php
/**
 * Smoke test: existing WebP siblings are served for uploads images.
 */

define( 'ABSPATH', __DIR__ . '/' );
$root = sys_get_temp_dir() . '/ec-media-webp-smoke-' . getmypid();
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
@mkdir( WP_CONTENT_DIR . '/uploads/2026/01', 0777, true );
file_put_contents( WP_CONTENT_DIR . '/uploads/2026/01/a-300x200.jpg', 'x' );
file_put_contents( WP_CONTENT_DIR . '/uploads/2026/01/a-300x200.jpg.webp', 'x' );
file_put_contents( WP_CONTENT_DIR . '/uploads/2026/01/b.png', 'x' );

function add_filter() {}
function add_action() {}
function apply_filters( $hook, $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require_once dirname( __DIR__ ) . '/inc/media/image-optimization.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
};

$assert( 'https://x.test/wp-content/uploads/2026/01/a-300x200.jpg.webp' === ec_media_webp_url( 'https://x.test/wp-content/uploads/2026/01/a-300x200.jpg' ), 'sibling webp served' );
$assert( 'https://x.test/wp-content/uploads/2026/01/b.png' === ec_media_webp_url( 'https://x.test/wp-content/uploads/2026/01/b.png' ), 'no sibling keeps original' );
$assert( 'https://x.test/wp-content/uploads/2026/01/a-300x200.jpg.webp?v=2' === ec_media_webp_url( 'https://x.test/wp-content/uploads/2026/01/a-300x200.jpg?v=2' ), 'query string kept' );
$assert( 'https://x.test/wp-content/uploads/../../etc/passwd.jpg' === ec_media_webp_url( 'https://x.test/wp-content/uploads/../../etc/passwd.jpg' ), 'traversal ignored' );

$html = '<meta property="og:image" content="https://x.test/wp-content/uploads/2026/01/a-300x200.jpg">'
	. '<img src="https://x.test/wp-content/uploads/2026/01/a-300x200.jpg" srcset="https://x.test/wp-content/uploads/2026/01/a-300x200.jpg 300w, https://x.test/wp-content/uploads/2026/01/b.png 600w">';
$out  = ec_media_rewrite_html_images( $html );
$assert( 2 === substr_count( $out, 'a-300x200.jpg.webp' ), 'img src and srcset rewritten' );
$assert( false !== strpos( $out, 'content="https://x.test/wp-content/uploads/2026/01/a-300x200.jpg"' ), 'meta tags untouched' );
$assert( false !== strpos( $out, 'b.png 600w' ), 'missing sibling untouched' );

echo "media-webp-smoke: ok\n";
