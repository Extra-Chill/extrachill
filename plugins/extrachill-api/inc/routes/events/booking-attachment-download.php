<?php
/**
 * Protected booking attachment download transport.
 *
 * Events owns authorization, handoffs, private bytes, policy, and audit. This
 * route only adapts those contracts to a bounded HTTP byte response.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Native resource streaming and unhooked spool deletion are required so private
// bytes never enter WP_Filesystem buffers or path-observing deletion hooks.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.unlink_unlink

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_booking_attachment_download_route' );
add_filter( 'rest_post_dispatch', 'extrachill_api_protect_booking_attachment_download_response', 10, 3 );
add_filter( 'rest_pre_serve_request', 'extrachill_api_serve_private_stream', 10, 4 );

/** Register the authenticated Events-owned attachment download route. */
function extrachill_api_register_booking_attachment_download_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/bookings/(?P<booking_id>\d+)/attachments/(?P<attachment_id>\d+)/download',
		array(
			'methods'             => 'GET',
			'callback'            => 'extrachill_api_download_booking_attachment',
			'permission_callback' => 'extrachill_api_booking_attachment_download_permission',
			'args'                => array(
				'booking_id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'attachment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		)
	);
	register_rest_route(
		'extrachill/v1',
		'/events/internal/booking-attachment-handoff',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_issue_booking_attachment_handoff_request',
			'permission_callback' => 'extrachill_api_booking_attachment_handoff_internal_permission',
		)
	);
	register_rest_route(
		'extrachill/v1',
		'/events/internal/booking-attachment-delivery',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_record_booking_attachment_delivery_request',
			'permission_callback' => 'extrachill_api_booking_attachment_delivery_internal_permission',
		)
	);
}

/** Admit descriptor preflight only through body-bound localhost user transport. */
function extrachill_api_booking_attachment_handoff_internal_permission( WP_REST_Request $request ) {
	$timestamp = (int) $request->get_header( 'X-EC-Handoff-Timestamp' );
	$signature = (string) $request->get_header( 'X-EC-Handoff-Signature' );
	$input     = (array) $request->get_json_params();
	$expected  = extrachill_api_booking_attachment_delivery_callback_signature( $input, get_current_user_id(), $timestamp );
	$verified  = $timestamp > 0 && abs( time() - $timestamp ) <= 60 && '' !== $signature && hash_equals( $expected, $signature );

	return extrachill_api_is_local_request() && extrachill_api_booking_attachment_has_verified_internal_user() && $verified
		? true
		: extrachill_api_booking_attachment_download_error( 403 );
}

/** Issue one Events-owned descriptor before the streamed affinity request. */
function extrachill_api_issue_booking_attachment_handoff_request( WP_REST_Request $request ) {
	$descriptor = extrachill_api_issue_booking_attachment_descriptor(
		array(
			'booking_id'    => (int) $request->get_param( 'booking_id' ),
			'attachment_id' => (int) $request->get_param( 'attachment_id' ),
		)
	);

	return is_wp_error( $descriptor ) ? extrachill_api_booking_attachment_download_error( 502 ) : new WP_REST_Response( $descriptor, 200 );
}

/** Admit terminal callbacks only through localhost signed-user transport. */
function extrachill_api_booking_attachment_delivery_internal_permission( WP_REST_Request $request ) {
	$timestamp = (int) $request->get_header( 'X-EC-Delivery-Timestamp' );
	$signature = (string) $request->get_header( 'X-EC-Delivery-Signature' );
	$input     = (array) $request->get_json_params();
	$expected  = extrachill_api_booking_attachment_delivery_callback_signature( $input, get_current_user_id(), $timestamp );
	$verified  = $timestamp > 0 && abs( time() - $timestamp ) <= 60 && '' !== $signature && hash_equals( $expected, $signature );
	return extrachill_api_is_local_request() && extrachill_api_booking_attachment_has_verified_internal_user() && $verified
		? true
		: extrachill_api_booking_attachment_download_error( 403 );
}

