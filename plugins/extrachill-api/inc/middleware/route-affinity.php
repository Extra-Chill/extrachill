<?php
/**
 * Route Affinity Middleware
 *
 * Intercepts REST API requests and forwards them to the correct subsite
 * when the current site doesn't match the route's home site.
 *
 * This makes the REST API universally accessible — any route can be called
 * from any site in the network. The middleware transparently proxies the
 * request to the correct subsite via internal HTTP (localhost).
 *
 * Requires: ec_cross_site_rest_request() from extrachill-multisite.
 *
 * @package ExtraChillAPI
 * @since 0.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add API-owned route families whose handlers require site-scoped plugins.
 *
 * @param array $affinity_map Route prefix to site key map.
 * @return array
 */
function extrachill_api_add_artist_route_affinity( $affinity_map ) {
	$affinity_map['/extrachill/v1/admin/artist-relationships'] = 'artist';
	$affinity_map['/extrachill/v1/event-submissions']          = 'events';
	$affinity_map['/extrachill/v1/giveaway/']                  = 'studio';
	$affinity_map['/extrachill/v1/instagram/']                 = 'studio';
	$affinity_map['/extrachill/v1/artists/']                   = 'artist';

	return $affinity_map;
}
add_filter( 'ec_route_site_affinity_map', 'extrachill_api_add_artist_route_affinity' );

/**
 * Normalize structured request data before hashing it.
 *
 * @param mixed $value Value to normalize.
 * @return mixed
 */
function extrachill_api_normalize_affinity_data( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
	if ( ! $is_list ) {
		ksort( $value, SORT_STRING );
	}

	foreach ( $value as $key => $item ) {
		$value[ $key ] = extrachill_api_normalize_affinity_data( $item );
	}

	return $value;
}

/**
 * Canonicalize query input exactly as the cross-site HTTP hop transmits it.
 *
 * The helper uses http_build_query(), and the target PHP request parses that
 * wire string before WordPress exposes query parameters. Replaying both steps
 * preserves false-as-0 while omitting null and empty arrays at every depth.
 *
 * @param array $query Query parameters before transport.
 * @return array
 */
function extrachill_api_canonicalize_affinity_query( $query ) {
	$canonical = array();
	parse_str( http_build_query( $query ), $canonical );

	return extrachill_api_normalize_affinity_data( $canonical );
}

/**
 * Create the signed route-affinity payload.
 *
 * @param string $method      HTTP method.
 * @param string $route       REST route.
 * @param string $target_host Target host.
 * @param array  $query       Query parameters.
 * @param mixed  $body        Request body.
 * @param int    $timestamp   Unix timestamp.
 * @param string $nonce       Single-use nonce.
 * @param array  $headers     Allowlisted forwarded headers.
 * @return string
 */
function extrachill_api_route_affinity_payload( $method, $route, $target_host, $query, $body, $timestamp, $nonce, $headers = array() ) {
	$query_digest  = hash( 'sha256', (string) wp_json_encode( extrachill_api_canonicalize_affinity_query( $query ) ) );
	$body_digest   = hash( 'sha256', (string) wp_json_encode( extrachill_api_normalize_affinity_data( $body ) ) );
	$header_digest = hash( 'sha256', (string) wp_json_encode( extrachill_api_normalize_affinity_data( $headers ) ) );

	return implode(
		"\n",
		array(
			strtoupper( $method ),
			$route,
			strtolower( $target_host ),
			$query_digest,
			$body_digest,
			$header_digest,
			(string) $timestamp,
			$nonce,
		)
	);
}

/**
 * Return the headers bound to route-affinity transport.
 *
 * Client identity is derived only while creating the hop. Verification reads
 * the transmitted values so a direct caller can never ask API to sign a
 * caller-supplied affinity identity.
 *
 * @param WP_REST_Request $request    Request being forwarded or verified.
 * @param bool            $forwarding Whether this is the source-side hop.
 * @return array
 */
