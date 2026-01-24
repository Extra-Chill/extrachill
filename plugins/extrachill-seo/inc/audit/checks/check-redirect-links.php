<?php
/**
 * Redirect Links Check
 *
 * Detects internal links that redirect (301/302) to different URLs.
 *
 * @package ExtraChill\SEO\Audit\Checks
 */

namespace ExtraChill\SEO\Audit\Checks;

use function ExtraChill\SEO\Audit\ec_seo_get_allowed_post_types;
use function ExtraChill\SEO\Audit\ec_seo_sql_placeholders;
use function ExtraChill\SEO\Audit\ec_seo_extract_link_urls;
use function ExtraChill\SEO\Audit\ec_seo_is_http_url;
use function ExtraChill\SEO\Audit\ec_seo_is_network_url;
use function ExtraChill\SEO\Audit\ec_seo_get_network_domains;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts redirect links in the current blog context.
 *
 * @return int Number of internal links that redirect.
 */
function ec_seo_count_redirect_links() {
	global $wpdb;

	$network_domains = ec_seo_get_network_domains();
	$redirect_count  = 0;
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

			if ( ! ec_seo_is_network_url( $url, $network_domains ) ) {
				continue;
			}

			$redirect_info = ec_seo_check_url_redirect( $url );
			if ( $redirect_info['redirects'] ) {
				++$redirect_count;
			}
		}
	}

	return $redirect_count;
}

/**
 * Gets URLs to check for redirects in batch mode.
 *
 * @return array Array of internal link URLs to check.
 */
function ec_seo_get_redirect_urls_to_check() {
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

			if ( ec_seo_is_network_url( $url, $network_domains ) ) {
				$urls_to_check[] = $url;
			}
		}
	}

	return array_unique( $urls_to_check );
}

/**
 * Checks if a URL redirects and returns redirect information.
 *
 * @param string $url URL to check.
 * @return array Redirect information with keys: redirects, status_code, final_url, hops, chain.
 */
function ec_seo_check_url_redirect( $url ) {
	$result = array(
		'redirects'   => false,
		'status_code' => 0,
		'final_url'   => $url,
		'hops'        => 0,
		'chain'       => array(),
	);

	$current_url = $url;
	$max_hops    = 10;
	$hop_count   = 0;

	while ( $hop_count < $max_hops ) {
		$response = wp_remote_head(
			$current_url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['status_code'] = 0;
			break;
		}

		$code = wp_remote_retrieve_response_code( $response );

		$result['chain'][] = array(
			'url'    => $current_url,
			'status' => $code,
		);

		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( empty( $location ) ) {
				break;
			}

			if ( 0 === strpos( $location, '/' ) ) {
				$parsed      = wp_parse_url( $current_url );
				$location    = $parsed['scheme'] . '://' . $parsed['host'] . $location;
			}

			$result['redirects']   = true;
			$result['status_code'] = $code;
			$current_url           = $location;
			++$hop_count;
		} else {
			$result['status_code'] = $code;
			$result['final_url']   = $current_url;
			break;
		}
	}

	$result['hops'] = $hop_count;

	return $result;
}

/**
 * Checks if a URL redirects (simple boolean check).
 *
 * @param string $url URL to check.
 * @return bool True if URL redirects, false otherwise.
 */
function ec_seo_url_redirects( $url ) {
	$info = ec_seo_check_url_redirect( $url );
	return $info['redirects'];
}

/**
 * Gets redirect links across all sites with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_redirect_links( $limit = 50, $offset = 0 ) {
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

					if ( ! ec_seo_is_network_url( $url, $network_domains ) ) {
						continue;
					}

					$redirect_info = ec_seo_check_url_redirect( $url );
					if ( $redirect_info['redirects'] ) {
						$items[] = array(
							'blog_id'     => $blog_id,
							'site_label'  => $site_label,
							'post_id'     => $post->ID,
							'post_title'  => $post->post_title,
							'source_url'  => $url,
							'final_url'   => $redirect_info['final_url'],
							'status_code' => $redirect_info['status_code'],
							'hops'        => $redirect_info['hops'],
							'edit_url'    => get_edit_post_link( $post->ID, 'raw' ),
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