/** Verify the canonical signed internal user independently of auth hook order. */
function extrachill_api_booking_attachment_has_verified_internal_user() {
	$user_id   = isset( $_SERVER['HTTP_X_EC_INTERNAL_USER'] ) ? (int) $_SERVER['HTTP_X_EC_INTERNAL_USER'] : 0;
	$timestamp = isset( $_SERVER['HTTP_X_EC_INTERNAL_TIMESTAMP'] ) ? (int) $_SERVER['HTTP_X_EC_INTERNAL_TIMESTAMP'] : 0;
	$signature = isset( $_SERVER['HTTP_X_EC_INTERNAL_SIGNATURE'] ) ? (string) $_SERVER['HTTP_X_EC_INTERNAL_SIGNATURE'] : '';

	return $user_id > 0
		&& get_current_user_id() === $user_id
		&& function_exists( 'ec_cross_site_verify_signature' )
		&& ec_cross_site_verify_signature( $user_id, $timestamp, $signature );
}

/** Sign one short-lived terminal callback independently of caller auth. */
function extrachill_api_booking_attachment_delivery_callback_signature( array $input, $user_id, $timestamp ) {
	$payload = wp_json_encode( extrachill_api_normalize_affinity_data( $input ) ) . "\n" . (int) $user_id . "\n" . (int) $timestamp;
	return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
}

/** Execute the Events-owned terminal callback on its owning site. */
function extrachill_api_record_booking_attachment_delivery_request( WP_REST_Request $request ) {
	$recorded = extrachill_api_record_booking_attachment_delivery_locally( (array) $request->get_json_params() );
	return $recorded
		? new WP_REST_Response( array( 'recorded' => true ), 200 )
		: extrachill_api_booking_attachment_download_error( 502 );
}

/** Require an authenticated caller without disclosing attachment existence. */
function extrachill_api_booking_attachment_download_permission() {
	return is_user_logged_in()
		? true
		: new WP_Error( 'booking_attachment_download_unavailable', 'The requested attachment is unavailable.', array( 'status' => 401 ) );
}

/**
 * Issue and immediately consume an Events-owned one-time handoff.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_download_booking_attachment( WP_REST_Request $request ) {
	if ( 'GET' !== $request->get_method() ) {
		return extrachill_api_booking_attachment_download_error( 405 );
	}
	if ( null !== $request->get_param( '_envelope' ) ) {
		return extrachill_api_booking_attachment_download_error( 400 );
	}

	$rate_limit = extrachill_api_check_booking_attachment_download_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return extrachill_api_booking_attachment_download_error( 503 );
	}

	$input      = array(
		'booking_id'    => (int) $request->get_param( 'booking_id' ),
		'attachment_id' => (int) $request->get_param( 'attachment_id' ),
	);
	$context    = function_exists( 'extrachill_api_route_affinity_context' ) ? extrachill_api_route_affinity_context( $request ) : array();
	$descriptor = is_array( $context['download_handoff'] ?? null ) ? $context['download_handoff'] : extrachill_api_issue_booking_attachment_descriptor( $input );
	if ( is_wp_error( $descriptor ) ) {
		return extrachill_api_booking_attachment_download_error( extrachill_api_booking_attachment_error_status( $descriptor ) );
	}

	$token          = $descriptor['stream_token'] ?? null;
	$correlation_id = $descriptor['correlation_id'] ?? null;
	if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) || ! is_string( $correlation_id ) || ! wp_is_uuid( $correlation_id, 4 ) ) {
		return extrachill_api_booking_attachment_download_error( 502 );
	}

	$service_class = '\\ExtraChillEvents\\Core\\BookingAttachmentService';
	if ( ! class_exists( $service_class ) ) {
		return extrachill_api_booking_attachment_download_error( 503 );
	}

	$service = new $service_class();
	$stream  = $service->open_download_stream(
		$input['booking_id'],
		$input['attachment_id'],
		$token,
		get_current_user_id(),
		$correlation_id
	);
	if ( is_wp_error( $stream ) || ! is_resource( $stream ) ) {
		if ( is_resource( $stream ) ) {
			fclose( $stream );
		}
		return extrachill_api_booking_attachment_download_error( extrachill_api_booking_attachment_error_status( $stream ) );
	}

	$delivery = array(
		'booking_id'     => $input['booking_id'],
		'attachment_id'  => $input['attachment_id'],
		'correlation_id' => $correlation_id,
	);
	$response = extrachill_api_create_private_stream_response(
		$stream,
		(string) ( $descriptor['filename'] ?? '' ),
		(string) ( $descriptor['mime_type'] ?? '' ),
		$request,
		$delivery
	);
	if ( is_wp_error( $response ) ) {
		if ( function_exists( 'extrachill_api_route_affinity_context' ) && extrachill_api_route_affinity_context( $request ) ) {
			$response = extrachill_api_booking_attachment_affinity_error( $response, $delivery, $request );
		} else {
			extrachill_api_record_booking_attachment_delivery( $delivery, 'failed', 0 );
		}
	}

	return $response;
}

/** Authorize and issue one Events-owned download descriptor. */
function extrachill_api_issue_booking_attachment_descriptor( array $input ) {
	$ability = wp_get_ability( 'extrachill/download-booking-attachment' );
	if ( ! $ability ) {
		return new WP_Error( 'booking_attachment_descriptor_unavailable' );
	}
	$allowed = $ability->check_permissions( $input );
	if ( true !== $allowed ) {
		return is_wp_error( $allowed ) ? $allowed : new WP_Error( 'booking_attachment_descriptor_forbidden', '', array( 'status' => 404 ) );
	}
	$descriptor = $ability->execute( $input );

	return is_array( $descriptor ) ? $descriptor : ( is_wp_error( $descriptor ) ? $descriptor : new WP_Error( 'booking_attachment_descriptor_invalid' ) );
}

