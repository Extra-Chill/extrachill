<?php
/**
 * WebSite Schema
 *
 * Outputs WebSite schema with SearchAction for sitelinks search box.
 * Included on all pages.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add WebSite schema to graph
 */
add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		$base_url = ec_seo_get_schema_base_url();
		$org_data = ec_seo_get_organization_data();

		$website = array(
			'@type'           => 'WebSite',
			'@id'             => $base_url . '/#website',
			'url'             => $base_url . '/',
			'name'            => $org_data['name'],
			'description'     => $org_data['description'],
			'publisher'       => array(
				'@id' => $base_url . '/#organization',
			),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $base_url . '/?s={search_term_string}',
				),
				'query-input' => 'required name=search_term_string',
			),
			'inLanguage'      => 'en-US',
		);

		$graph[] = $website;

		return $graph;
	}
);
