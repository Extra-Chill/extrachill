<?php
/**
 * Bounded service assertions for cross-site REST requests.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EC_CROSS_SITE_SERVICE_ASSERTION_VERSION     = '1';
const EC_CROSS_SITE_SERVICE_ASSERTION_MAX_TTL     = 60;
const EC_CROSS_SITE_SERVICE_ASSERTION_FUTURE_SKEW = 5;
const EC_CROSS_SITE_SERVICE_ASSERTION_REPLAY_HOOK = 'ec_cross_site_service_assertion_delete_replay';

/**
 * Return the complete assertion header map.
 *
 * @return array<string, string> Claim name to HTTP header name.
 */
function ec_cross_site_service_assertion_headers(): array {
	return array(
		'version'        => 'X-EC-Service-Version',
		'service_id'     => 'X-EC-Service-ID',
		'key_id'         => 'X-EC-Service-Key-ID',
		'scope'          => 'X-EC-Service-Scope',
		'source_site_id' => 'X-EC-Service-Source-Site',
		'target_site_id' => 'X-EC-Service-Target-Site',
		'target_host'    => 'X-EC-Service-Target-Host',
		'issued_at'      => 'X-EC-Service-Issued-At',
		'expires_at'     => 'X-EC-Service-Expires-At',
		'nonce'          => 'X-EC-Service-Nonce',
		'signature'      => 'X-EC-Service-Signature',
	);
}

/**
 * Recursively sort associative request data while preserving list order.
 *
 * @param mixed $value Request data.
 * @return mixed Canonical data.
 */
function ec_cross_site_service_assertion_normalize_data( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
	if ( ! $is_list ) {
		ksort( $value, SORT_STRING );
	}

	foreach ( $value as $key => $item ) {
		$value[ $key ] = ec_cross_site_service_assertion_normalize_data( $item );
	}

	return $value;
}

/**
 * Canonicalize query data exactly as the HTTP helper transmits it.
 *
 * @param array $query Query parameters.
 * @return array Canonical query parameters.
 */
function ec_cross_site_service_assertion_canonical_query( array $query ): array {
	$canonical = array();
	parse_str( http_build_query( $query ), $canonical );

	return ec_cross_site_service_assertion_normalize_data( $canonical );
}

/**
 * Hash a canonical request value.
 *
 * @param mixed $value Request value.
 * @return string SHA-256 digest.
 */
function ec_cross_site_service_assertion_digest( $value ): string {
	return hash( 'sha256', (string) wp_json_encode( ec_cross_site_service_assertion_normalize_data( $value ) ) );
}

/**
 * Return the parsed body representation used by the HTTP transport.
 *
 * @param WP_REST_Request $request REST request.
 * @return mixed Parsed body or raw body when it is not valid JSON.
 */