/** Obtain the private descriptor before opening the streamed loopback. */
function extrachill_api_prepare_booking_attachment_affinity_handoff( WP_REST_Request $request ) {
	$input     = array(
		'booking_id'    => (int) $request->get_param( 'booking_id' ),
		'attachment_id' => (int) $request->get_param( 'attachment_id' ),
	);
	$timestamp = time();
	$result    = ec_cross_site_rest_request_http(
		'events',
		'POST',
		'/events/internal/booking-attachment-handoff',
		array(
			'body'    => $input,
			'headers' => array(
				'X-EC-Handoff-Timestamp' => (string) $timestamp,
				'X-EC-Handoff-Signature' => extrachill_api_booking_attachment_delivery_callback_signature( $input, get_current_user_id(), $timestamp ),
			),
			'user_id' => get_current_user_id(),
			'timeout' => 10,
		)
	);

	return extrachill_api_validate_booking_attachment_handoff( $result ) ? $result : extrachill_api_booking_attachment_download_error( 502 );
}

/** Validate the exact opaque handoff shape carried inside affinity HMAC. */
function extrachill_api_validate_booking_attachment_handoff( $handoff ) {
	return is_array( $handoff )
		&& is_string( $handoff['stream_token'] ?? null )
		&& 1 === preg_match( '/^[a-f0-9]{64}$/', $handoff['stream_token'] )
		&& is_string( $handoff['correlation_id'] ?? null )
		&& wp_is_uuid( $handoff['correlation_id'], 4 );
}

/** Encode a bounded internal handoff for one signed affinity hop. */
function extrachill_api_encode_booking_attachment_handoff( array $handoff ) {
	return rtrim( strtr( base64_encode( (string) wp_json_encode( $handoff ) ), '+/', '-_' ), '=' );
}

/** Decode only a valid handoff after affinity HMAC verification. */
function extrachill_api_decode_booking_attachment_handoff( $encoded ) {
	$encoded = (string) $encoded;
	if ( '' === $encoded || strlen( $encoded ) > 4096 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $encoded ) ) {
		return null;
	}
	$padding = strlen( $encoded ) % 4;
	if ( $padding ) {
		$encoded .= str_repeat( '=', 4 - $padding );
	}
	$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );
	$handoff = is_string( $decoded ) ? json_decode( $decoded, true ) : null;

	return extrachill_api_validate_booking_attachment_handoff( $handoff ) ? $handoff : null;
}

/** Record one Events-owned terminal delivery outcome without exposing it. */
function extrachill_api_record_booking_attachment_delivery( array $delivery, $outcome, $bytes_sent ) {
	$input = array(
		'booking_id'     => (int) ( $delivery['booking_id'] ?? 0 ),
		'attachment_id'  => (int) ( $delivery['attachment_id'] ?? 0 ),
		'correlation_id' => (string) ( $delivery['correlation_id'] ?? '' ),
		'outcome'        => (string) $outcome,
		'bytes_sent'     => max( 0, (int) $bytes_sent ),
	);
	if ( extrachill_api_record_booking_attachment_delivery_locally( $input ) ) {
		return true;
	}
	if ( ! function_exists( 'ec_cross_site_rest_request_http' ) || get_current_user_id() < 1 ) {
		return false;
	}
	$timestamp = time();
	$result    = ec_cross_site_rest_request_http(
		'events',
		'POST',
		'/events/internal/booking-attachment-delivery',
		array(
			'body'    => $input,
			'headers' => array(
				'X-EC-Delivery-Timestamp' => (string) $timestamp,
				'X-EC-Delivery-Signature' => extrachill_api_booking_attachment_delivery_callback_signature( $input, get_current_user_id(), $timestamp ),
			),
			'user_id' => get_current_user_id(),
			'timeout' => 10,
		)
	);

	return is_array( $result ) && true === ( $result['recorded'] ?? false );
}

