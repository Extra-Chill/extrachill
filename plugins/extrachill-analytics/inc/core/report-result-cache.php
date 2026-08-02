<?php
/**
 * Bounded result caching for the expensive network analytics reports.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_REPORT_CACHE_TTL', 5 * MINUTE_IN_SECONDS );
define( 'EXTRACHILL_ANALYTICS_REPORT_CACHE_STALE_TTL', 10 * MINUTE_IN_SECONDS );

/**
 * Normalize report inputs so equivalent requests share a cache entry.
 *
 * @param mixed $value Input value.
 * @return mixed Normalized value.
 */
function extrachill_analytics_report_cache_normalize( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		ksort( $value );
	}

	foreach ( $value as $key => $item ) {
		$value[ $key ] = extrachill_analytics_report_cache_normalize( $item );
	}

	return $value;
}

/**
 * Build the network-transient key for one normalized report request.
 *
 * @param string $report Report identifier.
 * @param array  $inputs Behavior-affecting normalized inputs.
 * @return string Cache key.
 */
function extrachill_analytics_report_cache_key( $report, $inputs ) {
	$network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
	$normalized = extrachill_analytics_report_cache_normalize( $inputs );
	$digest     = hash( 'sha256', (string) wp_json_encode( $normalized ) );

	return sprintf( 'eca_report_v1_%d_%s_%s', $network_id, sanitize_key( $report ), $digest );
}

/**
 * Add the explicit freshness contract to a report result.
 *
 * @param array  $result       Report result.
 * @param int    $generated_at Unix timestamp when the report was computed.
 * @param string $cache_status miss, hit, or stale.
 * @return array Result with freshness metadata.
 */
function extrachill_analytics_report_cache_stamp( $result, $generated_at, $cache_status ) {
	$result['freshness'] = array(
		'as_of'           => isset( $result['as_of'] ) ? (string) $result['as_of'] : gmdate( 'Y-m-d H:i:s', $generated_at ),
		'generated_at'    => gmdate( 'Y-m-d H:i:s', $generated_at ),
		'fresh_until'     => gmdate( 'Y-m-d H:i:s', $generated_at + EXTRACHILL_ANALYTICS_REPORT_CACHE_TTL ),
		'max_age_seconds' => EXTRACHILL_ANALYTICS_REPORT_CACHE_TTL,
		'cache_status'    => $cache_status,
		'is_stale'        => 'stale' === $cache_status,
	);

	return $result;
}

/**
 * Read or compute one expensive analytics report.
 *
 * Fresh results live for five minutes. Write-side invalidation is deliberately
 * avoided because pageviews arrive continuously and would prevent warm reads.
 * The transient is retained for ten minutes so concurrent readers can receive
 * the prior result while one request refreshes it. Atomic lock behavior is
 * enabled only with a persistent object cache; without one, WordPress's
 * request-local cache cannot coordinate workers.
 *
 * @param string   $report  Report identifier.
 * @param array    $inputs  Behavior-affecting normalized inputs.
 * @param callable $compute Computes and returns the report array.
 * @return array Report result with freshness metadata.
 */
function extrachill_analytics_report_cache_remember( $report, $inputs, $compute ) {
	$key     = extrachill_analytics_report_cache_key( $report, $inputs );
	$payload = get_site_transient( $key );
	$now     = time();
	$valid   = is_array( $payload )
		&& isset( $payload['generated_at'], $payload['result'] )
		&& is_array( $payload['result'] );
	$age     = $valid ? max( 0, $now - (int) $payload['generated_at'] ) : PHP_INT_MAX;

	if ( $valid && $age <= EXTRACHILL_ANALYTICS_REPORT_CACHE_TTL ) {
		return extrachill_analytics_report_cache_stamp( $payload['result'], (int) $payload['generated_at'], 'hit' );
	}

	$can_lock = function_exists( 'wp_using_ext_object_cache' )
		&& wp_using_ext_object_cache()
		&& function_exists( 'wp_cache_add' )
		&& function_exists( 'wp_cache_delete' );
	$lock_key = $key . '_lock';
	$locked   = ! $can_lock || wp_cache_add( $lock_key, 1, 'extrachill_analytics_reports', 30 );

	if ( ! $locked && $valid && $age <= EXTRACHILL_ANALYTICS_REPORT_CACHE_STALE_TTL ) {
		return extrachill_analytics_report_cache_stamp( $payload['result'], (int) $payload['generated_at'], 'stale' );
	}

	if ( ! $locked ) {
		// Cold reports currently take up to ~14 seconds. Wait within that existing
		// cost envelope for the lock owner rather than multiplying the same work.
		for ( $attempt = 0; $attempt < 60; ++$attempt ) {
			usleep( 250000 );
			$payload = get_site_transient( $key );
			if ( is_array( $payload ) && isset( $payload['generated_at'], $payload['result'] ) && is_array( $payload['result'] ) ) {
				$age = max( 0, time() - (int) $payload['generated_at'] );
				if ( $age <= EXTRACHILL_ANALYTICS_REPORT_CACHE_TTL ) {
					return extrachill_analytics_report_cache_stamp( $payload['result'], (int) $payload['generated_at'], 'hit' );
				}
			}
		}
	}

	try {
		$result       = (array) call_user_func( $compute );
		$generated_at = time();
		$result       = extrachill_analytics_report_cache_stamp( $result, $generated_at, 'miss' );
		set_site_transient(
			$key,
			array(
				'generated_at' => $generated_at,
				'result'       => $result,
			),
			EXTRACHILL_ANALYTICS_REPORT_CACHE_STALE_TTL
		);

		return $result;
	} finally {
		if ( $can_lock && $locked ) {
			wp_cache_delete( $lock_key, 'extrachill_analytics_reports' );
		}
	}
}