function extrachill_api_route_affinity_forwarded_headers( WP_REST_Request $request, $forwarding = false ) {
	$headers = array();
	if ( function_exists( 'extrachill_api_is_booking_attachment_download_route' ) && extrachill_api_is_booking_attachment_download_route( $request->get_route() ) ) {
		$range = trim( (string) $request->get_header( 'Range' ) );
		if ( '' !== $range ) {
			$headers['range'] = $range;
		}
	}

	if ( $forwarding ) {
		$client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $client_ip && false !== filter_var( $client_ip, FILTER_VALIDATE_IP ) ) {
			$headers['x-ec-affinity-client']      = hash_hmac( 'sha256', $client_ip, wp_salt( 'nonce' ) );
			$headers['x-ec-affinity-remote-addr'] = $client_ip;
		}
	} else {
		$client = strtolower( trim( (string) $request->get_header( 'X-EC-Affinity-Client' ) ) );
		$remote = trim( (string) $request->get_header( 'X-EC-Affinity-Remote-Addr' ) );
		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $client ) && false !== filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			$headers['x-ec-affinity-client']      = $client;
			$headers['x-ec-affinity-remote-addr'] = $remote;
		}
		$handoff = trim( (string) $request->get_header( 'X-EC-Affinity-Download-Handoff' ) );
		if ( function_exists( 'extrachill_api_decode_booking_attachment_handoff' ) && extrachill_api_decode_booking_attachment_handoff( $handoff ) ) {
			$headers['x-ec-affinity-download-handoff'] = $handoff;
		}
	}

	return $headers;
}

/** Store cryptographically verified context without mutating public input. */
function extrachill_api_set_route_affinity_context( WP_REST_Request $request, array $context ) {
	if ( ! isset( $GLOBALS['extrachill_api_route_affinity_contexts'] ) || ! $GLOBALS['extrachill_api_route_affinity_contexts'] instanceof WeakMap ) {
		$GLOBALS['extrachill_api_route_affinity_contexts'] = new WeakMap();
	}
	$GLOBALS['extrachill_api_route_affinity_contexts'][ $request ] = $context;
}

/** Return context created only by successful affinity HMAC verification. */
function extrachill_api_route_affinity_context( WP_REST_Request $request ) {
	$contexts = $GLOBALS['extrachill_api_route_affinity_contexts'] ?? null;
	return $contexts instanceof WeakMap && isset( $contexts[ $request ] ) ? $contexts[ $request ] : array();
}

/**
 * Read the body shape that route-affinity forwards.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return mixed
 */
function extrachill_api_route_affinity_request_body( WP_REST_Request $request ) {
	if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
		return array();
	}

	$body = $request->get_json_params();
	if ( empty( $body ) ) {
		$body = $request->get_body_params();
	}
	$body = ! empty( $body ) ? $body : array();
	unset( $body['_ec_affinity_client'], $body['_ec_affinity_remote_addr'] );

	/**
	 * Filters the signed body representation for transport-only request data.
	 *
	 * @param array           $body    Parsed request body.
	 * @param WP_REST_Request $request Request being forwarded or verified.
	 */
	return apply_filters( 'extrachill_api_route_affinity_signature_body', $body, $request );
}

/**
 * Check whether the current HTTP request originated from loopback.
 *
 * @return bool
 */
function extrachill_api_is_local_request() {
	$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return in_array( $remote_addr, array( '127.0.0.1', '::1' ), true );
}

/**
 * Check whether any route-affinity token fields were supplied.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return bool
 */
