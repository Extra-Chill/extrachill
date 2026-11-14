<?php
/**
 * REST 5xx counter.
 *
 * Counts REST responses with status >= 500 that WordPress itself produced, at
 * the core `rest_post_dispatch` boundary. These responses never reach
 * debug.log, so the PHP error summary and fatal alarm cannot see them.
 *
 * Storage is one bounded network option: hourly buckets keyed by
 * "route|code", capped per hour, pruned past the retention window. A request
 * that returns 5xx costs one read-modify-write of that option. Successful
 * requests cost nothing. Concurrent increments can race, so counts are a
 * lower bound, not an exact tally.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

const EC_ANALYTICS_REST_5XX_OPTION          = 'extrachill_analytics_rest_5xx';
const EC_ANALYTICS_REST_5XX_RETENTION_HOURS = 168;
const EC_ANALYTICS_REST_5XX_MAX_KEYS        = 50;
const EC_ANALYTICS_REST_5XX_OVERFLOW_KEY    = '_other|_other';

add_filter( 'rest_post_dispatch', 'extrachill_analytics_rest_5xx_observe', 999, 3 );

/**
 * Observe one dispatched REST response; never alters it.
 *
 * @param WP_HTTP_Response|mixed $response Response.
 * @param WP_REST_Server|mixed   $server   Server.
 * @param WP_REST_Request|mixed  $request  Request.
 * @return mixed Unchanged response.
 */
function extrachill_analytics_rest_5xx_observe( $response, $server, $request ) {
	unset( $server );
	if ( ! $response instanceof WP_HTTP_Response || (int) $response->get_status() < 500 ) {
		return $response;
	}
	extrachill_analytics_rest_5xx_record(
		extrachill_analytics_rest_5xx_route_key( $response, $request ),
		extrachill_analytics_rest_5xx_error_code( $response ),
		time()
	);
	return $response;
}

/**
 * Resolve a bounded route key: the matched route pattern, never the raw URL.
 *
 * Abilities share one pattern, so the registered ability name (a bounded,
 * code-defined set) is appended for the core abilities run route.
 *
 * @param WP_HTTP_Response      $response Response.
 * @param WP_REST_Request|mixed $request  Request.
 * @return string Route key.
 */
function extrachill_analytics_rest_5xx_route_key( $response, $request ) {
	$route = $response instanceof WP_REST_Response ? (string) $response->get_matched_route() : '';
	if ( '' === $route ) {
		$route = '(unmatched)';
	}
	if ( 0 === strpos( $route, '/wp-abilities/' ) && $request instanceof WP_REST_Request ) {
		$params = $request->get_url_params();
		$name   = isset( $params['name'] ) ? (string) $params['name'] : '';
		if ( '' !== $name && function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			$route .= ' [' . $name . ']';
		}
	}
	return substr( $route, 0, 200 );
}

/**
 * Resolve the error code of a 5xx response, or "-" when it carries none.
 *
 * @param WP_HTTP_Response $response Response.
 * @return string Error code.
 */
function extrachill_analytics_rest_5xx_error_code( $response ) {
	$data = $response->get_data();
	$code = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '-';
	return substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', $code ), 0, 64 );
}

/**
 * Increment one bounded hourly counter.
 *
 * @param string $route     Route key.
 * @param string $code      Error code.
 * @param int    $timestamp Unix time of the response.
 * @return void
 */
function extrachill_analytics_rest_5xx_record( $route, $code, $timestamp ) {
	$buckets = extrachill_analytics_rest_5xx_add( extrachill_analytics_rest_5xx_buckets(), $route, $code, $timestamp );
	update_site_option( EC_ANALYTICS_REST_5XX_OPTION, $buckets );
}

/**
 * Pure increment: add one hit, enforce the key cap, prune old hours.
 *
 * @param array  $buckets   Hour => ( key => count ).
 * @param string $route     Route key.
 * @param string $code      Error code.
 * @param int    $timestamp Unix time.
 * @return array Updated buckets.
 */