/** Record a terminal delivery when the Events ability is loaded locally. */
function extrachill_api_record_booking_attachment_delivery_locally( array $input ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return false;
	}
	$ability = wp_get_abilities()['extrachill/record-booking-attachment-delivery'] ?? null;
	if ( ! $ability ) {
		return false;
	}
	$allowed = $ability->check_permissions( $input );
	if ( true !== $allowed ) {
		return false;
	}

	return ! is_wp_error( $ability->execute( $input ) );
}

/**
 * Return a stable public status without forwarding domain error details.
 *
 * @param mixed $error Domain result.
 * @return int
 */
function extrachill_api_booking_attachment_error_status( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return 502;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	if ( in_array( $status, array( 401, 403, 404, 409, 410 ), true ) ) {
		return 404;
	}

	return in_array( $status, array( 429, 502, 503 ), true ) ? $status : 502;
}

/** Build a generic, non-cacheable download error. */
function extrachill_api_booking_attachment_download_error( $status ) {
	$response = new WP_Error(
		'booking_attachment_download_unavailable',
		'The requested attachment is unavailable.',
		array( 'status' => (int) $status )
	);

	return $response;
}

/** Apply an atomic fixed-window per-user cap before Events issues a handoff. */
function extrachill_api_check_booking_attachment_download_rate_limit() {
	$limit = (int) apply_filters( 'extrachill_api_booking_attachment_download_rate_limit', 30 );
	if ( $limit < 1 ) {
		return true;
	}

	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return extrachill_api_booking_attachment_download_error( 401 );
	}

	$now         = time();
	$window      = intdiv( $now, MINUTE_IN_SECONDS );
	$retry_after = ( ( $window + 1 ) * MINUTE_IN_SECONDS ) - $now;
	$key         = 'booking_dl_' . substr( hash_hmac( 'sha256', $user_id . ':' . $window, wp_salt( 'nonce' ) ), 0, 40 );
	$admitted    = extrachill_api_atomic_rate_limit_admit( $key, $limit, $retry_after );
	if ( is_wp_error( $admitted ) ) {
		return extrachill_api_booking_attachment_download_error( 503 );
	}
	if ( ! $admitted ) {
		return new WP_Error(
			'booking_attachment_download_rate_limited',
			'Too many attachment download requests.',
			array(
				'status'      => 429,
				'retry_after' => $retry_after,
				'headers'     => array( 'Retry-After' => (string) $retry_after ),
			)
		);
	}
	return true;
}

/** Return the transport byte ceiling, bounded by Events' hard policy ceiling. */
function extrachill_api_booking_attachment_max_bytes() {
	$maximum = (int) apply_filters( 'extrachill_api_booking_attachment_max_bytes', 20 * MB_IN_BYTES );

	return max( 1, min( 20 * MB_IN_BYTES, $maximum ) );
}

/**
 * Validate metadata and register a local Events stream for manual REST serving.
 *
 * @param resource        $stream   Opened Events-owned stream.
 * @param string          $filename Events-provided filename.
 * @param string          $mime     Events-provided MIME type.
 * @param WP_REST_Request $request  REST request.
 * @param array|null      $delivery Events delivery correlation context.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_create_private_stream_response( $stream, $filename, $mime, WP_REST_Request $request, $delivery = null ) {
	$stat = fstat( $stream );
	$size = false !== $stat ? (int) $stat['size'] : -1;
	if ( $size < 0 || $size > extrachill_api_booking_attachment_max_bytes() ) {
		fclose( $stream );
		return extrachill_api_booking_attachment_download_error( 413 );
	}

	$range = extrachill_api_booking_attachment_range( $request->get_header( 'Range' ), $size );
	if ( is_wp_error( $range ) ) {
		fclose( $stream );
		return $range;
	}
	if ( 0 !== $range['offset'] && 0 !== fseek( $stream, $range['offset'] ) ) {
		fclose( $stream );
		return extrachill_api_booking_attachment_download_error( 502 );
	}

	$headers = extrachill_api_private_stream_headers( $filename, $mime, $range['length'] );
	if ( 206 === $range['status'] ) {
		$headers['Content-Range'] = sprintf( 'bytes %d-%d/%d', $range['offset'], $range['offset'] + $range['length'] - 1, $size );
	}
	if ( is_array( $delivery ) ) {
		$delivery['success_outcome'] = $range['length'] === $size ? 'completed' : 'partial';
	}

	$affinity_context = function_exists( 'extrachill_api_route_affinity_context' ) ? extrachill_api_route_affinity_context( $request ) : array();
	if ( $affinity_context && is_array( $delivery ) ) {
		$headers  = array_merge( $headers, extrachill_api_booking_attachment_affinity_delivery_headers( $delivery, $affinity_context ) );
		$delivery = null;
	}

	return extrachill_api_register_private_stream( $stream, $range['length'], $range['status'], $headers, null, $delivery );
}

/**
 * Parse one bounded HTTP byte range.
 *
 * @param string $header Range header.
 * @param int    $size   Full stream size.
 * @return array|WP_Error
 */
