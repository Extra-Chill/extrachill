<?php
/**
 * NetworkStats global helper.
 *
 * Thin procedural wrapper around the NetworkStats engine for template/theme
 * callers that prefer a function to a class call.
 *
 * @package ExtraChillNetwork\NetworkStats
 * @since   1.19.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ec_get_network_stats' ) ) {
	/**
	 * Get cross-site network statistics.
	 *
	 * Each metric is individually cached. Unavailable sources are reported as
	 * `available => false` with `value => null` — never a fabricated zero.
	 *
	 * @param string[] $keys Metric keys to resolve (e.g.
	 *                       ['events_count','artist_profiles']). Empty = all.
	 * @return array<string,array{key:string,label:string,value:int|array|null,available:bool}>
	 */
	function ec_get_network_stats( array $keys = array() ): array {
		return \ExtraChillNetwork\NetworkStats\NetworkStats::get( $keys );
	}
}

if ( ! function_exists( 'ec_network_stats_forget' ) ) {
	/**
	 * Invalidate the cached value for a single network-stat metric.
	 *
	 * Thin procedural wrapper around NetworkStats::forget() for callers (e.g.
	 * an activity recorder in another plugin) that need to bust ONE metric's
	 * per-metric cache without flushing the rest. The next read recomputes via
	 * the provider.
	 *
	 * @param string $key Metric machine key (e.g. "online_users").
	 * @return bool True if a cached value was deleted, false otherwise.
	 */
	function ec_network_stats_forget( string $key ): bool {
		return \ExtraChillNetwork\NetworkStats\NetworkStats::forget( $key );
	}
}