function extrachill_analytics_rest_5xx_add( array $buckets, $route, $code, $timestamp ) {
	$hour = (int) ( floor( $timestamp / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
	$key  = $route . '|' . $code;
	$slot = isset( $buckets[ $hour ] ) && is_array( $buckets[ $hour ] ) ? $buckets[ $hour ] : array();
	// The overflow key occupies the last slot, so an hour never exceeds the cap.
	$named = count( $slot ) - ( isset( $slot[ EC_ANALYTICS_REST_5XX_OVERFLOW_KEY ] ) ? 1 : 0 );
	if ( ! isset( $slot[ $key ] ) && $named >= EC_ANALYTICS_REST_5XX_MAX_KEYS - 1 ) {
		$key = EC_ANALYTICS_REST_5XX_OVERFLOW_KEY;
	}
	$slot[ $key ]     = (int) ( $slot[ $key ] ?? 0 ) + 1;
	$buckets[ $hour ] = $slot;

	$cutoff = $hour - EC_ANALYTICS_REST_5XX_RETENTION_HOURS * HOUR_IN_SECONDS;
	foreach ( array_keys( $buckets ) as $bucket_hour ) {
		if ( (int) $bucket_hour <= $cutoff ) {
			unset( $buckets[ $bucket_hour ] );
		}
	}
	ksort( $buckets );
	return $buckets;
}

/**
 * Read stored buckets.
 *
 * @return array Hour => ( key => count ).
 */
function extrachill_analytics_rest_5xx_buckets() {
	$value = get_site_option( EC_ANALYTICS_REST_5XX_OPTION, array() );
	return is_array( $value ) ? $value : array();
}

/**
 * Summarize buckets over a trailing window.
 *
 * @param array $buckets Hour => ( key => count ).
 * @param int   $hours   Trailing window in hours (capped at retention).
 * @param int   $now     Unix time.
 * @param int   $limit   Max rows; 0 for all.
 * @return array Summary.
 */
function extrachill_analytics_rest_5xx_summarize( array $buckets, $hours, $now, $limit = 10 ) {
	$hours  = min( EC_ANALYTICS_REST_5XX_RETENTION_HOURS, max( 1, (int) $hours ) );
	$cutoff = (int) ( floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS ) - ( $hours - 1 ) * HOUR_IN_SECONDS;
	$totals = array();
	$peak   = array();
	foreach ( $buckets as $hour => $slot ) {
		if ( (int) $hour < $cutoff || ! is_array( $slot ) ) {
			continue;
		}
		foreach ( $slot as $key => $count ) {
			$totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + (int) $count;
			$peak[ $key ]   = max( (int) ( $peak[ $key ] ?? 0 ), (int) $count );
		}
	}
	arsort( $totals );
	$total = array_sum( $totals );
	$rows  = array();
	foreach ( $totals as $key => $count ) {
		$parts  = explode( '|', (string) $key );
		$code   = array_pop( $parts );
		$rows[] = array(
			'route'         => implode( '|', $parts ),
			'code'          => $code,
			'count'         => (int) $count,
			'per_day'       => round( $count * 24 / $hours, 1 ),
			'peak_per_hour' => (int) $peak[ $key ],
		);
	}
	$distinct = count( $rows );
	if ( $limit > 0 ) {
		$rows = array_slice( $rows, 0, $limit );
	}

	return array(
		'total'               => (int) $total,
		'per_day'             => round( $total * 24 / $hours, 1 ),
		'window_hours'        => $hours,
		'retention_hours'     => EC_ANALYTICS_REST_5XX_RETENTION_HOURS,
		'distinct_signatures' => $distinct,
		'top'                 => $rows[0] ?? null,
		'rows'                => $rows,
		'source'              => 'rest_post_dispatch',
		'note'                => 'Counts REST responses with status >= 500 produced by WordPress. Concurrent increments can race, so counts are a lower bound. nginx 502/504 from PHP-FPM saturation never reach WordPress and are not counted.',
	);
}
