<?php
/**
 * Broken Images Check
 *
 * Counts broken images in featured images and post content.
 *
 * @package ExtraChill\SEO\Audit\Checks
 */

namespace ExtraChill\SEO\Audit\Checks;

use function ExtraChill\SEO\Audit\ec_seo_get_allowed_post_types;
use function ExtraChill\SEO\Audit\ec_seo_sql_placeholders;
use function ExtraChill\SEO\Audit\ec_seo_extract_image_urls;
use function ExtraChill\SEO\Audit\ec_seo_url_is_broken;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts broken images in the current blog context.
 *
 * Checks both featured images (missing attachment) and images in post content (404 URLs).
 *
 * @return int Number of broken images.
 */
function ec_seo_count_broken_images() {
	global $wpdb;

	$broken_count = 0;
	$allowed      = ec_seo_get_allowed_post_types();
	$placeholders = ec_seo_sql_placeholders( $allowed );

	// Check featured images with missing attachments
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$missing_featured = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_thumbnail_id'
			LEFT JOIN {$wpdb->posts} a ON m.meta_value = a.ID
			WHERE p.post_status = 'publish' 
			AND p.post_type IN ($placeholders)
			AND a.ID IS NULL",
			...$allowed
		)
	);
	$broken_count += $missing_featured;

	// Check images in post content via HTTP
	$args   = $allowed;
	$args[] = '%<img %';

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
		$urls = ec_seo_extract_image_urls( $post->post_content );

		foreach ( $urls as $url ) {
			if ( ec_seo_url_is_broken( $url ) ) {
				++$broken_count;
			}
		}
	}

	return $broken_count;
}

/**
 * Gets URLs to check for broken images in batch mode.
 *
 * @return array Array of image URLs from post content.
 */
function ec_seo_get_image_urls_to_check() {
	global $wpdb;

	$allowed      = ec_seo_get_allowed_post_types();
	$placeholders = ec_seo_sql_placeholders( $allowed );
	$urls         = array();

	$args   = $allowed;
	$args[] = '%<img %';

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
		$post_urls = ec_seo_extract_image_urls( $post->post_content );
		$urls      = array_merge( $urls, $post_urls );
	}

	return array_unique( $urls );
}

/**
 * Counts broken featured images (missing attachments) in current blog.
 *
 * @return int Number of broken featured images.
 */
function ec_seo_count_broken_featured_images() {
	global $wpdb;

	$allowed      = ec_seo_get_allowed_post_types();
	$placeholders = ec_seo_sql_placeholders( $allowed );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_thumbnail_id'
			LEFT JOIN {$wpdb->posts} a ON m.meta_value = a.ID
			WHERE p.post_status = 'publish' 
			AND p.post_type IN ($placeholders)
			AND a.ID IS NULL",
			...$allowed
		)
	);
}