function extrachill_api_booking_attachment_range( $header, $size ) {
	if ( '' === $header ) {
		return array(
			'offset' => 0,
			'length' => $size,
			'status' => 200,
		);
	}

	if ( $size < 1 || 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $match ) || ( '' === $match[1] && '' === $match[2] ) ) {
		return extrachill_api_booking_attachment_range_error( $size );
	}

	if ( '' === $match[1] ) {
		$suffix = (int) $match[2];
		if ( $suffix < 1 ) {
			return extrachill_api_booking_attachment_range_error( $size );
		}
		$length = min( $suffix, $size );
		$offset = $size - $length;
	} else {
		$offset = (int) $match[1];
		$end    = '' === $match[2] ? $size - 1 : min( (int) $match[2], $size - 1 );
		if ( $offset >= $size || $end < $offset ) {
			return extrachill_api_booking_attachment_range_error( $size );
		}
		$length = $end - $offset + 1;
	}

	return array(
		'offset' => $offset,
		'length' => $length,
		'status' => 206,
	);
}

/** Return a non-cacheable RFC 9110 unsatisfied range response. */
function extrachill_api_booking_attachment_range_error( $size ) {
	return new WP_Error(
		'booking_attachment_range_unsatisfiable',
		'The requested byte range is unavailable.',
		array(
			'status'  => 416,
			'headers' => array( 'Content-Range' => 'bytes */' . max( 0, (int) $size ) ),
		)
	);
}

/** Build headers that keep private bytes out of browsers and intermediary caches. */
function extrachill_api_private_stream_headers( $filename, $mime, $length ) {
	$safe_filename = sanitize_file_name( wp_basename( $filename ) );
	if ( '' === $safe_filename ) {
		$safe_filename = 'attachment.bin';
	}
	$fallback = preg_replace( '/[^A-Za-z0-9._-]/', '_', $safe_filename );
	$fallback = is_string( $fallback ) ? $fallback : 'attachment.bin';
	$mime     = 1 === preg_match( '#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', $mime ) ? strtolower( $mime ) : 'application/octet-stream';

	$headers = array(
		'Accept-Ranges'          => 'bytes',
		'Cache-Control'          => 'private, no-store, no-cache, must-revalidate, max-age=0',
		'Content-Disposition'    => 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $safe_filename ),
		'Content-Length'         => (string) $length,
		'Content-Type'           => $mime,
		'Expires'                => '0',
		'Pragma'                 => 'no-cache',
		'Vary'                   => 'Authorization, Cookie',
		'X-Content-Type-Options' => 'nosniff',
		'X-Robots-Tag'           => 'noindex, nofollow, noarchive',
	);
	return $headers;
}

/** Build nonce-bound response metadata for the outer affinity worker only. */
function extrachill_api_booking_attachment_affinity_delivery_headers( array $delivery, array $context ) {
	$delivery = array(
		'booking_id'      => (int) ( $delivery['booking_id'] ?? 0 ),
		'attachment_id'   => (int) ( $delivery['attachment_id'] ?? 0 ),
		'correlation_id'  => (string) ( $delivery['correlation_id'] ?? '' ),
		'success_outcome' => (string) ( $delivery['success_outcome'] ?? 'completed' ),
	);
	$nonce    = (string) ( $context['nonce'] ?? '' );
	if ( $delivery['booking_id'] < 1 || $delivery['attachment_id'] < 1 || ! wp_is_uuid( $delivery['correlation_id'], 4 ) || ! in_array( $delivery['success_outcome'], array( 'completed', 'partial' ), true ) || '' === $nonce ) {
		return array();
	}
	$encoded   = rtrim( strtr( base64_encode( (string) wp_json_encode( $delivery ) ), '+/', '-_' ), '=' );
	$signature = hash_hmac( 'sha256', $encoded . "\n" . $nonce, wp_salt( 'auth' ) );

	return array(
		'X-EC-Affinity-Delivery'           => $encoded,
		'X-EC-Affinity-Delivery-Signature' => $signature,
	);
}

