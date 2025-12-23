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
