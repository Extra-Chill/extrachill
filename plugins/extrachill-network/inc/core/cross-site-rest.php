<?php
/**
 * Cross-site REST request helper.
 *
 * Dispatches REST requests to other subsites in the network. Attempts
 * in-process dispatch via switch_to_blog() + rest_do_request() first, which
 * avoids spinning up a second PHP-FPM worker for network-shared routes.
 *
 * Two strategies are available:
 *
 * 1. **In-process (default).** switch_to_blog( $target ) then rest_do_request().
 *    Zero HTTP overhead, no auth handshake, no extra FPM worker. Safe for any
 *    route whose handlers depend only on network-shared plugins/abilities and
 *    blog-scoped options/queries — i.e. virtually every extrachill/v1 route.
 *
 * 2. **HTTP loopback (fallback).** wp_remote_request() to https://127.0.0.1
 *    with the target site's Host header. Spins up a fresh PHP-FPM worker that
 *    bootstraps the target site's full plugin stack independently. This is
 *    selected automatically when the source bootstrap lacks a requested
 *    ability, when in-process dispatch returns rest_no_route, or explicitly
 *    through the ec_cross_site_use_http_loopback filter.
 *
 * Auth in the in-process path uses wp_set_current_user() inside the
 * switch_to_blog() block — no HMAC handshake needed because the dispatch
 * never leaves the PHP process.
 *
 * Auth in the HTTP loopback path uses an HMAC-signed X-EC-Internal-User
 * header verified by the target site (see
 * ec_cross_site_authenticate_internal_request).
 *
 * Callers can force HTTP loopback via the `ec_cross_site_use_http_loopback`
 * filter (default: false).
 *
 * @package ExtraChillNetwork
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a caller-supplied path to a fully-qualified REST route.
 *
 * Accepts both shapes:
 *   - **Full path with namespace** (preferred): `/wp/v2/posts`,
 *     `/extrachill/v1/blog/transcribe-draft`, `/datamachine/v1/socials`.
 *     Passed through verbatim. Use this for any path outside the
 *     `extrachill/v1` namespace, including core WP routes (`wp/v2/*`).
 *   - **Namespace-relative path** (legacy): `/community/topics`. Prepended
 *     with `/extrachill/v1` for backward compatibility with existing
 *     callers (extrachill-api route-affinity middleware,
 *     extrachill-roadie PlatformTool).
 *
 * Detection: a path is treated as fully-qualified when it matches
 * `^/<segment>/v<N>/...`, e.g. `/wp/v2/posts` or `/extrachill/v1/foo`.
 * Anything else is assumed to be namespace-relative.
 *
 * @since 1.13.0
 *
 * @param string $path Caller-supplied REST path.
 * @return string Fully-qualified REST route starting with the namespace.
 */
function ec_cross_site_rest_resolve_route( string $path ): string {
	if ( preg_match( '#^/[a-z0-9-]+/v\d+/#i', $path ) ) {
		return $path;
	}

	return '/extrachill/v1' . $path;
}

/**
 * Make a REST API request to another subsite.
 *
 * The default path attempts in-process dispatch via switch_to_blog() +
 * rest_do_request(). Ability routes missing their named ability in the source
 * bootstrap dispatch directly over HTTP, while a rest_no_route result retries
 * over HTTP. Both paths let the target site's active plugins register their
 * canonical runtime. Callers can force HTTP loopback through the
 * `ec_cross_site_use_http_loopback` filter.
 *
 * @param string $site_key Logical site key (e.g. 'community', 'artist', 'events', 'main').
 * @param string $method   HTTP method (GET, POST, PUT, DELETE).
 * @param string $path     REST path. Either fully-qualified (`/wp/v2/posts`,
 *                         `/extrachill/v1/blog/foo`) or namespace-relative
 *                         (`/community/topics` → `/extrachill/v1/community/topics`).
 *                         See `ec_cross_site_rest_resolve_route()` for the rules.
 * @param array  $args     Optional. Request arguments:
 *                         - 'body'    => array|string  Request body for POST/PUT.
 *                         - 'query'   => array         Query parameters for GET.
 *                         - 'headers' => array         Additional headers (HTTP path only).
 *                         - 'timeout' => int           Request timeout (HTTP path only). Default 15.
 *                         - 'user_id' => int           Override user ID for auth. Default: current user.
 *                         - 'service_assertion' => array Explicit service_id and scope for HTTP authority.
 * @return array|string|WP_Error Decoded JSON response body, raw response body, or WP_Error on failure.
 */