/** Attach internal delivery metadata to an affinity-only error response. */
function extrachill_api_booking_attachment_affinity_error( WP_Error $error, array $delivery, WP_REST_Request $request ) {
	$response = rest_convert_error_to_response( $error );
	$context  = extrachill_api_route_affinity_context( $request );
	foreach ( extrachill_api_booking_attachment_affinity_delivery_headers( $delivery, $context ) as $name => $value ) {
		$response->header( $name, $value );
	}

	return $response;
}

/** Verify and decode internal response metadata without forwarding its headers. */
function extrachill_api_booking_attachment_affinity_delivery_from_headers( array $headers, $nonce ) {
	$lower     = array_change_key_case( $headers, CASE_LOWER );
	$encoded   = (string) ( $lower['x-ec-affinity-delivery'] ?? '' );
	$signature = (string) ( $lower['x-ec-affinity-delivery-signature'] ?? '' );
	$expected  = hash_hmac( 'sha256', $encoded . "\n" . (string) $nonce, wp_salt( 'auth' ) );
	if ( '' === $encoded || '' === $signature || ! hash_equals( $expected, $signature ) ) {
		return null;
	}
	$padding = strlen( $encoded ) % 4;
	if ( $padding ) {
		$encoded .= str_repeat( '=', 4 - $padding );
	}
	$decoded  = base64_decode( strtr( $encoded, '-_', '+/' ), true );
	$delivery = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
	if ( ! is_array( $delivery ) || array( 'booking_id', 'attachment_id', 'correlation_id', 'success_outcome' ) !== array_keys( $delivery ) || (int) $delivery['booking_id'] < 1 || (int) $delivery['attachment_id'] < 1 || ! wp_is_uuid( (string) $delivery['correlation_id'], 4 ) || ! in_array( $delivery['success_outcome'], array( 'completed', 'partial' ), true ) ) {
		return null;
	}

	return array(
		'booking_id'      => (int) $delivery['booking_id'],
		'attachment_id'   => (int) $delivery['attachment_id'],
		'correlation_id'  => (string) $delivery['correlation_id'],
		'success_outcome' => (string) $delivery['success_outcome'],
	);
}

/** Keep every success and failure for this private route out of caches. */
function extrachill_api_protect_booking_attachment_download_response( $response, $server, $request ) {
	if ( function_exists( 'extrachill_api_booking_transport_error_headers' ) ) {
		$response = extrachill_api_booking_transport_error_headers( $response, $server, $request );
	}
	unset( $server );
	if ( ! extrachill_api_is_booking_attachment_download_route( $request->get_route() ) ) {
		return $response;
	}

	$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Expires', '0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'Vary', 'Authorization, Cookie' );
	$response->header( 'X-Content-Type-Options', 'nosniff' );
	$response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );

	return $response;
}

/**
 * Register an exact stream response without placing its resource in REST data.
 *
 * @param resource    $stream       Open stream.
 * @param int         $length       Bytes to emit.
 * @param int         $status       HTTP status.
 * @param array       $headers      Safe response headers.
 * @param string|null $cleanup_path Optional affinity spool to unlink.
 * @param array|null  $delivery     Events delivery correlation context.
 * @return WP_REST_Response
 */
function extrachill_api_register_private_stream( $stream, $length, $status, array $headers, $cleanup_path = null, $delivery = null ) {
	$response = new WP_REST_Response( null, $status, $headers );
	$key      = spl_object_id( $response );
	$GLOBALS['extrachill_api_private_streams'][ $key ] = array(
		'stream'       => $stream,
		'length'       => (int) $length,
		'cleanup_path' => is_string( $cleanup_path ) ? $cleanup_path : null,
		'delivery'     => is_array( $delivery ) ? $delivery : null,
	);
	extrachill_api_register_private_stream_shutdown_cleanup();

	return $response;
}

