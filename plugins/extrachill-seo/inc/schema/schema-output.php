<?php
/**
 * Schema Output Handler
 *
 * Consolidates all schema types into a single JSON-LD @graph output.
 * Collects schema from individual schema files via filter.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output consolidated JSON-LD schema
 *
 * @return void
 */
function ec_seo_output_schema_graph() {
	$graph = array();

	// Collect schema from all registered types via filter
	$graph = apply_filters( 'extrachill_seo_schema_graph', $graph );

	if ( empty( $graph ) ) {
		return;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo "\n<!-- Schema.org JSON-LD -->\n";
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}
add_action( 'wp_head', __NAMESPACE__ . '\\ec_seo_output_schema_graph', 10 );

/**
 * Get schema base URL for the current site.
 *
 * This should match the domain the page is actually served from, so Google
 * (and other consumers) don't see a WebSite entity pointing at a different host.
 *
 * @return string Base URL
 */
function ec_seo_get_schema_site_base_url() {
	return untrailingslashit( home_url() );
}

/**
 * Get schema base URL for the global Organization.
 *
 * Extra Chill is one brand across all subdomains. Derived from the canonical
 * main-site URL (ec_get_site_url) so it survives a domain change, falling back
 * to the historical literal when the multisite helper is unavailable.
 *
 * @return string Base URL
 */
function ec_seo_get_schema_organization_base_url() {
	if ( function_exists( 'ec_get_site_url' ) ) {
		$main_url = ec_get_site_url( 'main' );
		if ( $main_url ) {
			return untrailingslashit( $main_url );
		}
	}

	return 'https://extrachill.com';
}

/**
 * Get the Organization sameAs social profile URLs.
 *
 * Pulls from the canonical `extrachill_social_links_data` registry (owned by
 * extrachill-multisite) so social profiles live in one place. Falls back to
 * the historical literal list when the registry is empty/unavailable.
 *
 * @return array List of social profile URLs.
 */
function ec_seo_get_organization_same_as() {
	$same_as = array();

	if ( has_filter( 'extrachill_social_links_data' ) ) {
		$links = apply_filters( 'extrachill_social_links_data', array() );
		if ( is_array( $links ) ) {
			foreach ( $links as $link ) {
				if ( ! empty( $link['url'] ) ) {
					$same_as[] = (string) $link['url'];
				}
			}
		}
	}

	if ( empty( $same_as ) ) {
		$same_as = array(
			'https://facebook.com/extrachill',
			'https://twitter.com/extra_chill',
			'https://instagram.com/extrachill',
			'https://youtube.com/@extra-chill',
			'https://pinterest.com/extrachill',
			'https://github.com/Extra-Chill',
		);
	}

	return array_values( array_unique( $same_as ) );
}

/**
 * Get organization data for schema.
 *
 * Single source of truth for organization identity. Values resolve in order:
 *   1. The `ec_seo_organization_data` network option (admin override).
 *   2. Derived defaults (base URL from ec_get_site_url, social URLs from the
 *      extrachill_social_links_data registry).
 *   3. Documented literal fallbacks.
 *
 * The whole array is filterable via `ec_seo_organization_data` so consumers
 * can override individual fields without editing this file.
 *
 * @return array Organization data.
 */
function ec_seo_get_organization_data() {
	$base_url = ec_seo_get_schema_organization_base_url();

	$defaults = array(
		'name'          => 'Extra Chill',
		'url'           => $base_url,
		'logo'          => $base_url . '/wp-content/uploads/2024/07/cropped-bigger-logo-black-1-400x400.jpeg',
		'description'   => 'Online Music Scene',
		'founding_date' => '2011',
		'founder'       => 'Chris Huber',
		'same_as'       => ec_seo_get_organization_same_as(),
	);

	$stored = is_multisite() ? get_site_option( 'ec_seo_organization_data', array() ) : get_option( 'ec_seo_organization_data', array() );
	if ( is_array( $stored ) && ! empty( $stored ) ) {
		$defaults = array_merge( $defaults, array_filter( $stored, static function ( $value ) {
			return null !== $value && '' !== $value;
		} ) );
	}

	/**
	 * Filter the Organization schema identity data.
	 *
	 * @param array $data Organization data (name, url, logo, description,
	 *                    founding_date, founder, same_as).
	 */
	return apply_filters( 'ec_seo_organization_data', $defaults );
}