function ec_cross_site_rest_request( string $site_key, string $method, string $path, array $args = array() ) {
	/**
	 * Filters whether to use HTTP loopback for cross-site REST dispatch.
	 *
	 * Default false — in-process dispatch via switch_to_blog() + rest_do_request().
	 * Set to true for routes that genuinely require the target site's full
	 * plugin bootstrap (rare in practice on a network-shared install).
	 *
	 * @param bool   $use_http  Whether to force HTTP loopback. Default false.
	 * @param string $site_key  Logical site key.
	 * @param string $method    HTTP method.
	 * @param string $path      REST path.
	 * @param array  $args      Request arguments.
	 */
	$use_http = isset( $args['service_assertion'] )
		|| (bool) apply_filters( 'ec_cross_site_use_http_loopback', false, $site_key, $method, $path, $args );

	if ( $use_http ) {
		return ec_cross_site_rest_request_http( $site_key, $method, $path, $args );
	}

	$route = ec_cross_site_rest_resolve_route( $path );
	if (
		function_exists( 'wp_has_ability' )
		&& preg_match( '#^/wp-abilities/v1/abilities/([a-zA-Z0-9\-/]+)/run/?$#', $route, $matches )
		&& ! wp_has_ability( $matches[1] )
	) {
		// Core registers one generic execution route before plugin abilities are
		// initialized. Avoid invoking that route with the source site's registry:
		// wp_get_ability() emits a notice for a miss before returning its 404.
		return ec_cross_site_rest_request_http( $site_key, $method, $path, $args );
	}

	$result = ec_cross_site_rest_request_in_process( $site_key, $method, $path, $args );

	// switch_to_blog() does not load plugins activated only on the target site.
	// Retry a missing route through the target host so its canonical plugin
	// bootstrap and rest_api_init lifecycle own route registration.
	if ( is_wp_error( $result ) && 'rest_no_route' === $result->get_error_code() ) {
		return ec_cross_site_rest_request_http( $site_key, $method, $path, $args );
	}

	return $result;
}

/**
 * Dispatch a cross-site REST request in-process via switch_to_blog().
 *
 * Sets up the target blog context, authenticates as the requested user,
 * dispatches the request via rest_do_request(), then restores the original
 * blog and user context. No HTTP traffic, no extra FPM worker.
 *
 * Sets $GLOBALS['ec_in_cross_site_dispatch'] = true for the duration of the
 * dispatch. The route-affinity middleware in extrachill-api short-circuits
 * naturally when get_current_blog_id() matches the target after switch_to_blog,
 * so the flag exists primarily for diagnostic/instrumentation use and as a
 * guard hook for any future middleware that needs to detect re-entry.
 *
 * @param string $site_key Logical site key.
 * @param string $method   HTTP method.
 * @param string $path     REST path without namespace.
 * @param array  $args     Request arguments.
 * @return array|WP_Error  Response data or WP_Error.
 */
