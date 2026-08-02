<?php
/**
 * Privacy-safe public venue booking availability transport.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY = 'extrachill/check-booking-availability';

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_booking_availability_route' );

/** Register the Events-affine public availability facade. */
function extrachill_api_register_booking_availability_route() {
	register_rest_route(
		'extrachill/v1',
		'/venues/(?P<venue>\d+)/booking-availability',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_booking_availability',
			'permission_callback' => 'extrachill_api_booking_availability_permission',
		)
	);
}

/** Rate-limit advisory reads without consuming a Turnstile challenge. */
function extrachill_api_booking_availability_permission( WP_REST_Request $request ) {
	$limit  = (int) apply_filters( 'extrachill_api_booking_availability_rate_limit', 60 );
	$result = extrachill_api_check_public_read_rate_limit( $request, 'booking-availability', $limit );

	return is_wp_error( $result ) ? extrachill_api_booking_availability_public_error( $result ) : true;
}

/** Validate, invoke the hidden Events ability, and project one boolean. */
function extrachill_api_handle_booking_availability( WP_REST_Request $request ) {
	$identity = extrachill_api_booking_validate_canonical_identity();
	if ( is_wp_error( $identity ) ) {
		return extrachill_api_booking_availability_service_error();
	}

	$input = extrachill_api_booking_availability_input( $request );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$ability = wp_get_abilities()[ EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY ] ?? null;
	if ( ! $ability || false !== $ability->get_meta_item( 'show_in_rest' ) ) {
		return extrachill_api_booking_availability_service_error();
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return extrachill_api_booking_availability_public_error( $result );
	}
	if ( ! is_array( $result ) || ! is_bool( $result['available'] ?? null ) ) {
		return extrachill_api_booking_availability_service_error();
	}

	return new WP_REST_Response( array( 'available' => $result['available'] ), 200 );
}

/** Build only the exact hidden-ability input from an exact JSON body. */
function extrachill_api_booking_availability_input( WP_REST_Request $request ) {
	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) || array() !== $request->get_query_params() ) {
		return extrachill_api_booking_availability_validation_error();
	}
	$expected = array( 'venue', 'requested_space_key', 'requested_start_at', 'requested_end_at' );
	$keys     = array_keys( $body );
	sort( $expected );
	sort( $keys );
	if ( $expected !== $keys ) {
		return extrachill_api_booking_availability_validation_error();
	}

	$url_params = $request->get_url_params();
	$venue      = $url_params['venue'] ?? null;
	if ( ! is_int( $venue ) && ! ( is_string( $venue ) && ctype_digit( $venue ) ) ) {
		return extrachill_api_booking_availability_validation_error();
	}
	$venue = (int) $venue;
	if ( $venue < 1 || ! is_int( $body['venue'] ) || $body['venue'] !== $venue ) {
		return extrachill_api_booking_availability_validation_error();
	}

	$space_key = $body['requested_space_key'];
	$start_at  = $body['requested_start_at'];
	$end_at    = $body['requested_end_at'];
	if ( ! is_string( $space_key ) || '' === $space_key || strlen( $space_key ) > 64 || sanitize_key( $space_key ) !== $space_key ) {
		return extrachill_api_booking_availability_validation_error();
	}
	if ( ! extrachill_api_booking_availability_datetime_is_valid( $start_at ) || ! extrachill_api_booking_availability_datetime_is_valid( $end_at ) || $end_at <= $start_at ) {
		return extrachill_api_booking_availability_validation_error();
	}

	return array(
		'venue_term_id'       => $venue,
		'requested_space_key' => $space_key,
		'requested_start_at'  => $start_at,
		'requested_end_at'    => $end_at,
	);
}

/** Validate the canonical venue-local wall-clock representation. */
function extrachill_api_booking_availability_datetime_is_valid( $value ) {
	if ( ! is_string( $value ) ) {
		return false;
	}
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
	return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
}

/** Return the one fixed public request-validation response. */
function extrachill_api_booking_availability_validation_error() {
	return new WP_Error( 'booking_availability_invalid_request', __( 'A valid booking interval is required.', 'extrachill-api' ), array( 'status' => 400 ) );
}

/** Return the one retryable dependency/service response. */
function extrachill_api_booking_availability_service_error() {
	return new WP_Error(
		'booking_availability_unavailable',
		__( 'Booking availability is temporarily unavailable. Please try again.', 'extrachill-api' ),
		array(
			'status'    => 503,
			'retryable' => true,
		)
	);
}

/** Map only fixed public validation and limiter errors; hide all owner details. */
function extrachill_api_booking_availability_public_error( WP_Error $error ) {
	$code       = $error->get_error_code();
	$error_data = (array) $error->get_error_data();
	$status     = (int) ( $error_data['status'] ?? 0 );
	if ( 'booking_availability_interval_invalid' === $code || ( 'booking_availability_unavailable' === $code && 409 === $status ) ) {
		return extrachill_api_booking_availability_validation_error();
	}
	if ( 'public_read_rate_limited' === $code ) {
		$data = (array) $error->get_error_data();
		return new WP_Error(
			'public_read_rate_limited',
			__( 'Too many requests. Please try again later.', 'extrachill-api' ),
			array(
				'status'  => 429,
				'headers' => array( 'Retry-After' => (string) max( 1, (int) ( $data['retry_after'] ?? 60 ) ) ),
			)
		);
	}

	return extrachill_api_booking_availability_service_error();
}

/** Revalidate one affinity response before exposing it on the source site. */
function extrachill_api_booking_availability_affinity_response( $data, $status, $headers ) {
	if ( 200 === $status && is_array( $data ) && is_bool( $data['available'] ?? null ) ) {
		return new WP_REST_Response( array( 'available' => $data['available'] ), 200 );
	}
	if ( 400 === $status ) {
		return rest_convert_error_to_response( extrachill_api_booking_availability_validation_error() );
	}
	if ( 429 === $status ) {
		$retry_after = is_array( $headers ) ? ( $headers['retry-after'] ?? $headers['Retry-After'] ?? 60 ) : 60;
		$error       = new WP_Error(
			'public_read_rate_limited',
			'',
			array(
				'status'      => 429,
				'retry_after' => max( 1, (int) $retry_after ),
			)
		);
		return rest_convert_error_to_response( extrachill_api_booking_availability_public_error( $error ) );
	}

	return rest_convert_error_to_response( extrachill_api_booking_availability_service_error() );
}
