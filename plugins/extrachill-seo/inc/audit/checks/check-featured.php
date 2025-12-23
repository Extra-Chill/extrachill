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