function ec_cross_site_rest_request_in_process( string $site_key, string $method, string $path, array $args = array() ) {
	$target_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $site_key ) : null;

	if ( ! $target_blog_id ) {
		return new WP_Error(
			'ec_unknown_site',
			sprintf( 'Unknown site key: %s', $site_key ),
			array( 'status' => 400 )
		);
	}

	// Resolve route — accepts both fully-qualified paths (e.g. `/wp/v2/posts`)
	// and namespace-relative paths (e.g. `/community/topics`, prepended with
	// `/extrachill/v1` for backward compat).
	$route = ec_cross_site_rest_resolve_route( $path );

	// Resolve user context BEFORE switching blogs. wp_set_current_user() state
	// is global (not blog-scoped), but capability checks on the target site
	// still need the user record loaded.
	$desired_user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : (int) get_current_user_id();
	$original_user_id = (int) get_current_user_id();

	$method = strtoupper( $method );

	$switched = false;
	if ( (int) get_current_blog_id() !== (int) $target_blog_id ) {
		switch_to_blog( $target_blog_id );
		$switched = true;
	}

	// Mark in-cross-site-dispatch so middleware/hooks can detect re-entry.
	// Using a stack counter so nested dispatches behave correctly.
	if ( ! isset( $GLOBALS['ec_in_cross_site_dispatch'] ) ) {
		$GLOBALS['ec_in_cross_site_dispatch'] = 0;
	}
	++$GLOBALS['ec_in_cross_site_dispatch'];

	// Authenticate as the desired user inside the target blog context.
	// wp_set_current_user() is global — we restore the original below.
	if ( $desired_user_id !== $original_user_id ) {
		wp_set_current_user( $desired_user_id );
	}

	$result = null;
	try {
		$request = new WP_REST_Request( $method, $route );

		// Mark forwarded so any middleware that does its own re-entry guard
		// (e.g. extrachill-api route-affinity) treats this as terminal.
		$request->add_header( 'X-EC-Forwarded', '1' );

		// Query params for GET-style requests.
		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$request->set_query_params( $args['query'] );
		}

		// Body for write methods. Send as JSON-encoded body so REST handlers
		// that read get_json_params() get the same shape they would over HTTP.
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) && isset( $args['body'] ) ) {
			$body = $args['body'];
			if ( is_array( $body ) ) {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $body ) );
			} else {
				$request->set_body( (string) $body );
			}
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error         = $response->as_error();
			$data          = $error->get_error_data();
			$status        = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			$error_code    = $error->get_error_code();
			$error_message = $error->get_error_message();

			$result = new WP_Error(
				$error_code ? $error_code : 'ec_cross_site_error',
				$error_message ? $error_message : 'Cross-site request failed',
				array( 'status' => $status )
			);
		} else {
			$data = $response->get_data();
			// Normalize to array when possible — matches the HTTP path's
			// json_decode() return shape for caller compatibility.
			$result = is_array( $data ) ? $data : ( null === $data ? array() : $data );
		}
	} finally {
		// Restore user context — wp_set_current_user is global, must be
		// explicitly reverted regardless of switch_to_blog() state.
		if ( $desired_user_id !== $original_user_id ) {
			wp_set_current_user( $original_user_id );
		}

		--$GLOBALS['ec_in_cross_site_dispatch'];
		if ( $GLOBALS['ec_in_cross_site_dispatch'] <= 0 ) {
			unset( $GLOBALS['ec_in_cross_site_dispatch'] );
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $result;
}

/**
 * Dispatch a cross-site REST request via HTTP loopback (legacy path).
 *
 * Routes through 127.0.0.1 with the correct Host header so nginx dispatches
 * to the right virtual host. The target site bootstraps its own plugin stack
 * in a fresh PHP-FPM worker.
 *
 * Use this only when the target route genuinely requires the target site's
 * full bootstrap (e.g. site-only mu-plugins that don't register after
 * switch_to_blog). Costs an extra FPM worker per call — see issue #11.
 *
 * @param string $site_key Logical site key.
 * @param string $method   HTTP method.
 * @param string $path     REST path without namespace.
 * @param array  $args     Request arguments.
 * @return array|string|WP_Error Response data, raw response body, or WP_Error.
 */
function ec_cross_site_rest_request_http( string $site_key, string $method, string $path, array $args = array() ) {
	$method   = strtoupper( $method );
	$site_url = ec_get_site_url( $site_key );

	if ( ! $site_url ) {
		return new WP_Error(
			'ec_unknown_site',
			sprintf( 'Unknown site key: %s', $site_key ),
			array( 'status' => 400 )
		);
	}

	$host = wp_parse_url( $site_url, PHP_URL_HOST );

	if ( ! $host ) {
		return new WP_Error(
			'ec_invalid_site_url',
			sprintf( 'Could not parse host from site URL: %s', $site_url ),
			array( 'status' => 500 )
		);
	}

	// Build the localhost URL — route through 127.0.0.1 via HTTPS.
	// `ec_cross_site_rest_resolve_route()` accepts both fully-qualified paths
	// (`/wp/v2/posts`) and namespace-relative paths (`/community/topics`).
	$rest_path = '/wp-json' . ec_cross_site_rest_resolve_route( $path );

	// Append query parameters for GET requests.
	if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
		$rest_path .= '?' . http_build_query( $args['query'] );
	}

	$url = 'https://127.0.0.1' . $rest_path;

	// Build headers.
	$headers = array(
		'Host'         => $host,
		'Content-Type' => 'application/json',
		'Accept'       => 'application/json',
	);

	// Auth: determine user ID and build auth headers.
	$user_id      = $args['user_id'] ?? get_current_user_id();
	$auth_headers = ec_cross_site_build_auth_headers( $user_id );
	$headers      = array_merge( $headers, $auth_headers );

	// Merge any additional headers.
	if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
		$headers = array_merge( $headers, $args['headers'] );
	}

	if ( isset( $args['service_assertion'] ) ) {
		$service = $args['service_assertion'];
		if ( ! is_array( $service ) || ! isset( $service['service_id'], $service['scope'] ) ) {
			return new WP_Error( 'ec_service_assertion_denied', 'Service assertion could not be created.', array( 'status' => 403 ) );
		}
		$service_headers = ec_cross_site_build_service_assertion_headers(
			$site_key,
			$method,
			$path,
			$args,
			(string) $service['service_id'],
			(string) $service['scope']
		);
		if ( is_wp_error( $service_headers ) ) {
			return $service_headers;
		}
		$headers = array_merge( $headers, $service_headers );
	}

	$timeout = (float) ( $args['timeout'] ?? 15 );

	$request_args = array(
		'method'    => $method,
		'headers'   => $headers,
		'timeout'   => $timeout,
		'sslverify' => false, // Localhost — skip certificate verification.
	);

	// Attach body for POST/PUT/PATCH/DELETE.
	if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) && isset( $args['body'] ) ) {
		$request_args['body'] = (string) wp_json_encode( $args['body'] );
	}

	$response = wp_remote_request( $url, $request_args );

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'ec_cross_site_request_failed',
			sprintf( 'Cross-site request to %s failed: %s', $site_key, $response->get_error_message() ),
			array( 'status' => 502 )
		);
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	$decoded     = json_decode( $body, true );

	// If the target returned an error status, wrap it.
	if ( $status_code >= 400 ) {
		$error_message = 'Cross-site request failed';
		$error_code    = 'ec_cross_site_error';
		$error_data    = array( 'status' => $status_code );

		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['code'] ) && is_string( $decoded['code'] ) && strlen( $decoded['code'] ) <= 64 && preg_match( '/^[a-z0-9_-]+$/D', $decoded['code'] ) ) {
				$error_code = $decoded['code'];
			}

			$target_data = $decoded['data'] ?? null;
			if ( is_array( $target_data ) ) {
				foreach ( array( 'retryable', 'transient' ) as $field ) {
					if ( isset( $target_data[ $field ] ) && is_bool( $target_data[ $field ] ) ) {
						$error_data[ $field ] = $target_data[ $field ];
					}
				}

				// Retry delays are whole seconds, bounded to one day.
				if ( isset( $target_data['retry_after'] ) && ( is_int( $target_data['retry_after'] ) || is_float( $target_data['retry_after'] ) ) ) {
					$retry_after = (float) $target_data['retry_after'];
					if ( is_finite( $retry_after ) && $retry_after > 0 ) {
						$error_data['retry_after'] = (int) ceil( min( 86400.0, $retry_after ) );
					}
				}
			}
		}

		return new WP_Error( $error_code, $error_message, $error_data );
	}

	// Return decoded JSON, or the raw body if JSON parsing failed.
	return is_array( $decoded ) ? $decoded : $body;
}

