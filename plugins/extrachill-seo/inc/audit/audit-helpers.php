<?php
/**
 * Audit Helpers
 *
 * Utility functions for URL extraction, validation, and HTTP checking.
 *
 * @package ExtraChill\SEO\Audit
 */

namespace ExtraChill\SEO\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets allowed post types for the current blog context.
 *
 * Uses extrachill_get_site_post_types() from extrachill-search as the source of truth.
 * Falls back to 'post' and 'page' if the function is unavailable or blog not mapped.
 *
 * @return array Array of allowed post type slugs for current site.
 */
function ec_seo_get_allowed_post_types() {
	$blog_id = get_current_blog_id();

	if ( function_exists( 'extrachill_get_site_post_types' ) ) {
		$site_post_types = extrachill_get_site_post_types();

		if ( isset( $site_post_types[ $blog_id ] ) ) {
			return $site_post_types[ $blog_id ];
		}
	}

	return array( 'post', 'page' );
}

/**
 * Extracts all image src URLs from HTML content.
 *
 * @param string $content HTML content to parse.
 * @return array Unique array of image URLs.
 */
function ec_seo_extract_image_urls( $content ) {
	preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches );
	return array_unique( $matches[1] ?? array() );
}

/**
 * Extracts all href URLs from HTML content.
 *
 * @param string $content HTML content to parse.
 * @return array Unique array of link URLs.
 */
function ec_seo_extract_link_urls( $content ) {
	preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/', $content, $matches );
	return array_unique( $matches[1] ?? array() );
}

/**
 * Checks if a URL uses HTTP or HTTPS scheme.
 *
 * @param string $url URL to check.
 * @return bool True if HTTP/HTTPS, false otherwise.
 */
function ec_seo_is_http_url( $url ) {
	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
	return in_array( $scheme, array( 'http', 'https' ), true );
}

/**
 * Checks if a URL belongs to the multisite network.
 *
 * @param string $url             URL to check.
 * @param array  $network_domains Array of network domain strings.
 * @return bool True if URL is within the network, false otherwise.
 */
function ec_seo_is_network_url( $url, $network_domains ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	return in_array( $host, $network_domains, true );
}

/**
 * Checks if a URL returns a broken response (4xx/5xx status or error).
 *
 * @param string $url URL to check.
 * @return bool True if broken, false if accessible.
 */
function ec_seo_url_is_broken( $url ) {
	$response = wp_remote_head(
		$url,
		array(
			'timeout'     => 5,
			'redirection' => 3,
			'sslverify'   => false,
		)
	);

	if ( is_wp_error( $response ) ) {
		return true;
	}

	$code = wp_remote_retrieve_response_code( $response );
	return $code >= 400;
}

/**
 * Gets available blog IDs, filtering out unavailable sites.
 *
 * Uses ec_get_blog_ids() from extrachill-multisite as the source of truth,
 * then filters to only include sites that exist and are not deleted/archived.
 *
 * @return array Associative array of slug => blog_id for available sites.
 */
function ec_seo_get_available_blog_ids() {
	if ( ! function_exists( 'ec_get_blog_ids' ) ) {
		return array();
	}

	$available = array();

	foreach ( ec_get_blog_ids() as $slug => $blog_id ) {
		$blog_details = get_blog_details( $blog_id );

		if ( $blog_details && ! $blog_details->deleted && ! $blog_details->archived ) {
			$available[ $slug ] = $blog_id;
		}
	}

	return $available;
}

/**
 * Gets all network domains for internal link detection.
 *
 * @return array Array of domain strings.
 */
function ec_seo_get_network_domains() {
	if ( ! function_exists( 'ec_get_domain_map' ) ) {
		return array();
	}

	return array_keys( ec_get_domain_map() );
}

/**
 * Generates SQL placeholders for an array of values.
 *
 * @param array  $values Array of values.
 * @param string $type   Placeholder type (%s, %d, etc.).
 * @return string Comma-separated placeholders.
 */
function ec_seo_sql_placeholders( $values, $type = '%s' ) {
	return implode( ',', array_fill( 0, count( $values ), $type ) );
}
