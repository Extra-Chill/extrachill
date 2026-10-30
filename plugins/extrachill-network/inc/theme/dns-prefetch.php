<?php
/**
 * DNS Prefetch Domains
 *
 * Registers Extra Chill-specific domains for DNS prefetching.
 *
 * @package ExtraChill_Network
 * @since 1.0.0
 */

/**
 * Add Mediavine scripts domain for DNS prefetching.
 *
 * @param array $domains Existing DNS prefetch domains.
 * @return array Modified domains array.
 */
function extrachill_network_dns_prefetch_domains( $domains ) {
	$domains[] = '//scripts.mediavine.com';
	return $domains;
}
add_filter( 'extrachill_dns_prefetch_domains', 'extrachill_network_dns_prefetch_domains' );