/**
 * Build auth headers for cross-site requests.
 *
 * Uses two strategies:
 * 1. If the current request has cookies (browser context), forward them.
 * 2. Always include a signed internal user header for server-to-server trust.
 *
 * The target site's `ec_cross_site_authenticate_internal_request` hook
 * validates the HMAC and sets the current user.
 *
 * @param int $user_id User ID to authenticate as.
 * @return array Headers array.
 */
function ec_cross_site_build_auth_headers( int $user_id ): array {
	$headers = array();

	// Strategy 1: Forward cookies from browser requests.
	if ( ! empty( $_SERVER['HTTP_COOKIE'] ) ) {
		$headers['Cookie'] = $_SERVER['HTTP_COOKIE'];
	}

	// Forward the nonce if present in the original request.
	if ( ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
		$headers['X-WP-Nonce'] = $_SERVER['HTTP_X_WP_NONCE'];
	}

	// Strategy 2: Signed internal user header (works without cookies).
	if ( $user_id > 0 ) {
		$timestamp = time();
		$signature = ec_cross_site_sign_request( $user_id, $timestamp );

		$headers['X-EC-Internal-User']      = (string) $user_id;
		$headers['X-EC-Internal-Timestamp'] = (string) $timestamp;
		$headers['X-EC-Internal-Signature'] = $signature;
	}

	return $headers;
}

/**
 * Generate an HMAC signature for internal cross-site auth.
 *
 * Uses the WordPress AUTH_SALT as the shared secret — it's the same
 * across all sites in the multisite network (shared wp-config.php).
 *
 * @param int $user_id   User ID to sign.
 * @param int $timestamp Unix timestamp.
 * @return string HMAC-SHA256 hex signature.
 */
