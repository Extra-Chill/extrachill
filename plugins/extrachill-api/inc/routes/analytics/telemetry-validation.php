<?php
/**
 * Shared admission checks for public browser telemetry adapters.
 *
 * Analytics owns event semantics and persistence. These helpers only enforce
 * the HTTP trust boundary before forwarding accepted shapes to that contract.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate and normalize common public telemetry input.
 *
 * @param WP_REST_Request $request REST request.
 * @return array|WP_Error Normalized input or an admission error.
 */
function extrachill_api_validate_telemetry_request( WP_REST_Request $request ) {
	$rate_limit = extrachill_api_check_telemetry_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$source_url = (string) $request->get_param( 'source_url' );
	$source     = extrachill_api_normalize_telemetry_source( $source_url );
	if ( is_wp_error( $source ) ) {
		return $source;
	}

	$origin = $request->get_header( 'origin' );
	if ( $origin ) {
		$origin_host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		if ( '' === $origin_host || $origin_host !== $source['host'] ) {
			return new WP_Error(
				'invalid_telemetry_origin',
				'Telemetry origin does not match source_url.',
				array( 'status' => 403 )
			);
		}
	}

	return $source;
}

/**
 * Convert an accepted first-party source URL to its query-free path.
 *
 * @param string $url Client source URL.
 * @return array|WP_Error Source host and normalized path.
 */
function extrachill_api_normalize_telemetry_source( $url ) {
	if ( '' === $url || strlen( $url ) > 2048 ) {
		return new WP_Error( 'invalid_source_url', 'A valid source_url is required.', array( 'status' => 400 ) );
	}

	$parts = wp_parse_url( $url );
	if ( false === $parts || ! is_array( $parts ) ) {
		return new WP_Error( 'invalid_source_url', 'A valid source_url is required.', array( 'status' => 400 ) );
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	$host   = isset( $parts['host'] ) ? strtolower( rtrim( $parts['host'], '.' ) ) : '';
	$path   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

	if ( '' === $host && 0 === strpos( $url, '/' ) ) {
		$host = extrachill_api_telemetry_request_host();
	} elseif ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return new WP_Error( 'invalid_source_url', 'source_url must be an HTTP(S) first-party URL.', array( 'status' => 400 ) );
	}

	if ( '' === $host || ! extrachill_api_is_first_party_telemetry_host( $host ) ) {
		return new WP_Error( 'invalid_source_host', 'source_url must belong to the current site.', array( 'status' => 403 ) );
	}

	if ( extrachill_api_is_unsafe_telemetry_path( $path ) ) {
		return new WP_Error( 'unsafe_telemetry_path', 'Telemetry source path is not accepted.', array( 'status' => 400 ) );
	}

	$path = '/' . ltrim( $path, '/' );

	return array(
		'host' => $host,
		'path' => $path,
	);
}

/**
 * Normalize a destination and remove analytics or sensitive query fields.
 *
 * @param string $url Client destination URL.
 * @return array|WP_Error Destination URL and host.
 */
function extrachill_api_normalize_telemetry_destination( $url ) {
	if ( '' === $url || strlen( $url ) > 2048 ) {
		return new WP_Error( 'invalid_destination_url', 'A valid destination_url is required.', array( 'status' => 400 ) );
	}

	$parts = wp_parse_url( $url );
	if ( false === $parts || ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return new WP_Error( 'invalid_destination_url', 'destination_url must be an absolute HTTP(S) URL.', array( 'status' => 400 ) );
	}

	$scheme = strtolower( $parts['scheme'] );
	$host   = strtolower( rtrim( $parts['host'], '.' ) );
	$path   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return new WP_Error( 'invalid_destination_url', 'destination_url must be an absolute HTTP(S) URL.', array( 'status' => 400 ) );
	}

	if ( extrachill_api_is_unsafe_telemetry_path( $path ) ) {
		return new WP_Error( 'unsafe_destination_url', 'Telemetry destination is not accepted.', array( 'status' => 400 ) );
	}

	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		wp_parse_str( $parts['query'], $query );
		foreach ( $query as $key => $value ) {
			if ( extrachill_api_is_sensitive_telemetry_field( $key, $value ) ) {
				unset( $query[ $key ] );
			}
		}
	}

	$normalized = $scheme . '://' . $host;
	if ( isset( $parts['port'] ) ) {
		$normalized .= ':' . (int) $parts['port'];
	}
	$normalized .= '/' . ltrim( $path, '/' );
	if ( $query ) {
		$normalized .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	return array(
		'host' => $host,
		'url'  => $normalized,
	);
}

/**
 * Determine whether a source host resolves to the current network site.
 *
 * @param string $host Candidate source host.
 * @return bool
 */
