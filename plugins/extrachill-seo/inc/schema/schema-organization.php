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
 * Get network sites for schema subOrganization
 *
 * Derives site data from canonical blog-ids.php source.
 * Skips main site (parent Organization) and unpublished sites.
 *
 * @return array Network sites with name, url, description
 */
function ec_seo_get_network_sites() {
	if ( ! function_exists( 'ec_get_blog_ids' ) || ! function_exists( 'ec_get_site_url' ) ) {
		return array();
	}

	$blog_ids = ec_get_blog_ids();
	$sites    = array();

	foreach ( $blog_ids as $slug => $blog_id ) {
		// Skip main site (it's the parent Organization, not a subOrganization)
		if ( $slug === 'main' ) {
			continue;
		}

		$blog_details = get_blog_details( $blog_id );

		// Skip if site doesn't exist or is archived/deleted
		if ( ! $blog_details || $blog_details->archived || $blog_details->deleted ) {
			continue;
		}

		$url = ec_get_site_url( $slug );
		if ( ! $url ) {
			continue;
		}

		$sites[] = array(
			'name'        => $blog_details->blogname,
			'url'         => $url,
			'description' => get_blog_option( $blog_id, 'blogdescription' ),
		);
	}

	return $sites;
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

		// Add network sites as subOrganizations
		$network_sites = ec_seo_get_network_sites();

		if ( ! empty( $network_sites ) ) {
			$sub_organizations = array();

			foreach ( $network_sites as $site ) {
				$sub_org = array(
					'@type' => 'WebSite',
					'name'  => $site['name'],
					'url'   => $site['url'],
				);

				// Only include description if not empty
				if ( ! empty( $site['description'] ) ) {
					$sub_org['description'] = $site['description'];
				}

				$sub_organizations[] = $sub_org;
			}

			$organization['subOrganization'] = $sub_organizations;
		}

		$graph[] = $organization;

		return $graph;
	}
);