/** Register one request-level safety net for aborted/private stream responses. */
function extrachill_api_register_private_stream_shutdown_cleanup() {
	if ( ! empty( $GLOBALS['extrachill_api_private_stream_cleanup_registered'] ) ) {
		return;
	}
	$GLOBALS['extrachill_api_private_stream_cleanup_registered'] = true;
	register_shutdown_function( 'extrachill_api_cleanup_private_streams' );
}

/** Close every pending resource and unlink every private affinity spool. */
function extrachill_api_cleanup_private_streams() {
	$streams = is_array( $GLOBALS['extrachill_api_private_streams'] ?? null ) ? $GLOBALS['extrachill_api_private_streams'] : array();
	foreach ( $streams as $entry ) {
		if ( is_resource( $entry['stream'] ?? null ) ) {
			fclose( $entry['stream'] );
		}
		$path = $entry['cleanup_path'] ?? null;
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			unlink( $path );
		}
		if ( is_array( $entry['delivery'] ?? null ) ) {
			extrachill_api_record_booking_attachment_delivery( $entry['delivery'], 'interrupted', 0 );
		}
	}
	$GLOBALS['extrachill_api_private_streams'] = array();
}

/**
 * Emit a registered private stream after WordPress has sent its safe headers.
 *
 * @param bool             $served  Whether already served.
 * @param WP_HTTP_Response $result  REST response.
 * @param WP_REST_Request  $request REST request.
 * @param WP_REST_Server   $server  REST server.
 * @return bool
 */
function extrachill_api_serve_private_stream( $served, $result, $request, $server ) {
	unset( $request, $server );
	if ( $served ) {
		return $served;
	}

	$key = spl_object_id( $result );
	if ( ! isset( $GLOBALS['extrachill_api_private_streams'][ $key ] ) ) {
		return $served;
	}

	$entry = $GLOBALS['extrachill_api_private_streams'][ $key ];
	unset( $GLOBALS['extrachill_api_private_streams'][ $key ] );
	$stream      = $entry['stream'];
	$remaining   = max( 0, (int) $entry['length'] );
	$bytes_sent  = 0;
	$read_failed = false;
	try {
		while ( $remaining > 0 && is_resource( $stream ) && ! feof( $stream ) ) {
			$chunk = fread( $stream, min( 64 * KB_IN_BYTES, $remaining ) );
			if ( false === $chunk ) {
				$read_failed = true;
				break;
			}
			if ( '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified private binary transport.
			$remaining  -= strlen( $chunk );
			$bytes_sent += strlen( $chunk );
			if ( connection_aborted() ) {
				break;
			}
		}
	} finally {
		if ( is_resource( $stream ) ) {
			fclose( $stream );
		}
		$path = $entry['cleanup_path'] ?? null;
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			unlink( $path );
		}
		if ( is_array( $entry['delivery'] ?? null ) ) {
			if ( 0 === $remaining && ! $read_failed ) {
				$outcome = (string) ( $entry['delivery']['success_outcome'] ?? 'completed' );
			} elseif ( $bytes_sent > 0 ) {
				$outcome = 'partial';
			} elseif ( connection_aborted() ) {
				$outcome = 'interrupted';
			} else {
				$outcome = 'failed';
			}
			extrachill_api_record_booking_attachment_delivery( $entry['delivery'], $outcome, $bytes_sent );
		}
	}

	return true;
}

/** Return whether a route is the protected binary transport. */
function extrachill_api_is_booking_attachment_download_route( $route ) {
	return 1 === preg_match( '#^/extrachill/v1/events/bookings/\d+/attachments/\d+/download$#', (string) $route );
}