function extrachill_api_has_route_affinity_token( WP_REST_Request $request ) {
	foreach ( array( 'X-EC-Affinity-Timestamp', 'X-EC-Affinity-Signature', 'X-EC-Affinity-Target', 'X-EC-Affinity-Nonce' ) as $header ) {
		if ( $request->get_header( $header ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether a request is a trusted route-affinity re-entry.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return bool
 */
function extrachill_api_is_route_affinity_reentry( WP_REST_Request $request ) {
	if ( ! extrachill_api_is_local_request() ) {
		return false;
	}

	$timestamp = (int) $request->get_header( 'X-EC-Affinity-Timestamp' );
	$signature = $request->get_header( 'X-EC-Affinity-Signature' );
	$target    = strtolower( (string) $request->get_header( 'X-EC-Affinity-Target' ) );
	$nonce     = $request->get_header( 'X-EC-Affinity-Nonce' );
	$host      = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	if ( ! $timestamp || ! $signature || ! $target || ! $nonce || $target !== $host || abs( time() - $timestamp ) > 300 ) {
		return false;
	}

	$forwarded_headers = extrachill_api_route_affinity_forwarded_headers( $request );
	$payload           = extrachill_api_route_affinity_payload(
		$request->get_method(),
		$request->get_route(),
		$target,
		$request->get_query_params(),
		extrachill_api_route_affinity_request_body( $request ),
		$timestamp,
		$nonce,
		$forwarded_headers
	);
	$expected          = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	if ( ! hash_equals( $expected, $signature ) ) {
		return false;
	}

	// Persistent object caches make this single-use across loopback workers.
	// Without one, localhost and the five-minute signature window remain the
	// residual replay boundary.
	$verified = wp_cache_add( 'route_affinity_' . hash( 'sha256', $nonce ), 1, 'extrachill_api', 300 );
	if ( $verified ) {
		$download_handoff = function_exists( 'extrachill_api_decode_booking_attachment_handoff' )
			? extrachill_api_decode_booking_attachment_handoff( $forwarded_headers['x-ec-affinity-download-handoff'] ?? '' )
			: null;
		extrachill_api_set_route_affinity_context(
			$request,
			array(
				'client'           => (string) ( $forwarded_headers['x-ec-affinity-client'] ?? '' ),
				'remote_addr'      => (string) ( $forwarded_headers['x-ec-affinity-remote-addr'] ?? '' ),
				'nonce'            => $nonce,
				'download_handoff' => $download_handoff,
			)
		);
	}

	return $verified;
}

/**
 * Check if a REST request should be forwarded to another subsite.
 *
 * Hooked into `rest_pre_dispatch` — if the route belongs to a different site,
 * forwards the request via internal HTTP and returns the response directly.
 *
 * @param mixed           $result  Response to replace the requested version with. Default null.
 * @param WP_REST_Server  $server  REST server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed Forwarded response or null to continue normal dispatch.
 */
function extrachill_api_route_affinity_dispatch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	// Only a signed localhost request can suppress forwarding on re-entry.
	if ( extrachill_api_is_route_affinity_reentry( $request ) ) {
		return $result;
	}
	if ( extrachill_api_is_local_request() && extrachill_api_has_route_affinity_token( $request ) ) {
		return new WP_Error( 'route_affinity_reentry_invalid', 'Invalid or expired route-affinity token.', array( 'status' => 403 ) );
	}

	// Only handle extrachill/v1 routes.
	$route = $request->get_route();
	if ( ! str_starts_with( $route, '/extrachill/v1/' ) ) {
		return $result;
	}
	$booking_follow_through = 1 === preg_match( '#^/extrachill/v1/venues/\d+/booking-inquiries/follow-through/(?:status|correction|withdrawal|receipt-recovery)$#', $route );

	// Check if the multisite helper is available.
	if ( ! function_exists( 'ec_get_route_site_affinity' ) || ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return $result;
	}

	// Determine which site this route belongs to.
	$target_site = '/extrachill/v1/artists' === $route ? 'artist' : ec_get_route_site_affinity( $route );

	if ( ! $target_site ) {
		return $result; // No affinity — handle normally on current site.
	}

	// Check if we're already on the correct site.
	if ( function_exists( 'ec_get_blog_id' ) ) {
		$target_blog_id  = ec_get_blog_id( $target_site );
		$current_blog_id = get_current_blog_id();

		if ( $target_blog_id && (int) $current_blog_id === (int) $target_blog_id ) {
			return $result; // Already on the right site — handle normally.
		}
	}

	// Forward the request to the correct subsite.
	$method = $request->get_method();

	// Build the REST path (everything after /extrachill/v1).
	$path = substr( $route, strlen( '/extrachill/v1' ) );

	$args = array();

	// Forward query parameters.
	$query_params = $request->get_query_params();
	if ( ! empty( $query_params ) ) {
		$args['query'] = $query_params;
	}

	// Forward body for write methods.
	$body = extrachill_api_route_affinity_request_body( $request );
	if ( ! empty( $body ) ) {
		$args['body'] = $body;
	}

	// Forward the current user context.
	$current_user_id = get_current_user_id();
	if ( $current_user_id > 0 ) {
		$args['user_id'] = $current_user_id;
	}

	$target_url  = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( $target_site ) : '';
	$target_host = $target_url ? wp_parse_url( $target_url, PHP_URL_HOST ) : '';
	if ( ! $target_host ) {
		$error = new WP_Error( 'route_affinity_target_invalid', 'Could not resolve route-affinity target host.', array( 'status' => 500 ) );
		return $booking_follow_through && function_exists( 'extrachill_api_booking_follow_through_affinity_error' ) ? extrachill_api_booking_follow_through_affinity_error( $error ) : $error;
	}

	$private_stream = function_exists( 'extrachill_api_is_booking_attachment_download_route' ) && extrachill_api_is_booking_attachment_download_route( $route );
	$outer_delivery = null;
	$private_spool  = null;
	if ( $private_stream ) {
		$private_spool = apply_filters( 'extrachill_api_private_affinity_spool_path', wp_tempnam( 'extrachill-private-download' ) );
		if ( ! is_string( $private_spool ) || '' === $private_spool ) {
			return extrachill_api_fail_private_affinity_stream( $private_spool, null, 503 );
		}
		$spool_protected = apply_filters( 'extrachill_api_private_affinity_spool_protected', chmod( $private_spool, 0600 ), $private_spool ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private affinity spool must be process-only.
		if ( ! $spool_protected ) {
			return extrachill_api_fail_private_affinity_stream( $private_spool, null, 503 );
		}
	}
	$forwarded_headers = extrachill_api_route_affinity_forwarded_headers( $request, true );
	if ( $private_stream ) {
		$handoff = extrachill_api_prepare_booking_attachment_affinity_handoff( $request );
		if ( is_wp_error( $handoff ) ) {
			return extrachill_api_fail_private_affinity_stream( $private_spool, null );
		}
		$forwarded_headers['x-ec-affinity-download-handoff'] = extrachill_api_encode_booking_attachment_handoff( $handoff );
		$outer_delivery                                      = array(
			'booking_id'     => (int) $request->get_param( 'booking_id' ),
			'attachment_id'  => (int) $request->get_param( 'attachment_id' ),
			'correlation_id' => (string) $handoff['correlation_id'],
		);
	}
	$timestamp       = time();
	$nonce           = wp_generate_uuid4();
	$payload         = extrachill_api_route_affinity_payload( $method, $route, $target_host, $query_params, $body, $timestamp, $nonce, $forwarded_headers );
	$signature       = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	$args['headers'] = array(
		'X-EC-Affinity-Timestamp' => (string) $timestamp,
		'X-EC-Affinity-Signature' => $signature,
		'X-EC-Affinity-Target'    => strtolower( $target_host ),
		'X-EC-Affinity-Nonce'     => $nonce,
	);
	foreach ( $forwarded_headers as $name => $value ) {
		$args['headers'][ $name ] = $value;
	}

	// Force HTTP loopback for affinity forwarding.
	//
	// A route only has site affinity because its handler depends on the
	// target site's plugin stack — e.g. /events/* handlers call abilities
	// registered by extrachill-events, which is active ONLY on the events
	// site. The default in-process cross-site path (switch_to_blog() +
	// rest_do_request()) swaps DB/options context but does NOT bootstrap the
	// target site's per-site plugins, so those abilities are never registered
	// in the source process. The forwarded request then runs the handler with
	// the ability missing → WP_Abilities_Registry logs a "not found" notice on
	// every call and the route returns a 500.
	//
	// HTTP loopback spins up a fresh PHP-FPM worker that bootstraps the target
	// site's full plugin stack, so the per-site ability is registered and the
	// handler resolves correctly. This is precisely the documented use case for
	// the loopback path. See Extra-Chill/extrachill-events#141.
	$force_http_loopback     = static function () {
		return true;
	};
	$forwarded_http_response = null;
	$stream_http_response    = static function ( $http_args ) use ( $signature, $private_spool ) {
		$headers = $http_args['headers'] ?? array();
		if ( null !== $private_spool && ( $headers['X-EC-Affinity-Signature'] ?? '' ) === $signature ) {
			$http_args['stream']              = true;
			$http_args['filename']            = $private_spool;
			$http_args['limit_response_size'] = extrachill_api_booking_attachment_max_bytes() + 1;
			$http_args['timeout']             = 30;
			$http_args['headers']['Accept']   = 'application/octet-stream';
		}

		return $http_args;
	};
	$capture_http_response   = static function ( $http_response, $http_args ) use ( &$forwarded_http_response, $signature ) {
		$headers = $http_args['headers'] ?? array();
		if ( ( $headers['X-EC-Affinity-Signature'] ?? '' ) === $signature && false !== $http_response ) {
			$forwarded_http_response = $http_response;
		}

		return $http_response;
	};
	add_filter( 'ec_cross_site_use_http_loopback', $force_http_loopback, 10, 0 );
	add_filter( 'http_request_args', $stream_http_response, PHP_INT_MAX, 1 );
	add_filter( 'pre_http_request', $capture_http_response, PHP_INT_MAX, 2 );
	add_filter( 'http_response', $capture_http_response, PHP_INT_MAX, 2 );

	try {
		/**
		 * Allows a route owner to preserve admitted files over the HTTP hop.
		 * A null result falls through to the standard JSON transport.
		 *
		 * @param mixed           $response    Forwarded result or null.
		 * @param string          $target_site Target site key.
		 * @param string          $path        Namespace-relative path.
		 * @param array           $args        Signed forwarding arguments.
		 * @param WP_REST_Request $request     Original request.
		 */
		$response = apply_filters( 'extrachill_api_route_affinity_file_forward', null, $target_site, $path, $args, $request );
		if ( null === $response ) {
			$response = ec_cross_site_rest_request( $target_site, $method, $path, $args );
		}
	} catch ( Throwable $throwable ) {
		if ( $private_stream ) {
			$response = extrachill_api_booking_attachment_download_error( 502 );
		} elseif ( $booking_follow_through && function_exists( 'extrachill_api_booking_follow_through_affinity_error' ) ) {
			$response = extrachill_api_booking_follow_through_affinity_error( new WP_Error( 'route_affinity_transport_failed', $throwable->getMessage(), array( 'status' => 503 ) ) );
		} else {
			throw $throwable;
		}
	} finally {
		remove_filter( 'ec_cross_site_use_http_loopback', $force_http_loopback, 10 );
		remove_filter( 'http_request_args', $stream_http_response, PHP_INT_MAX );
		remove_filter( 'pre_http_request', $capture_http_response, PHP_INT_MAX );
		remove_filter( 'http_response', $capture_http_response, PHP_INT_MAX );
	}

	if ( is_array( $forwarded_http_response ) ) {
		if ( $private_stream ) {
			return extrachill_api_private_stream_from_affinity_response( $forwarded_http_response, $private_spool, $nonce );
		}
		$status  = wp_remote_retrieve_response_code( $forwarded_http_response );
		$headers = wp_remote_retrieve_headers( $forwarded_http_response );
		$body    = wp_remote_retrieve_body( $forwarded_http_response );
		$data    = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$data = $body;
		}

		if ( $headers instanceof Traversable ) {
			$headers = iterator_to_array( $headers );
		}
		if ( preg_match( '#^/extrachill/v1/venues/\d+/booking-availability$#', $route ) && function_exists( 'extrachill_api_booking_availability_affinity_response' ) ) {
			return extrachill_api_booking_availability_affinity_response( $data, (int) $status, $headers );
		}

		return new WP_REST_Response( $data, (int) $status, $headers );
	}

	if ( is_wp_error( $response ) ) {
		if ( $private_stream ) {
			return extrachill_api_fail_private_affinity_stream( $private_spool, $outer_delivery );
		}
		if ( preg_match( '#^/extrachill/v1/venues/\d+/booking-inquiries$#', $route ) && function_exists( 'extrachill_api_booking_public_error' ) ) {
			$response = extrachill_api_booking_public_error( $response );
		} elseif ( $booking_follow_through && function_exists( 'extrachill_api_booking_follow_through_affinity_error' ) ) {
			$affinity_error = is_wp_error( $forwarded_http_response ) ? $forwarded_http_response : $response;
			$response       = extrachill_api_booking_follow_through_affinity_error( $affinity_error );
			$rest_response  = rest_convert_error_to_response( $response );
			$error_data     = (array) $response->get_error_data();
			if ( isset( $error_data['headers']['Retry-After'] ) ) {
				$rest_response->header( 'Retry-After', (string) $error_data['headers']['Retry-After'] );
			}
			return function_exists( 'extrachill_api_booking_transport_error_headers' ) ? extrachill_api_booking_transport_error_headers( $rest_response, $server, $request ) : $rest_response;
		} elseif ( preg_match( '#^/extrachill/v1/venues/\d+/booking-availability$#', $route ) && function_exists( 'extrachill_api_booking_availability_public_error' ) ) {
			$response = extrachill_api_booking_availability_public_error( $response );
		}
		$status = $response->get_error_data()['status'] ?? 500;
		return new WP_REST_Response(
			array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	if ( $private_stream ) {
		return extrachill_api_fail_private_affinity_stream( $private_spool, $outer_delivery );
	}

	// Return the forwarded response.
	return new WP_REST_Response( $response, 200 );
}
add_filter( 'rest_pre_dispatch', 'extrachill_api_route_affinity_dispatch', 10, 3 );
