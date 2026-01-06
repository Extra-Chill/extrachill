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

/**
 * Gets broken images across all sites with pagination.
 *
 * Returns broken featured images (missing attachments) and images with broken URLs in content.
 * Does not re-check URLs - returns all images from content for manual verification.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_broken_images( $limit = 50, $offset = 0 ) {
	$blog_ids = \ExtraChill\SEO\Audit\ec_seo_get_available_blog_ids();
	$items    = array();
	$total    = 0;

	foreach ( $blog_ids as $slug => $blog_id ) {
		try {
			switch_to_blog( $blog_id );

			global $wpdb;

			$site_label   = get_bloginfo( 'name' );
			$allowed      = ec_seo_get_allowed_post_types();
			$placeholders = ec_seo_sql_placeholders( $allowed );

			// Get broken featured images (missing attachments)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$broken_featured = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, m.meta_value as thumbnail_id FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_thumbnail_id'
					LEFT JOIN {$wpdb->posts} a ON m.meta_value = a.ID
					WHERE p.post_status = 'publish' 
					AND p.post_type IN ($placeholders)
					AND a.ID IS NULL
					ORDER BY p.post_date DESC",
					...$allowed
				)
			);

			$total += count( $broken_featured );

			foreach ( $broken_featured as $post ) {
				$items[] = array(
					'blog_id'      => $blog_id,
					'site_label'   => $site_label,
					'post_id'      => $post->ID,
					'post_title'   => $post->post_title,
					'image_url'    => '(Missing featured image - ID: ' . $post->thumbnail_id . ')',
					'issue_type'   => 'missing_featured',
					'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
				);
			}

			// Get images from content that may be broken
			$args   = $allowed;
			$args[] = '%<img %';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					WHERE post_status = 'publish'
					AND post_type IN ($placeholders)
					AND post_content LIKE %s
					ORDER BY post_date DESC",
					...$args
				)
			);

			foreach ( $posts as $post ) {
				$urls = ec_seo_extract_image_urls( $post->post_content );

				foreach ( $urls as $url ) {
					$items[] = array(
						'blog_id'      => $blog_id,
						'site_label'   => $site_label,
						'post_id'      => $post->ID,
						'post_title'   => $post->post_title,
						'image_url'    => $url,
						'issue_type'   => 'content_image',
						'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
					);
					++$total;
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	$paginated_items = array_slice( $items, $offset, $limit );

	return array(
		'total' => $total,
		'items' => $paginated_items,
	);
}
