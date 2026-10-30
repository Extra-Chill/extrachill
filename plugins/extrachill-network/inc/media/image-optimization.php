<?php
/**
 * Network image optimization with WordPress core (replaces Imagify).
 *
 * - New uploads: JPEG and PNG image sizes are written as WebP by the local
 *   image editor (Imagick), at a set quality, with oversized originals scaled
 *   down to 2048px.
 * - Existing uploads: front-end <img>/<source> tags that point at a JPEG or
 *   PNG in the network uploads directory are served the sibling
 *   `<file>.<ext>.webp` when one exists (Imagify's naming), so previously
 *   optimized images keep being served as WebP.
 *
 * @package ExtraChillNetwork
 */

defined( 'ABSPATH' ) || exit;

defined( 'EC_MEDIA_MAX_IMAGE_DIMENSION' ) || define( 'EC_MEDIA_MAX_IMAGE_DIMENSION', 2048 );
defined( 'EC_MEDIA_WEBP_QUALITY' ) || define( 'EC_MEDIA_WEBP_QUALITY', 82 );

/**
 * Write JPEG and PNG image sizes as WebP.
 *
 * @param mixed $formats MIME type map (source => output).
 * @return array
 */
function ec_media_output_formats( $formats ) {
	$formats = is_array( $formats ) ? $formats : array();
	if ( ! function_exists( 'wp_image_editor_supports' ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		return $formats;
	}
	$formats['image/jpeg'] = 'image/webp';
	$formats['image/png']  = 'image/webp';
	return $formats;
}
add_filter( 'image_editor_output_format', 'ec_media_output_formats' );

/**
 * Encode quality for generated WebP images.
 *
 * @param int    $quality   Default quality.
 * @param string $mime_type Output MIME type.
 * @return int
 */
function ec_media_image_quality( $quality, $mime_type = '' ) {
	return 'image/webp' === $mime_type ? (int) EC_MEDIA_WEBP_QUALITY : $quality;
}
add_filter( 'wp_editor_set_quality', 'ec_media_image_quality', 10, 2 );

/**
 * Scale oversized originals down (core default 2560).
 *
 * @return int
 */
function ec_media_big_image_threshold() {
	return (int) EC_MEDIA_MAX_IMAGE_DIMENSION;
}
add_filter( 'big_image_size_threshold', 'ec_media_big_image_threshold' );

/**
 * Rewrite one uploads URL to its sibling WebP when that file exists.
 *
 * @param string $url Image URL.
 * @return string
 */
function ec_media_webp_url( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( ! preg_match( '#^/wp-content/uploads/.+\.(?:jpe?g|png)$#i', $path ) ) {
		return $url;
	}
	$file = WP_CONTENT_DIR . substr( rawurldecode( $path ), strlen( '/wp-content' ) );
	if ( false !== strpos( $file, '..' ) || ! is_file( $file . '.webp' ) ) {
		return $url;
	}
	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
	$base  = '' === $query ? $url : substr( $url, 0, -strlen( '?' . $query ) );
	return $base . '.webp' . ( '' === $query ? '' : '?' . $query );
}

/**
 * Rewrite image URLs inside <img> and <source> tags of an HTML document.
 *
 * @param mixed $html HTML.
 * @return mixed
 */
function ec_media_rewrite_html_images( $html ) {
	if ( ! is_string( $html ) || false === stripos( $html, '/wp-content/uploads/' ) ) {
		return $html;
	}
	$rewritten = preg_replace_callback(
		'#<(?:img|source)\b[^>]*>#i',
		static function ( $tag ) {
			return (string) preg_replace_callback(
				'#https?://[^\s"\',]+/wp-content/uploads/[^\s"\',]+\.(?:jpe?g|png)(?:\?[^\s"\',]*)?#i',
				static function ( $url ) {
					return ec_media_webp_url( $url[0] );
				},
				$tag[0]
			);
		},
		$html
	);
	return is_string( $rewritten ) ? $rewritten : $html;
}

/** Whether this request renders a front-end HTML page. */
function ec_media_should_rewrite_request() {
	if ( is_admin() || is_feed() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) || defined( 'WP_CLI' ) ) {
		return false;
	}
	/**
	 * Toggle serving existing WebP siblings on the front end.
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'ec_media_serve_webp_siblings', true );
}

/** Buffer front-end HTML (inside the page cache buffer) to serve WebP siblings. */
function ec_media_start_webp_buffer() {
	if ( ec_media_should_rewrite_request() ) {
		ob_start( 'ec_media_rewrite_html_images' );
	}
}
add_action( 'template_redirect', 'ec_media_start_webp_buffer', 1 );
