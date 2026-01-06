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

/**
 * Gets broken links across all sites with pagination.
 *
 * Returns links from content for manual verification. Does not re-check URLs.
 *
 * @param string $type   Link type: 'internal' or 'external'.
 * @param int    $limit  Number of items to return.
 * @param int    $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_broken_links( $type = 'internal', $limit = 50, $offset = 0 ) {
	$blog_ids        = \ExtraChill\SEO\Audit\ec_seo_get_available_blog_ids();
	$network_domains = \ExtraChill\SEO\Audit\ec_seo_get_network_domains();
	$items           = array();
	$total           = 0;

	foreach ( $blog_ids as $slug => $blog_id ) {
		try {
			switch_to_blog( $blog_id );

			global $wpdb;

			$site_label   = get_bloginfo( 'name' );
			$allowed      = ec_seo_get_allowed_post_types();
			$placeholders = ec_seo_sql_placeholders( $allowed );

			$args   = $allowed;
			$args[] = '%<a %';

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
				$urls = ec_seo_extract_link_urls( $post->post_content );

				foreach ( $urls as $url ) {
					if ( ! ec_seo_is_http_url( $url ) ) {
						continue;
					}

					$is_internal = ec_seo_is_network_url( $url, $network_domains );

					if ( ( 'internal' === $type && $is_internal ) ||
						 ( 'external' === $type && ! $is_internal ) ) {
						$items[] = array(
							'blog_id'    => $blog_id,
							'site_label' => $site_label,
							'post_id'    => $post->ID,
							'post_title' => $post->post_title,
							'link_url'   => $url,
							'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
						);
						++$total;
					}
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
