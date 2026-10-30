<?php
/**
 * Data Machine Business network-density integration.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return canonical hosts for every active Extra Chill network site.
 *
 * Extra Chill Network owns active-site discovery. Mapping those IDs through
 * get_home_url() preserves WordPress's canonical domain mapping in any runtime
 * context, including main-site WP-CLI requests.
 *
 * @param string[] $network_hosts Existing host scope, replaced by canonical network state.
 * @return string[] Canonical active-site hosts.
 */
function extrachill_analytics_network_density_hosts( $network_hosts ) {
	if ( ! function_exists( 'ec_get_all_site_ids' ) ) {
		return $network_hosts;
	}

	$hosts = array();
	foreach ( ec_get_all_site_ids() as $blog_id ) {
		$url  = get_home_url( (int) $blog_id, '/' );
		$host = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';

		if ( ! is_string( $host ) ) {
			continue;
		}

		$host = strtolower( rtrim( trim( $host ), '.' ) );
		if ( '' !== $host ) {
			$hosts[] = $host;
		}
	}

	return empty( $hosts ) ? $network_hosts : array_values( array_unique( $hosts ) );
}
add_filter( 'datamachine_network_density_hosts', 'extrachill_analytics_network_density_hosts' );
