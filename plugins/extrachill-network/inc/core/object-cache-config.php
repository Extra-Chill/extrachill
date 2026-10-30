<?php
/**
 * Object Cache Pro Configuration
 *
 * Configures Object Cache Pro for optimal performance with the network's plugins.
 * Uses the objectcache_config filter to ensure settings persist across updates.
 *
 * @package ExtraChillNetwork
 * @since 1.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'objectcache_config', 'extrachill_object_cache_config' );

/**
 * Configure non-prefetchable cache groups for Object Cache Pro
 *
 * Dynamic cache keys (like coauthors_post_{id}) cannot be efficiently prefetched.
 * This prevents log spam from Object Cache Pro's prefetching optimization.
 *
 * @param array $config Object Cache Pro configuration array.
 * @return array Modified configuration.
 */
function extrachill_object_cache_config( $config ) {
	$config['non_prefetchable_groups'] = array_merge(
		$config['non_prefetchable_groups'] ?? array(),
		array( 'co-authors-plus' )
	);
	return $config;
}