/**
 * Convert a bounded affinity spool into the same manual stream response.
 *
 * @param array  $http_response Raw WordPress HTTP response.
 * @param string $path          Private temporary spool path.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_private_stream_from_affinity_response( array $http_response, $path, $nonce ) {
	$status   = (int) wp_remote_retrieve_response_code( $http_response );
	$headers  = wp_remote_retrieve_headers( $http_response );
	$headers  = $headers instanceof Traversable ? iterator_to_array( $headers ) : (array) $headers;
	$delivery = extrachill_api_booking_attachment_affinity_delivery_from_headers( $headers, $nonce );
	if ( ! in_array( $status, array( 200, 206 ), true ) ) {
		return extrachill_api_fail_private_affinity_stream( $path, $delivery, extrachill_api_booking_attachment_proxy_status( $status ) );
	}
	if ( ! is_array( $delivery ) ) {
		extrachill_api_discard_private_affinity_spool( $path );
		return extrachill_api_booking_attachment_download_error( 502 );
	}

	$size = is_file( $path ) ? filesize( $path ) : false;
	if ( false === $size || $size > extrachill_api_booking_attachment_max_bytes() ) {
		return extrachill_api_fail_private_affinity_stream( $path, $delivery );
	}

	$declared = isset( $headers['content-length'] ) ? (int) $headers['content-length'] : ( isset( $headers['Content-Length'] ) ? (int) $headers['Content-Length'] : -1 );
	if ( $declared !== (int) $size ) {
		return extrachill_api_fail_private_affinity_stream( $path, $delivery );
	}

	$disposition = (string) ( $headers['content-disposition'] ?? ( $headers['Content-Disposition'] ?? '' ) );
	$filename    = extrachill_api_filename_from_content_disposition( $disposition );
	$mime        = (string) ( $headers['content-type'] ?? ( $headers['Content-Type'] ?? '' ) );
	$safe        = extrachill_api_private_stream_headers( $filename, $mime, $size );
	if ( 206 === $status ) {
		$content_range = (string) ( $headers['content-range'] ?? ( $headers['Content-Range'] ?? '' ) );
		if ( 1 !== preg_match( '/^bytes (\d+)-(\d+)\/(\d+)$/', $content_range, $range ) ) {
			return extrachill_api_fail_private_affinity_stream( $path, $delivery );
		}
		$start = (int) $range[1];
		$end   = (int) $range[2];
		$total = (int) $range[3];
		if ( $total < 1 || $start < 0 || $start > $end || $end >= $total || $end - $start + 1 !== (int) $size || $total > extrachill_api_booking_attachment_max_bytes() ) {
			return extrachill_api_fail_private_affinity_stream( $path, $delivery );
		}
		$safe['Content-Range'] = $content_range;
	}

	$stream = fopen( $path, 'rb' );
	$stream = apply_filters( 'extrachill_api_private_affinity_stream', $stream, $path );
	if ( false === $stream ) {
		return extrachill_api_fail_private_affinity_stream( $path, $delivery );
	}

	return extrachill_api_register_private_stream( $stream, $size, $status, $safe, $path, $delivery );
}

/** Finalize a consumed handoff without exposing callback or spool failures. */
function extrachill_api_fail_private_affinity_stream( $path, $delivery, $status = 502 ) {
	if ( is_array( $delivery ) ) {
		$bytes = extrachill_api_private_affinity_spool_bytes( $path );
		if ( ! is_wp_error( $bytes ) ) {
			try {
				extrachill_api_record_booking_attachment_delivery( $delivery, $bytes > 0 ? 'partial' : 'failed', $bytes );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
			}
		}
	}
	extrachill_api_discard_private_affinity_spool( $path );

	return extrachill_api_booking_attachment_download_error( $status );
}

/** Return exact bounded spool bytes, rejecting counts that cannot be trusted. */
function extrachill_api_private_affinity_spool_bytes( $path ) {
	if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) {
		return 0;
	}
	$size = filesize( $path );
	if ( false === $size || $size > extrachill_api_booking_attachment_max_bytes() ) {
		return new WP_Error( 'booking_attachment_spool_size_invalid' );
	}

	return (int) $size;
}

/** Extract and sanitize only a presentation filename from Content-Disposition. */
function extrachill_api_filename_from_content_disposition( $header ) {
	$filename = '';
	if ( 1 === preg_match( "/filename\*=UTF-8''([^;]+)/i", $header, $match ) ) {
		$filename = rawurldecode( $match[1] );
	} elseif ( 1 === preg_match( '/filename="?([^";]+)"?/i', $header, $match ) ) {
		$filename = $match[1];
	}

	return sanitize_file_name( wp_basename( $filename ) );
}

/** Map a downstream private transport status without exposing its response. */
function extrachill_api_booking_attachment_proxy_status( $status ) {
	if ( in_array( (int) $status, array( 401, 403, 404, 409, 410 ), true ) ) {
		return 404;
	}

	return in_array( (int) $status, array( 413, 416, 429, 502, 503 ), true ) ? (int) $status : 502;
}

/** Close and unlink an affinity spool on every failure path. */
function extrachill_api_discard_private_affinity_spool( $path ) {
	if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
		unlink( $path );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.unlink_unlink
