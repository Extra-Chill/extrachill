<?php
/**
 * Missing Alt Text Check
 *
 * Counts images attached to published posts that are missing alt text.
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
 * Counts images missing alt text in the current blog context.
 *
 * Only counts images that are attached to published posts of allowed types.
 *
 * @return int Number of images without alt text.
 */
function ec_seo_count_missing_alt_text() {
	global $wpdb;

	$allowed      = ec_seo_get_allowed_post_types();
	$placeholders = ec_seo_sql_placeholders( $allowed );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT a.ID) 
			FROM {$wpdb->posts} a
			INNER JOIN {$wpdb->posts} p ON a.post_parent = p.ID
			LEFT JOIN {$wpdb->postmeta} m ON a.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt'
			WHERE a.post_type = 'attachment'
			AND a.post_mime_type LIKE 'image/%'
			AND p.post_status = 'publish'
			AND p.post_type IN ($placeholders)
			AND (m.meta_value = '' OR m.meta_value IS NULL)",
			...$allowed
		)
	);

	return (int) $count;
}

/**
 * Gets images missing alt text across all sites with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_missing_alt_text( $limit = 50, $offset = 0 ) {
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
					"SELECT COUNT(DISTINCT a.ID) 
					FROM {$wpdb->posts} a
					INNER JOIN {$wpdb->posts} p ON a.post_parent = p.ID
					LEFT JOIN {$wpdb->postmeta} m ON a.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt'
					WHERE a.post_type = 'attachment'
					AND a.post_mime_type LIKE 'image/%'
					AND p.post_status = 'publish'
					AND p.post_type IN ($placeholders)
					AND (m.meta_value = '' OR m.meta_value IS NULL)",
					...$allowed
				)
			);

			$total += $site_total;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$images = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT a.ID, a.post_title as filename, a.guid, p.ID as parent_id, p.post_title as parent_title
					FROM {$wpdb->posts} a
					INNER JOIN {$wpdb->posts} p ON a.post_parent = p.ID
					LEFT JOIN {$wpdb->postmeta} m ON a.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt'
					WHERE a.post_type = 'attachment'
					AND a.post_mime_type LIKE 'image/%'
					AND p.post_status = 'publish'
					AND p.post_type IN ($placeholders)
					AND (m.meta_value = '' OR m.meta_value IS NULL)
					ORDER BY a.post_date DESC",
					...$allowed
				)
			);

			foreach ( $images as $image ) {
				$items[] = array(
					'blog_id'      => $blog_id,
					'site_label'   => $site_label,
					'image_id'     => $image->ID,
					'filename'     => $image->filename ?: basename( $image->guid ),
					'parent_id'    => $image->parent_id,
					'parent_title' => $image->parent_title,
					'edit_url'     => get_edit_post_link( $image->ID, 'raw' ),
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