function ec_cross_site_sign_request( int $user_id, int $timestamp ): string {
	$secret  = defined( 'AUTH_SALT' ) ? AUTH_SALT : wp_salt( 'auth' );
	$payload = sprintf( 'ec-internal:%d:%d', $user_id, $timestamp );

	return hash_hmac( 'sha256', $payload, $secret );
}

/**
 * Verify an HMAC signature from an internal cross-site request.
 *
 * @param int    $user_id   Claimed user ID.
 * @param int    $timestamp Request timestamp.
 * @param string $signature HMAC signature to verify.
 * @return bool True if valid and not expired (5 minute window).
 */
function ec_cross_site_verify_signature( int $user_id, int $timestamp, string $signature ): bool {
	// Reject requests older than 5 minutes.
	if ( abs( time() - $timestamp ) > 300 ) {
		return false;
	}

	$expected = ec_cross_site_sign_request( $user_id, $timestamp );

	return hash_equals( $expected, $signature );
}

/**
 * Authenticate internal cross-site requests.
 *
 * Hooked early into `rest_authentication_errors` to check for the
 * X-EC-Internal-* headers and set the current user if valid.
 *
 * Only trusts requests from localhost (127.0.0.1 / ::1).
 *
 * @param WP_Error|null|true $result Existing auth result.
 * @return WP_Error|null|true Auth result.
 */
function ec_cross_site_authenticate_internal_request( $result ) {
	// Don't override if already authenticated.
	if ( null !== $result ) {
		return $result;
	}

	// Check for internal headers.
	$user_id   = isset( $_SERVER['HTTP_X_EC_INTERNAL_USER'] ) ? (int) $_SERVER['HTTP_X_EC_INTERNAL_USER'] : 0;
	$timestamp = isset( $_SERVER['HTTP_X_EC_INTERNAL_TIMESTAMP'] ) ? (int) $_SERVER['HTTP_X_EC_INTERNAL_TIMESTAMP'] : 0;
	$signature = isset( $_SERVER['HTTP_X_EC_INTERNAL_SIGNATURE'] ) ? sanitize_text_field( $_SERVER['HTTP_X_EC_INTERNAL_SIGNATURE'] ) : '';

	if ( ! $user_id || ! $timestamp || ! $signature ) {
		return $result;
	}

	// Only trust requests from localhost.
	$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
	if ( ! in_array( $remote_ip, array( '127.0.0.1', '::1' ), true ) ) {
		return $result;
	}

	// Verify the HMAC signature.
	if ( ! ec_cross_site_verify_signature( $user_id, $timestamp, $signature ) ) {
		return new WP_Error(
			'ec_internal_auth_failed',
			'Internal cross-site authentication failed.',
			array( 'status' => 403 )
		);
	}

	// Verify user exists.
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'ec_internal_user_not_found',
			'Internal auth user not found.',
			array( 'status' => 403 )
		);
	}

	// Set the current user — this request is trusted.
	wp_set_current_user( $user_id );

	return true;
}
add_filter( 'rest_authentication_errors', 'ec_cross_site_authenticate_internal_request', 5 );

/**
 * Get the site key for a given REST route path prefix.
 *
 * Used by the API route affinity middleware to determine which site
 * a route belongs to.
 *
 * @param string $route The REST route path (e.g. '/extrachill/v1/community/topics').
 * @return string|null  Site key (e.g. 'community') or null if route has no affinity.
 */
function ec_get_route_site_affinity( string $route ): ?string {
	/**
	 * Filters the route-to-site affinity map.
	 *
	 * Keys are REST path prefixes (after /wp-json/), values are site keys.
	 * The middleware checks if the current route starts with any prefix.
	 *
	 * @param array $affinity_map Route prefix => site key mapping.
	 */
	$affinity_map = apply_filters(
		'ec_route_site_affinity_map',
		array(
			'/extrachill/v1/blog/'      => 'main',
			'/extrachill/v1/community/' => 'community',
			'/extrachill/v1/artists/'   => 'artist',
			'/extrachill/v1/events/'    => 'events',
			'/extrachill/v1/shop/'      => 'shop',
			'/extrachill/v1/wire/'      => 'wire',
			'/extrachill/v1/docs/'      => 'docs',
		)
	);

	foreach ( $affinity_map as $prefix => $site_key ) {
		if ( str_starts_with( $route, $prefix ) ) {
			return $site_key;
		}
	}

	return null;
}
