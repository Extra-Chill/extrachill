<?php
/**
 * Broken Links Check
 *
 * Counts broken internal and external links in post content.
 *
 * @package ExtraChill\SEO\Audit\Checks
 */

namespace ExtraChill\SEO\Audit\Checks;

use function ExtraChill\SEO\Audit\ec_seo_get_allowed_post_types;
use function ExtraChill\SEO\Audit\ec_seo_sql_placeholders;
use function ExtraChill\SEO\Audit\ec_seo_extract_link_urls;
use function ExtraChill\SEO\Audit\ec_seo_is_http_url;
use function ExtraChill\SEO\Audit\ec_seo_is_network_url;
use function ExtraChill\SEO\Audit\ec_seo_url_is_broken;
use function ExtraChill\SEO\Audit\ec_seo_get_network_domains;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts broken links in the current blog context.
 *
 * @param string $type Link type: 'internal' or 'external'.
 * @return int Number of broken links of specified type.
 */
function ec_seo_count_broken_links( $type = 'internal' ) {
	global $wpdb;

	$network_domains = ec_seo_get_network_domains();
	$broken_count    = 0;
	$allowed         = ec_seo_get_allowed_post_types();
	$placeholders    = ec_seo_sql_placeholders( $allowed );

	$args   = $allowed;
	$args[] = '%<a %';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$posts = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_status = 'publish'
			AND post_type IN ($placeholders)
			AND post_content LIKE %s",
			...$args
		)
	);

	foreach ( $posts as $post ) {
		$urls = ec_seo_extract_link_urls( $post->post_content );

		foreach ( $urls as $url ) {
			if ( ! ec_seo_is_http_url( $url ) ) {
				continue;
			}

			$is_internal = ec_seo_is_network_url( $url, $network_domains );

			if ( ( 'internal' === $type && $is_internal ) ||
				 ( 'external' === $type && ! $is_internal ) ) {
				if ( ec_seo_url_is_broken( $url ) ) {
					++$broken_count;
				}
			}
		}
	}

	return $broken_count;
}

/**
 * Gets URLs to check for broken links in batch mode.
 *
 * @param string $type Link type: 'internal' or 'external'.
 * @return array Array of link URLs to check.
 */
function ec_seo_get_link_urls_to_check( $type = 'internal' ) {
	global $wpdb;

	$network_domains = ec_seo_get_network_domains();
	$allowed         = ec_seo_get_allowed_post_types();
	$placeholders    = ec_seo_sql_placeholders( $allowed );
	$urls_to_check   = array();

	$args   = $allowed;
	$args[] = '%<a %';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$posts = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_status = 'publish'
			AND post_type IN ($placeholders)
			AND post_content LIKE %s",
			...$args
		)
	);

	foreach ( $posts as $post ) {
		$urls = ec_seo_extract_link_urls( $post->post_content );

		foreach ( $urls as $url ) {
			if ( ! ec_seo_is_http_url( $url ) ) {
				continue;
			}

			$is_internal = ec_seo_is_network_url( $url, $network_domains );

			if ( ( 'internal' === $type && $is_internal ) ||
				 ( 'external' === $type && ! $is_internal ) ) {
				$urls_to_check[] = $url;
			}
		}
	}

	return array_unique( $urls_to_check );
}