function extrachill_api_is_first_party_telemetry_host( $host ) {
	$current_blog_id = (int) get_current_blog_id();
	$allowed         = array();

	foreach ( array( home_url(), site_url(), rest_url() ) as $site_url ) {
		$site_host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		if ( '' !== $site_host ) {
			$allowed[] = $site_host;
		}
	}

	if ( function_exists( 'ec_get_domain_map' ) ) {
		foreach ( ec_get_domain_map() as $domain => $blog_id ) {
			if ( $current_blog_id === (int) $blog_id ) {
				$allowed[] = strtolower( $domain );
			}
		}
	}

	if ( in_array( $host, array_unique( $allowed ), true ) ) {
		return true;
	}

	$request_host = extrachill_api_telemetry_request_host();
	if ( $host !== $request_host || ! function_exists( 'get_site_by_path' ) ) {
		return false;
	}

	$site = get_site_by_path( $host, '/' );

	return $site && $current_blog_id === (int) $site->blog_id;
}

/**
 * Check a host against exact network hosts and their subdomains.
 *
 * @param string $host          Candidate destination host.
 * @param array  $network_hosts First-party host list.
 * @return bool
 */
function extrachill_api_is_network_telemetry_host( $host, $network_hosts ) {
	$host = strtolower( rtrim( $host, '.' ) );
	foreach ( $network_hosts as $network_host ) {
		$network_host = strtolower( rtrim( (string) $network_host, '.' ) );
		if ( $host === $network_host || ( $network_host && substr( $host, -strlen( '.' . $network_host ) ) === '.' . $network_host ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Return the hostname used for the current request.
 *
 * @return string
 */
function extrachill_api_telemetry_request_host() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized and parsed below.
	$host = strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( $host ) ) );

	return rtrim( $host, '.' );
}

/**
 * Reject common scanner targets and encoded form/payload paths.
 *
 * @param string $path URL path.
 * @return bool
 */
function extrachill_api_is_unsafe_telemetry_path( $path ) {
	$decoded = extrachill_api_decode_telemetry_value( $path );
	if ( preg_match( '/[\x00-\x1F\x7F]/', $decoded ) ) {
		return true;
	}

	return (bool) preg_match(
		'#(?:^|/)(?:\.env|\.git|wp-admin|wp-login\.php|xmlrpc\.php|phpmyadmin|vendor/phpunit|cgi-bin|actuator)(?:/|$)#i',
		$decoded
	) || (bool) preg_match( '/(?:password|passwd|access_token|authorization|session(?:id)?|api[_-]?key)\s*[=:]/i', $decoded );
}

/**
 * Detect query fields that must never enter analytics event data.
 *
 * @param string $key   Query field name.
 * @param mixed  $value Query field value.
 * @return bool
 */
function extrachill_api_is_sensitive_telemetry_field( $key, $value ) {
	$key     = strtolower( extrachill_api_decode_telemetry_value( (string) $key ) );
	$encoded = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	$value   = strtolower( extrachill_api_decode_telemetry_value( $encoded ) );

	if ( preg_match( '/(?:^|[_-])(?:pass(?:word|wd)?|email|e-mail|user(?:name)?|login|auth(?:orization)?|token|secret|nonce|session(?:id)?|cookie|api[_-]?key|credit|card|cvv|ssn)(?:$|[_-])/i', $key ) ) {
		return true;
	}

	return (bool) preg_match( '/["\'{&?](?:pass(?:word|wd)?|email|authorization|token|secret|session(?:id)?|api[_-]?key)["\'}\s]*[=:]/i', $value );
}

/**
 * Decode nested URL encoding without allowing unbounded work.
 *
 * @param string $value Encoded value.
 * @return string
 */
function extrachill_api_decode_telemetry_value( $value ) {
	for ( $i = 0; $i < 3; $i++ ) {
		$decoded = rawurldecode( $value );
		if ( $decoded === $value ) {
			break;
		}
		$value = $decoded;
	}

	return $value;
}

/**
 * Validate bounded free-text dimensions before forwarding them.
 *
 * @param string $value Client text dimension.
 * @return bool
 */
function extrachill_api_is_safe_telemetry_text( $value ) {
	if ( strlen( $value ) > 200 ) {
		return false;
	}

	$decoded = extrachill_api_decode_telemetry_value( $value );

	return ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F{}<>]/', $decoded )
		&& ! preg_match( '/(?:password|passwd|authorization|access_token|session(?:id)?|api[_-]?key)\s*[=:]/i', $decoded );
}

/**
 * Apply a fixed-window per-client cap to public telemetry writes.
 *
 * @return true|WP_Error
 */
function extrachill_api_check_telemetry_rate_limit() {
	$limit = (int) apply_filters( 'extrachill_api_telemetry_rate_limit', 240 );
	if ( $limit < 1 ) {
		return true;
	}

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return true;
	}

	$key   = 'ec_api_tel_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return new WP_Error(
			'telemetry_rate_limited',
			'Too many telemetry requests.',
			array( 'status' => 429 )
		);
	}

	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

	return true;
}