function ec_cross_site_service_assertion_request_body( WP_REST_Request $request ) {
	if ( ! in_array( strtoupper( $request->get_method() ), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
		return array();
	}

	$raw = $request->get_body();
	if ( '' === $raw ) {
		return array();
	}

	$decoded = json_decode( $raw, true );

	return JSON_ERROR_NONE === json_last_error() ? $decoded : $raw;
}

/**
 * Return configuration registered for one side of the transport.
 *
 * Each grant is an array containing service_id, scope, source_site_id,
 * target_site_id, target_host, method, route, keys, and (for source grants)
 * active_key_id. Keys map opaque key IDs to secrets of at least 32 bytes.
 *
 * @param string $side Either source or target.
 * @return array<int|string, array<string, mixed>> Registered grants.
 */
function ec_cross_site_service_assertion_grants( string $side ): array {
	$hook   = 'source' === $side
		? 'ec_cross_site_service_assertion_source_grants'
		: 'ec_cross_site_service_assertion_target_grants';
	$grants = apply_filters( $hook, array() );

	return is_array( $grants ) ? $grants : array();
}

/**
 * Validate and normalize a configured grant.
 *
 * @param mixed $grant Registered grant.
 * @return array<string, mixed>|null Normalized grant, or null when invalid.
 */
function ec_cross_site_service_assertion_normalize_grant( $grant ): ?array {
	if ( ! is_array( $grant ) ) {
		return null;
	}

	$required = array( 'service_id', 'scope', 'source_site_id', 'target_site_id', 'target_host', 'method', 'route', 'keys' );
	foreach ( $required as $field ) {
		if ( ! array_key_exists( $field, $grant ) ) {
			return null;
		}
	}

	$service_id     = (string) $grant['service_id'];
	$scope          = (string) $grant['scope'];
	$source_site_id = filter_var( $grant['source_site_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	$target_site_id = filter_var( $grant['target_site_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	$target_host    = strtolower( trim( (string) $grant['target_host'] ) );
	$method         = strtoupper( trim( (string) $grant['method'] ) );
	$route          = (string) $grant['route'];
	$keys           = array();

	if (
		1 !== preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $service_id )
		|| 1 !== preg_match( '#^[A-Za-z0-9._:/-]{1,128}$#', $scope )
		|| false === $source_site_id
		|| false === $target_site_id
		|| ! filter_var( $target_host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME )
		|| ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true )
		|| 1 !== preg_match( '#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]+$#', $route )
		|| ! is_array( $grant['keys'] )
	) {
		return null;
	}

	foreach ( $grant['keys'] as $key_id => $secret ) {
		if (
			! is_string( $key_id )
			|| 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $key_id )
			|| ! is_string( $secret )
			|| strlen( $secret ) < 32
		) {
			continue;
		}
		$keys[ $key_id ] = $secret;
	}

	if ( empty( $keys ) ) {
		return null;
	}

	return array(
		'service_id'     => $service_id,
		'scope'          => $scope,
		'source_site_id' => (int) $source_site_id,
		'target_site_id' => (int) $target_site_id,
		'target_host'    => $target_host,
		'method'         => $method,
		'route'          => $route,
		'keys'           => $keys,
		'active_key_id'  => isset( $grant['active_key_id'] ) ? (string) $grant['active_key_id'] : '',
	);
}

/**
 * Find an exact configured grant without exposing configuration values.
 *
 * @param string               $side     Source or target.
 * @param array<string, mixed> $criteria Exact non-secret grant fields.
 * @return array<string, mixed>|null Matching normalized grant.
 */
function ec_cross_site_service_assertion_find_grant( string $side, array $criteria ): ?array {
	foreach ( ec_cross_site_service_assertion_grants( $side ) as $candidate ) {
		$grant = ec_cross_site_service_assertion_normalize_grant( $candidate );
		if ( null === $grant ) {
			continue;
		}

		$matches = true;
		foreach ( $criteria as $field => $value ) {
			if ( ! array_key_exists( $field, $grant ) || $grant[ $field ] !== $value ) {
				$matches = false;
				break;
			}
		}
		if ( $matches ) {
			return $grant;
		}
	}

	return null;
}

/**
 * Build the canonical signed payload.
 *
 * @param array<string, mixed> $claims       Normalized claims.
 * @param string               $method       HTTP method.
 * @param string               $route        Exact REST route.
 * @param string               $query_digest Canonical query digest.
 * @param string               $body_digest  Canonical body digest.
 * @return string Canonical JSON payload.
 */
function ec_cross_site_service_assertion_payload( array $claims, string $method, string $route, string $query_digest, string $body_digest ): string {
	return (string) wp_json_encode(
		array(
			'version'        => (string) $claims['version'],
			'service_id'     => (string) $claims['service_id'],
			'key_id'         => (string) $claims['key_id'],
			'scope'          => (string) $claims['scope'],
			'source_site_id' => (int) $claims['source_site_id'],
			'target_site_id' => (int) $claims['target_site_id'],
			'target_host'    => strtolower( (string) $claims['target_host'] ),
			'method'         => strtoupper( $method ),
			'route'          => $route,
			'query_digest'   => $query_digest,
			'body_digest'    => $body_digest,
			'issued_at'      => (int) $claims['issued_at'],
			'expires_at'     => (int) $claims['expires_at'],
			'nonce'          => (string) $claims['nonce'],
		),
		JSON_UNESCAPED_SLASHES
	);
}

