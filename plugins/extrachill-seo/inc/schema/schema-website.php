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
		$site_base_url = ec_seo_get_schema_site_base_url();
		$org_base_url  = ec_seo_get_schema_organization_base_url();
		$org_data      = ec_seo_get_organization_data();

		$website = array(
			'@type'           => 'WebSite',
			'@id'             => $site_base_url . '/#website',
			'url'             => $site_base_url . '/',
			'name'            => get_bloginfo( 'name' ),
			'description'     => $org_data['description'],
			'publisher'       => array(
				'@id' => $org_base_url . '/#organization',
			),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $site_base_url . '/?s={search_term_string}',
				),
				'query-input' => 'required name=search_term_string',
			),
			'inLanguage'      => 'en-US',
		);

		$graph[] = $website;

		return $graph;
	}
);
