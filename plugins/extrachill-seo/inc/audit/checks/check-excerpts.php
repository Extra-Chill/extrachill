<?php
/**
 * Missing Excerpts Check
 *
 * Counts published posts without excerpts (poor meta descriptions).
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
 * Counts posts missing excerpts in the current blog context.
 *
 * @return int Number of published posts without excerpts.
 */
function ec_seo_count_missing_excerpts() {
	global $wpdb;

	$allowed      = ec_seo_get_allowed_post_types();
	$placeholders = ec_seo_sql_placeholders( $allowed );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} 
			WHERE post_status = 'publish' 
			AND post_type IN ($placeholders)
			AND (post_excerpt = '' OR post_excerpt IS NULL)",
			...$allowed
		)
	);

	return (int) $count;
}
