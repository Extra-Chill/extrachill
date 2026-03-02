<?php
/**
 * Custom Sitemap Provider
 *
 * Extends WordPress core sitemaps with a custom provider that collects
 * URLs from plugins via the extrachill_seo_sitemap_urls filter.
 *
 * This lets plugins like extrachill-events register discovery page URLs
 * without needing their own sitemap implementation.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register our custom provider into the sitemap index.
 *
 * Uses wp_loaded hook to call wp_register_sitemap_provider() after the
 * sitemaps server has been initialized (init priority 10). The function
 * internally calls wp_sitemaps_get_server() which returns the already-
 * initialized server, so the provider is added to the existing registry.
 *
 * @hook wp_loaded
 */
add_action(
	'wp_loaded',
	function () {
		\wp_register_sitemap_provider( 'extrachill', new EC_Sitemaps_Custom_Provider() );
	}
);

/**
 * Custom sitemap provider for plugin-registered URLs.
 *
 * Collects URLs from the extrachill_seo_sitemap_urls filter and
 * serves them as a sitemap within the WordPress core sitemap index.
 */
class EC_Sitemaps_Custom_Provider extends \WP_Sitemaps_Provider {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name        = 'extrachill';
		$this->object_type = 'extrachill';
	}

	/**
	 * Get the list of URLs for the sitemap.
	 *
	 * @param int    $page_num Page number (1-indexed).
	 * @param string $subtype  Optional subtype.
	 * @return array[] Array of URL entry arrays.
	 */
	public function get_url_list( $page_num, $subtype = '' ) {
		$urls = $this->get_all_urls();

		if ( empty( $urls ) ) {
			return array();
		}

		// WordPress core sitemaps paginate at 2000 URLs per page.
		$offset = ( $page_num - 1 ) * wp_sitemaps_get_max_urls( $this->object_type );
		$length = wp_sitemaps_get_max_urls( $this->object_type );

		return array_slice( $urls, $offset, $length );
	}

	/**
	 * Get the max number of pages for the sitemap.
	 *
	 * @param string $subtype Optional subtype.
	 * @return int Number of pages.
	 */
	public function get_max_num_pages( $subtype = '' ) {
		$urls = $this->get_all_urls();

		if ( empty( $urls ) ) {
			return 0;
		}

		$max_urls = wp_sitemaps_get_max_urls( $this->object_type );

		return (int) ceil( count( $urls ) / $max_urls );
	}

	/**
	 * Collect all URLs from plugins via filter.
	 *
	 * Each URL entry should be an array with at least a 'loc' key:
	 *   [
	 *       'loc'     => 'https://example.com/page/',      // Required.
	 *       'lastmod' => '2026-03-01T00:00:00+00:00',      // Optional.
	 *   ]
	 *
	 * @return array[] Array of URL entry arrays.
	 */
	private function get_all_urls() {
		/**
		 * Filter to register custom sitemap URLs.
		 *
		 * Plugins should append URL entries to the array. Each entry
		 * must have a 'loc' key with the full URL. Optional 'lastmod'
		 * key for last modification date in W3C format.
		 *
		 * @param array[] $urls Array of URL entry arrays.
		 */
		$urls = apply_filters( 'extrachill_seo_sitemap_urls', array() );

		// Validate entries.
		return array_filter(
			$urls,
			function ( $entry ) {
				return is_array( $entry ) && ! empty( $entry['loc'] );
			}
		);
	}
}
