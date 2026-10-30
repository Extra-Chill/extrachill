<?php
/**
 * Keep upload URLs on the current blog's own host under switch_to_blog().
 *
 * WordPress defines WP_CONTENT_URL once per request from the REQUESTING site's
 * siteurl, and `_wp_upload_dir()` builds `url` / `baseurl` from that constant.
 * `switch_to_blog()` changes the upload directory on disk but not that URL
 * prefix, so a cross-site upload (a subsite request writing media to another
 * site) records the requesting site's host in the attachment GUID, REST media
 * responses, and block markup:
 *
 *     request host studio.example.com, switch_to_blog( main )
 *     => https://studio.example.com/wp-content/uploads   (wrong host)
 *
 * This filter swaps a leading WP_CONTENT_URL for the current blog's own
 * content URL. On an un-switched request the two are equal, so it is a no-op.
 * A custom `upload_url_path` (URL not built from WP_CONTENT_URL) is left
 * untouched.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite an upload URL built from one content URL onto another.
 *
 * Pure function: no WordPress state.
 *
 * @param string $url                 Upload URL (`url` or `baseurl`).
 * @param string $request_content_url Content URL the request booted with (WP_CONTENT_URL).
 * @param string $blog_content_url    Content URL of the blog the URL should belong to.
 * @return string
 */
function extrachill_network_rewrite_upload_url( string $url, string $request_content_url, string $blog_content_url ): string {
	$request_content_url = untrailingslashit( $request_content_url );
	$blog_content_url    = untrailingslashit( $blog_content_url );

	if ( '' === $url || '' === $request_content_url || '' === $blog_content_url || $request_content_url === $blog_content_url ) {
		return $url;
	}

	// Match on a path boundary so `/wp-content` never matches `/wp-content-x`.
	if ( $url !== $request_content_url && 0 !== strpos( $url, $request_content_url . '/' ) ) {
		return $url;
	}

	return $blog_content_url . substr( $url, strlen( $request_content_url ) );
}

/**
 * Content URL for the current blog, derived the way core derives
 * WP_CONTENT_URL (`siteurl . '/wp-content'`) on a request served by that blog.
 *
 * Returns '' when WP_CONTENT_URL was customized to something other than the
 * default `/wp-content` derivation; such a constant is site-agnostic by
 * design and must not be rewritten.
 *
 * @return string
 */
function extrachill_network_current_blog_content_url(): string {
	// Core builds upload URLs from the raw constant; content_url() adds scheme/filters that would break prefix matching.
	// @phpstan-ignore phpstanWP.wpConstant.fetch
	if ( ! defined( 'WP_CONTENT_URL' ) || '/wp-content' !== substr( untrailingslashit( WP_CONTENT_URL ), -11 ) ) {
		return '';
	}

	$siteurl = (string) get_option( 'siteurl' );

	return '' === $siteurl ? '' : untrailingslashit( $siteurl ) . '/wp-content';
}

/**
 * `upload_dir` filter callback.
 *
 * @param array $uploads Upload directory data.
 * @return array
 */
function extrachill_network_filter_upload_dir_host( array $uploads ): array {
	if ( ! is_multisite() || ! defined( 'WP_CONTENT_URL' ) ) {
		return $uploads;
	}

	$blog_content_url = extrachill_network_current_blog_content_url();
	if ( '' === $blog_content_url ) {
		return $uploads;
	}

	foreach ( array( 'url', 'baseurl' ) as $key ) {
		if ( isset( $uploads[ $key ] ) && is_string( $uploads[ $key ] ) ) {
			// @phpstan-ignore phpstanWP.wpConstant.fetch
			$uploads[ $key ] = extrachill_network_rewrite_upload_url( $uploads[ $key ], WP_CONTENT_URL, $blog_content_url );
		}
	}

	return $uploads;
}
add_filter( 'upload_dir', 'extrachill_network_filter_upload_dir_host' );