/**
 * Mint headers for one configured cross-site operation.
 *
 * @param string $site_key   Target site key understood by the network resolver.
 * @param string $method     HTTP method.
 * @param string $path       Full or namespace-relative REST path.
 * @param array  $args       Cross-site request arguments.
 * @param string $service_id Opaque service ID.
 * @param string $scope      Opaque operation scope.
 * @return array<string, string>|WP_Error Assertion headers or a generic error.
 */
function ec_cross_site_build_service_assertion_headers( string $site_key, string $method, string $path, array $args, string $service_id, string $scope ) {
	$target_url     = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( $site_key ) : '';
	$target_site_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $site_key ) : null;
	$target_host    = $target_url ? wp_parse_url( $target_url, PHP_URL_HOST ) : '';
	$route          = ec_cross_site_rest_resolve_route( $path );
	$method         = strtoupper( $method );
	$source_site_id = (int) get_current_blog_id();

	if ( ! is_string( $target_host ) || '' === $target_host || ! $target_site_id ) {
		return new WP_Error( 'ec_service_assertion_denied', 'Service assertion could not be created.', array( 'status' => 403 ) );
	}

	$grant = ec_cross_site_service_assertion_find_grant(
		'source',
		array(
			'service_id'     => $service_id,
			'scope'          => $scope,
			'source_site_id' => $source_site_id,
			'target_site_id' => (int) $target_site_id,
			'target_host'    => strtolower( $target_host ),
			'method'         => $method,
			'route'          => $route,
		)
	);
	if ( null === $grant || '' === $grant['active_key_id'] || ! isset( $grant['keys'][ $grant['active_key_id'] ] ) ) {
		return new WP_Error( 'ec_service_assertion_denied', 'Service assertion could not be created.', array( 'status' => 403 ) );
	}

	try {
		$nonce = bin2hex( random_bytes( 16 ) );
	} catch ( Throwable $error ) {
		return new WP_Error( 'ec_service_assertion_unavailable', 'Service assertion is unavailable.', array( 'status' => 503 ) );
	}

	$issued_at = time();
	$claims    = array(
		'version'        => EC_CROSS_SITE_SERVICE_ASSERTION_VERSION,
		'service_id'     => $service_id,
		'key_id'         => $grant['active_key_id'],
		'scope'          => $scope,
		'source_site_id' => $source_site_id,
		'target_site_id' => (int) $target_site_id,
		'target_host'    => strtolower( $target_host ),
		'issued_at'      => $issued_at,
		'expires_at'     => $issued_at + EC_CROSS_SITE_SERVICE_ASSERTION_MAX_TTL,
		'nonce'          => $nonce,
	);
	$query     = isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array();
	$body      = isset( $args['body'] ) ? $args['body'] : array();
	$payload   = ec_cross_site_service_assertion_payload(
		$claims,
		$method,
		$route,
		ec_cross_site_service_assertion_digest( ec_cross_site_service_assertion_canonical_query( $query ) ),
		ec_cross_site_service_assertion_digest( $body )
	);
	$signature = hash_hmac( 'sha256', $payload, $grant['keys'][ $grant['active_key_id'] ] );
	$headers   = array();

	foreach ( ec_cross_site_service_assertion_headers() as $claim => $header ) {
		$headers[ $header ] = 'signature' === $claim ? $signature : (string) $claims[ $claim ];
	}

	return $headers;
}

/**
 * Read assertion claims from a REST request.
 *
 * @param WP_REST_Request $request REST request.
 * @return array<string, string>|WP_Error|null Claims, partial-header error, or null.
 */
function ec_cross_site_service_assertion_claims( WP_REST_Request $request ) {
	$claims  = array();
	$present = 0;

	foreach ( ec_cross_site_service_assertion_headers() as $claim => $header ) {
		$value = $request->get_header( $header );
		if ( null !== $value && '' !== trim( $value ) ) {
			++$present;
			$claims[ $claim ] = trim( $value );
		}
	}

	if ( 0 === $present ) {
		return null;
	}
	if ( count( ec_cross_site_service_assertion_headers() ) !== $present ) {
		return new WP_Error( 'ec_service_assertion_invalid', 'Service assertion is invalid.', array( 'status' => 403 ) );
	}

	return $claims;
}

