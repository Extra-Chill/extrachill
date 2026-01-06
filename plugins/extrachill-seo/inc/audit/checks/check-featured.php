<?php
/**
 * Missing Featured Images Check
 *
 * Counts published posts without featured images.
 *
 * @package ExtraChill\SEO\Audit\Checks
 */

namespace ExtraChill\SEO\Audit\Checks;

use function ExtraChill\SEO\Audit\ec_seo_get_allowed_post_types;
use function ExtraChill\SEO\Audit\ec_seo_sql_placeholders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts posts missing featured images in the current blog context.
 *
 * @return int Number of published posts without featured images.
 */
function ec_seo_count_missing_featured() {
	global $wpdb;

	$allowed      = ec_seo_get_allowed_post_types();
	$placeholders = ec_seo_sql_placeholders( $allowed );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_thumbnail_id'
			WHERE p.post_status = 'publish' 
			AND p.post_type IN ($placeholders)
			AND m.meta_value IS NULL",
			...$allowed
		)
	);

	return (int) $count;
}

/**
 * Gets posts missing featured images across all sites with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_missing_featured( $limit = 50, $offset = 0 ) {
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$site_total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_thumbnail_id'
					WHERE p.post_status = 'publish' 
					AND p.post_type IN ($placeholders)
					AND m.meta_value IS NULL",
					...$allowed
				)
			);

			$total += $site_total;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_type FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_thumbnail_id'
					WHERE p.post_status = 'publish' 
					AND p.post_type IN ($placeholders)
					AND m.meta_value IS NULL
					ORDER BY p.post_date DESC",
					...$allowed
				)
			);

			foreach ( $posts as $post ) {
				$items[] = array(
					'blog_id'    => $blog_id,
					'site_label' => $site_label,
					'post_id'    => $post->ID,
					'title'      => $post->post_title,
					'post_type'  => $post->post_type,
					'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
				);
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
