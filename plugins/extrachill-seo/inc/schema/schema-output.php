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
 */
add_action(
	'wp_head',
	function () {
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
	},
	10
);

/**
 * Get base URL for schema IDs
 *
 * Uses main site URL for consistent cross-site schema references.
 *
 * @return string Base URL
 */
function ec_seo_get_schema_base_url() {
	return 'https://extrachill.com';
}

/**
 * Get organization data for schema
 *
 * Hardcoded single source of truth for organization info.
 *
 * @return array Organization data
 */
function ec_seo_get_organization_data() {
	return array(
		'name'          => 'Extra Chill',
		'url'           => 'https://extrachill.com',
		'logo'          => 'https://extrachill.com/wp-content/uploads/2024/07/cropped-bigger-logo-black-1-400x400.jpeg',
		'description'   => 'Online Music Scene',
		'founding_date' => '2011',
		'founder'       => 'Chris Huber',
		'same_as'       => array(
			'https://facebook.com/extrachill',
			'https://twitter.com/extra_chill',
			'https://instagram.com/extrachill',
			'https://youtube.com/@extra-chill',
			'https://pinterest.com/extrachill',
			'https://github.com/Extra-Chill',
		),
	);
}