/**
 * Atomically consume an assertion nonce in target-site persistent storage.
 *
 * The options table has a unique option_name key. INSERT IGNORE therefore
 * admits exactly one concurrent request without relying on object-cache health.
 *
 * @param array<string, mixed> $claims Verified claims.
 * @return string One of consumed, replay, or unavailable.
 */
function ec_cross_site_consume_service_assertion_nonce( array $claims ): string {
	global $wpdb;

	if ( ! isset( $wpdb, $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
		return 'unavailable';
	}

	$replay_digest   = hash(
		'sha256',
		implode(
			'|',
			array(
				(string) $claims['version'],
				(string) $claims['service_id'],
				(string) $claims['key_id'],
				(string) $claims['source_site_id'],
				(string) $claims['target_site_id'],
				(string) $claims['nonce'],
			)
		)
	);
	$option_name     = '_ec_service_replay_' . $replay_digest;
	$suppress_errors = method_exists( $wpdb, 'suppress_errors' ) ? Closure::fromCallable( array( $wpdb, 'suppress_errors' ) ) : null;
	$previous        = null !== $suppress_errors ? $suppress_errors() : null;
	$inserted        = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique database key is the atomic replay boundary.
		$wpdb->prepare(
			"INSERT IGNORE INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)",
			$option_name,
			(string) $claims['expires_at'],
			'off'
		)
	);
	$storage_error   = ! empty( $wpdb->last_error );
	if ( null !== $previous && null !== $suppress_errors ) {
		$suppress_errors( $previous );
	}

	if ( 1 !== $inserted ) {
		return 0 === $inserted && ! $storage_error ? 'replay' : 'unavailable';
	}

	if ( function_exists( 'wp_schedule_single_event' ) ) {
		wp_schedule_single_event( (int) $claims['expires_at'] + 1, EC_CROSS_SITE_SERVICE_ASSERTION_REPLAY_HOOK, array( $option_name ) );
	}

	return 'consumed';
}

/**
 * Remove an expired replay marker.
 *
 * @param string $option_name Replay option name supplied by the scheduled event.
 * @return void
 */
function ec_cross_site_delete_service_assertion_replay( string $option_name ): void {
	if ( 1 === preg_match( '/^_ec_service_replay_[a-f0-9]{64}$/', $option_name ) ) {
		delete_option( $option_name );
	}
}
add_action( EC_CROSS_SITE_SERVICE_ASSERTION_REPLAY_HOOK, 'ec_cross_site_delete_service_assertion_replay' );

/**
 * Store verified claims against their request without mutating public input.
 *
 * @param WP_REST_Request      $request REST request.
 * @param array<string, mixed> $claims Verified normalized claims.
 * @return void
 */
function ec_cross_site_set_verified_service_context( WP_REST_Request $request, array $claims ): void {
	if ( ! isset( $GLOBALS['ec_cross_site_verified_service_contexts'] ) || ! $GLOBALS['ec_cross_site_verified_service_contexts'] instanceof WeakMap ) {
		$GLOBALS['ec_cross_site_verified_service_contexts'] = new WeakMap();
	}

	$GLOBALS['ec_cross_site_verified_service_contexts'][ $request ] = $claims;
}

/**
 * Return verified service claims for a request.
 *
 * @param WP_REST_Request $request REST request.
 * @return array<string, mixed> Verified claims, or an empty array.
 */
function ec_cross_site_verified_service_context( WP_REST_Request $request ): array {
	$contexts = $GLOBALS['ec_cross_site_verified_service_contexts'] ?? null;

	return $contexts instanceof WeakMap && isset( $contexts[ $request ] ) ? $contexts[ $request ] : array();
}

/**
 * Verify a service assertion before route permission callbacks run.
 *
 * @param mixed           $result  Existing pre-dispatch result.
 * @param WP_REST_Server  $server  REST server.
 * @param WP_REST_Request $request REST request.
 * @return mixed Existing result, null, or a generic WP_Error.
 */
