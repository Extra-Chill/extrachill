<?php
/**
 * Network rate-limit contract fixture for the isolated analytics harness.
 *
 * Kept apart from the base test case so that file holds only its class.
 *
 * @package ExtraChill\Analytics
 */

if ( ! function_exists( 'extrachill_rate_limit_admit' ) ) {
	/**
	 * Network rate-limit contract fixture for the isolated analytics harness.
	 *
	 * Production loads extrachill-network/inc/core/rate-limit.php; this suite
	 * runs without sibling plugins, so it mirrors that contract: fail closed
	 * without a persistent cache, otherwise true or retry-after seconds.
	 *
	 * @param string $key            Limiter key.
	 * @param int    $limit          Requests allowed per window.
	 * @param int    $window_seconds Window length.
	 * @return true|int|WP_Error
	 */
	function extrachill_rate_limit_admit( string $key, int $limit, int $window_seconds ) {
		if ( ! wp_using_ext_object_cache() ) {
			return new WP_Error( 'rate_limiter_unavailable', 'Request admission is temporarily unavailable.', array( 'status' => 503 ) );
		}
		$window_seconds = max( 1, $window_seconds );
		$start          = intdiv( time(), $window_seconds ) * $window_seconds;
		$window_key     = hash( 'sha256', $key ) . ':' . $window_seconds . ':' . $start;
		$count          = wp_cache_add( $window_key, 1, 'extrachill_rate_limits', $window_seconds ) ? 1 : wp_cache_incr( $window_key, 1, 'extrachill_rate_limits' );
		if ( false === $count ) {
			return new WP_Error( 'rate_limiter_unavailable', 'Request admission is temporarily unavailable.', array( 'status' => 503 ) );
		}
		return (int) $count <= $limit ? true : $start + $window_seconds - time();
	}
}
