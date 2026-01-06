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

/**
 * Gets posts missing excerpts across all sites with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_missing_excerpts( $limit = 50, $offset = 0 ) {
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
					"SELECT COUNT(*) FROM {$wpdb->posts} 
					WHERE post_status = 'publish' 
					AND post_type IN ($placeholders)
					AND (post_excerpt = '' OR post_excerpt IS NULL)",
					...$allowed
				)
			);

			$total += $site_total;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type FROM {$wpdb->posts} 
					WHERE post_status = 'publish' 
					AND post_type IN ($placeholders)
					AND (post_excerpt = '' OR post_excerpt IS NULL)
					ORDER BY post_date DESC",
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