function ec_cross_site_verify_service_assertion( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	$claims = ec_cross_site_service_assertion_claims( $request );
	if ( null === $claims ) {
		return $result;
	}
	if ( is_wp_error( $claims ) ) {
		return $claims;
	}

	$invalid = new WP_Error( 'ec_service_assertion_invalid', 'Service assertion is invalid.', array( 'status' => 403 ) );
	$remote  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$host    = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : '';
	$now     = time();

	if (
		! in_array( $remote, array( '127.0.0.1', '::1' ), true )
		|| EC_CROSS_SITE_SERVICE_ASSERTION_VERSION !== $claims['version']
		|| 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $claims['service_id'] )
		|| 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $claims['key_id'] )
		|| 1 !== preg_match( '#^[A-Za-z0-9._:/-]{1,128}$#', $claims['scope'] )
		|| 1 !== preg_match( '/^[a-f0-9]{32}$/', $claims['nonce'] )
		|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $claims['signature'] )
		|| ! ctype_digit( $claims['source_site_id'] )
		|| ! ctype_digit( $claims['target_site_id'] )
		|| ! ctype_digit( $claims['issued_at'] )
		|| ! ctype_digit( $claims['expires_at'] )
	) {
		return $invalid;
	}

	$normalized = array(
		'version'        => $claims['version'],
		'service_id'     => $claims['service_id'],
		'key_id'         => $claims['key_id'],
		'scope'          => $claims['scope'],
		'source_site_id' => (int) $claims['source_site_id'],
		'target_site_id' => (int) $claims['target_site_id'],
		'target_host'    => strtolower( $claims['target_host'] ),
		'issued_at'      => (int) $claims['issued_at'],
		'expires_at'     => (int) $claims['expires_at'],
		'nonce'          => $claims['nonce'],
	);
	$method     = strtoupper( $request->get_method() );
	$route      = $request->get_route();
	$grant      = ec_cross_site_service_assertion_find_grant(
		'target',
		array(
			'service_id'     => $normalized['service_id'],
			'scope'          => $normalized['scope'],
			'source_site_id' => $normalized['source_site_id'],
			'target_site_id' => $normalized['target_site_id'],
			'target_host'    => $normalized['target_host'],
			'method'         => $method,
			'route'          => $route,
		)
	);

	if (
		null === $grant
		|| (int) get_current_blog_id() !== $normalized['target_site_id']
		|| $normalized['target_host'] !== $host
		|| $normalized['issued_at'] > $now + EC_CROSS_SITE_SERVICE_ASSERTION_FUTURE_SKEW
		|| $normalized['expires_at'] <= $now
		|| $normalized['expires_at'] <= $normalized['issued_at']
		|| $normalized['expires_at'] - $normalized['issued_at'] > EC_CROSS_SITE_SERVICE_ASSERTION_MAX_TTL
		|| ! isset( $grant['keys'][ $normalized['key_id'] ] )
	) {
		return $invalid;
	}

	$payload  = ec_cross_site_service_assertion_payload(
		$normalized,
		$method,
		$route,
		ec_cross_site_service_assertion_digest( ec_cross_site_service_assertion_canonical_query( $request->get_query_params() ) ),
		ec_cross_site_service_assertion_digest( ec_cross_site_service_assertion_request_body( $request ) )
	);
	$expected = hash_hmac( 'sha256', $payload, $grant['keys'][ $normalized['key_id'] ] );
	if ( ! hash_equals( $expected, $claims['signature'] ) ) {
		return $invalid;
	}

	$consumed = ec_cross_site_consume_service_assertion_nonce( $normalized );
	if ( 'consumed' !== $consumed ) {
		$status = 'replay' === $consumed ? 403 : 503;
		return new WP_Error( 'ec_service_assertion_' . $consumed, 'Service assertion could not be accepted.', array( 'status' => $status ) );
	}

	ec_cross_site_set_verified_service_context( $request, $normalized );

	return $result;
}
add_filter( 'rest_pre_dispatch', 'ec_cross_site_verify_service_assertion', 1, 3 );
