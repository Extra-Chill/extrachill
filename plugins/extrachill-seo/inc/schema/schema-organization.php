<?php
/**
 * Organization Schema
 *
 * Outputs Organization schema with sameAs social profiles.
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
 * Add Organization schema to graph
 */
add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		$base_url = ec_seo_get_schema_organization_base_url();
		$org_data = ec_seo_get_organization_data();

		$organization = array(
			'@type'        => 'Organization',
			'@id'          => $base_url . '/#organization',
			'name'         => $org_data['name'],
			'url'          => $org_data['url'],
			'logo'         => array(
				'@type'      => 'ImageObject',
				'@id'        => $base_url . '/#logo',
				'url'        => $org_data['logo'],
				'contentUrl' => $org_data['logo'],
				'caption'    => $org_data['name'],
				'inLanguage' => 'en-US',
			),
			'image'        => array(
				'@id' => $base_url . '/#logo',
			),
			'description'  => $org_data['description'],
			'foundingDate' => $org_data['founding_date'],
			'founder'      => array(
				'@type' => 'Person',
				'name'  => $org_data['founder'],
			),
			'sameAs'       => $org_data['same_as'],
		);

		$graph[] = $organization;

		return $graph;
	}
);
