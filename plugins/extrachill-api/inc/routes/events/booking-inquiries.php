<?php
/**
 * Protected public venue booking inquiry admission.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_API_BOOKING_ABILITY             = 'extrachill/create-booking-inquiry';
const EXTRACHILL_API_BOOKING_STATUS_ABILITY      = 'extrachill-events/get-artist-booking-inquiry';
const EXTRACHILL_API_BOOKING_CORRECTION_ABILITY  = 'extrachill-events/request-artist-booking-correction';
const EXTRACHILL_API_BOOKING_WITHDRAWAL_ABILITY  = 'extrachill-events/withdraw-artist-booking-inquiry';
const EXTRACHILL_API_BOOKING_RECOVERY_ABILITY    = 'extrachill-events/recover-artist-booking-inquiry-receipt';
const EXTRACHILL_API_BOOKING_FILES               = '_ec_affinity_files';
const EXTRACHILL_API_BOOKING_MAX_FILES           = 5;
const EXTRACHILL_API_BOOKING_MAX_FILE_BYTES      = 20 * MB_IN_BYTES;
const EXTRACHILL_API_BOOKING_MAX_AGGREGATE_BYTES = 50 * MB_IN_BYTES;

add_filter( 'ec_route_site_affinity_map', 'extrachill_api_add_booking_route_affinity' );
add_filter( 'extrachill_api_route_affinity_signature_body', 'extrachill_api_booking_affinity_signature_body', 10, 2 );
add_filter( 'extrachill_api_route_affinity_file_forward', 'extrachill_api_booking_affinity_file_forward', 10, 5 );
add_filter( 'rest_post_dispatch', 'extrachill_api_booking_transport_error_headers', 20, 3 );
add_action( 'extrachill_api_register_routes', 'extrachill_api_register_booking_inquiry_route' );

/** Promote only stable booking error headers out of REST JSON metadata. */
function extrachill_api_booking_transport_error_headers( $response, $server, $request ) {
	unset( $server );
	$route = $request->get_route();
	if ( ! preg_match( '#^/extrachill/v1/(?:venues/\d+/(?:booking-inquiries(?:/follow-through/(?:status|correction|withdrawal|receipt-recovery))?|booking-availability)|events/bookings/\d+/attachments/\d+/download)$#', $route ) ) {
		return $response;
	}
	$data    = $response->get_data();
	$headers = is_array( $data ) && is_array( $data['data']['headers'] ?? null ) ? $data['data']['headers'] : array();
	foreach ( array( 'Retry-After', 'Content-Range' ) as $name ) {
		if ( isset( $headers[ $name ] ) && is_scalar( $headers[ $name ] ) ) {
			$response->header( $name, (string) $headers[ $name ] );
		}
	}
	if ( $headers ) {
		unset( $data['data']['headers'] );
		$response->set_data( $data );
	}

	return $response;
}

/** Route booking inquiries to the Events site on a segment boundary. */
function extrachill_api_add_booking_route_affinity( $affinity_map ) {
	$affinity_map['/extrachill/v1/venues/'] = 'events';
	return $affinity_map;
}

/** Register the protected public facade. */
function extrachill_api_register_booking_inquiry_route() {
	register_rest_route(
		'extrachill/v1',
		'/venues/(?P<venue>\d+)/booking-inquiries',
		array(
			'methods'               => WP_REST_Server::CREATABLE,
			'callback'              => 'extrachill_api_handle_booking_inquiry',
			'permission_callback'   => 'extrachill_api_booking_inquiry_permission',
			'args'                  => extrachill_api_booking_inquiry_args(),
			'_extrachill_abilities' => array( EXTRACHILL_API_BOOKING_ABILITY ),
		)
	);

	$routes = array(
		'status'           => array( EXTRACHILL_API_BOOKING_STATUS_ABILITY, 'extrachill_api_booking_follow_through_status_permission' ),
		'correction'       => array( EXTRACHILL_API_BOOKING_CORRECTION_ABILITY, 'extrachill_api_booking_follow_through_write_permission' ),
		'withdrawal'       => array( EXTRACHILL_API_BOOKING_WITHDRAWAL_ABILITY, 'extrachill_api_booking_follow_through_write_permission' ),
		'receipt-recovery' => array( EXTRACHILL_API_BOOKING_RECOVERY_ABILITY, 'extrachill_api_booking_follow_through_write_permission' ),
	);
	foreach ( $routes as $operation => $contract ) {
		register_rest_route(
			'extrachill/v1',
			'/venues/(?P<venue>\d+)/booking-inquiries/follow-through/' . $operation,
			array(
				'methods'               => WP_REST_Server::CREATABLE,
				'callback'              => 'extrachill_api_handle_booking_follow_through',
				'permission_callback'   => $contract[1],
				'args'                  => extrachill_api_booking_follow_through_args( $operation ),
				'_extrachill_abilities' => array( $contract[0] ),
			)
		);
	}
}

/** Return the strict transport schema for one artist follow-through operation. */
function extrachill_api_booking_follow_through_args( $operation ) {
	$args = array(
		'venue'     => array(
			'required' => true,
			'type'     => 'integer',
			'minimum'  => 1,
		),
		'public_id' => array(
			'required' => true,
			'type'     => 'string',
			'format'   => 'uuid',
		),
	);
	if ( 'receipt-recovery' !== $operation ) {
		$args['capability'] = array(
			'required'          => false,
			'type'              => 'string',
			'minLength'         => 64,
			'maxLength'         => 64,
			'validate_callback' => 'extrachill_api_validate_booking_capability',
		);
	}
	if ( in_array( $operation, array( 'correction', 'withdrawal' ), true ) ) {
		$args['expected_version'] = array(
			'required' => true,
			'type'     => 'integer',
			'minimum'  => 1,
		);
	}
	if ( 'status' !== $operation ) {
		$args['idempotency_key']    = array(
			'required'  => true,
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 120,
		);
		$args['turnstile_response'] = array(
			'required' => true,
			'type'     => 'string',
		);
	}
	if ( 'correction' === $operation ) {
		$args['correction'] = array(
			'required'  => true,
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 2000,
		);
	}
	if ( 'receipt-recovery' === $operation ) {
		$args['contact_email'] = array(
			'required'  => true,
			'type'      => 'string',
			'format'    => 'email',
			'maxLength' => 255,
		);
	}

	return $args;
}

/** Require the canonical lowercase anonymous booking capability form. */
function extrachill_api_validate_booking_capability( $value ) {
	return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
}

/** Return the facade schema, including transport-only fields. */
function extrachill_api_booking_inquiry_args() {
	$nullable_text = array(
		'required'          => false,
		'type'              => array( 'string', 'null' ),
		'sanitize_callback' => 'sanitize_text_field',
	);

	return array(
		'venue'               => array(
			'required' => true,
			'type'     => 'integer',
			'minimum'  => 1,
		),
		'idempotency_key'     => array(
			'required'          => true,
			'type'              => 'string',
			'minLength'         => 1,
			'maxLength'         => 191,
			'sanitize_callback' => 'sanitize_text_field',
		),
		'artist_term_id'      => array(
			'required' => false,
			'type'     => array( 'integer', 'null' ),
			'minimum'  => 1,
		),
		'artist_profile_id'   => array(
			'required' => false,
			'type'     => array( 'integer', 'null' ),
			'minimum'  => 1,
		),
		'artist_name'         => array(
			'required'          => false,
			'type'              => 'string',
			'minLength'         => 1,
			'maxLength'         => 255,
			'sanitize_callback' => 'sanitize_text_field',
		),
		'contact_name'        => array_merge( $nullable_text, array( 'maxLength' => 255 ) ),
		'contact_email'       => array_merge(
			$nullable_text,
			array(
				'format'            => 'email',
				'maxLength'         => 255,
				'sanitize_callback' => 'sanitize_email',
			)
		),
		'contact_phone'       => array_merge( $nullable_text, array( 'maxLength' => 64 ) ),
		'requested_space_key' => array_merge( $nullable_text, array( 'maxLength' => 64 ) ),
		'requested_start_at'  => array_merge( $nullable_text, array( 'pattern' => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$' ) ),
		'requested_end_at'    => array_merge( $nullable_text, array( 'pattern' => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$' ) ),
		'intake'              => array(
			'required'          => true,
			'type'              => array( 'object', 'string' ),
			'validate_callback' => 'extrachill_api_validate_booking_intake',
		),
		'attachment_purposes' => array(
			'required'          => false,
			'type'              => array( 'array', 'string' ),
			'validate_callback' => 'extrachill_api_validate_booking_attachment_purposes',
		),
		'turnstile_response'  => array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		),
	);
}

/** Validate JSON-or-object intake used by JSON and multipart clients. */
function extrachill_api_validate_booking_intake( $value ) {
	if ( is_array( $value ) ) {
		return true;
	}
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}
	$decoded = json_decode( $value, true );
	return is_array( $decoded ) && JSON_ERROR_NONE === json_last_error();
}

/** Validate the declared purpose list without duplicating Events policy. */
function extrachill_api_validate_booking_attachment_purposes( $value ) {
	if ( is_string( $value ) ) {
		$value = json_decode( $value, true );
	}
	if ( ! is_array( $value ) ) {
		return false;
	}
	foreach ( $value as $purpose ) {
		if ( ! is_string( $purpose ) || '' === sanitize_key( $purpose ) ) {
			return false;
		}
	}
	return true;
}

/** Enforce Turnstile before consuming the public-write rate budget. */
function extrachill_api_booking_inquiry_permission( WP_REST_Request $request ) {
	foreach ( array( 'user_id', 'submitter_user_id', 'uploader_user_id' ) as $identity_field ) {
		if ( null !== $request->get_param( $identity_field ) ) {
			return new WP_Error( 'booking_identity_not_allowed', __( 'Caller identity cannot be submitted as form data.', 'extrachill-api' ), array( 'status' => 400 ) );
		}
	}
	$turnstile = extrachill_api_booking_check_turnstile( $request );
	if ( is_wp_error( $turnstile ) ) {
		return $turnstile;
	}
	$limit = (int) apply_filters( 'extrachill_api_booking_inquiry_rate_limit', 10 );
	return extrachill_api_check_public_write_rate_limit( $request, 'booking-inquiry', $limit );
}

/** Verify Turnstile against only the trusted affinity client address. */
function extrachill_api_booking_check_turnstile( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_turnstile_check_request' ) ) {
		return new WP_Error( 'booking_security_unavailable', __( 'Security verification is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}
	$original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
	$affinity_context     = function_exists( 'extrachill_api_route_affinity_context' ) ? extrachill_api_route_affinity_context( $request ) : array();
	$affinity_remote_addr = (string) ( $affinity_context['remote_addr'] ?? '' );
	if ( '' !== $affinity_remote_addr && false !== filter_var( $affinity_remote_addr, FILTER_VALIDATE_IP ) ) {
		$_SERVER['REMOTE_ADDR'] = $affinity_remote_addr;
	}
	try {
		$turnstile = ec_turnstile_check_request( $request );
	} finally {
		if ( null === $original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $original_remote_addr;
		}
	}
	if ( is_wp_error( $turnstile ) ) {
		return extrachill_api_booking_public_error( $turnstile );
	}

	return true;
}

/** Apply bounded read admission before artist-safe status execution. */
function extrachill_api_booking_follow_through_status_permission( WP_REST_Request $request ) {
	$valid = extrachill_api_booking_follow_through_request_valid( $request, true );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$limit = (int) apply_filters( 'extrachill_api_booking_follow_through_read_rate_limit', 30 );
	return extrachill_api_check_public_read_rate_limit( $request, 'booking-follow-through-status', $limit );
}

/** Apply Turnstile and bounded write admission before artist mutations. */
function extrachill_api_booking_follow_through_write_permission( WP_REST_Request $request ) {
	$valid = extrachill_api_booking_follow_through_request_valid( $request, true );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$turnstile = extrachill_api_booking_check_turnstile( $request );
	if ( is_wp_error( $turnstile ) ) {
		return $turnstile;
	}
	$limit = (int) apply_filters( 'extrachill_api_booking_follow_through_write_rate_limit', 10 );
	return extrachill_api_check_public_write_rate_limit( $request, 'booking-follow-through-' . extrachill_api_booking_follow_through_operation( $request ), $limit );
}

/** Reject submitted authority, attestations, and capabilities outside the POST body. */
function extrachill_api_booking_follow_through_request_valid( WP_REST_Request $request, $reject_attestations ) {
	foreach ( array( 'user_id', 'submitter_user_id', 'uploader_user_id', 'actor_id', 'current_user_id' ) as $identity_field ) {
		if ( null !== $request->get_param( $identity_field ) ) {
			return new WP_Error( 'booking_identity_not_allowed', __( 'Caller identity cannot be submitted as form data.', 'extrachill-api' ), array( 'status' => 400 ) );
		}
	}
	if ( $reject_attestations ) {
		foreach ( array( 'contact_verified', 'rate_limit_consumed' ) as $attestation ) {
			if ( null !== $request->get_param( $attestation ) ) {
				return new WP_Error( 'booking_attestation_not_allowed', __( 'Internal booking attestations cannot be submitted.', 'extrachill-api' ), array( 'status' => 400 ) );
			}
		}
	}
	if ( array_key_exists( 'capability', $request->get_query_params() ) || null !== $request->get_header( 'X-Booking-Capability' ) ) {
		return new WP_Error( 'booking_capability_location_invalid', __( 'Booking capability values are accepted only in the POST body.', 'extrachill-api' ), array( 'status' => 400 ) );
	}

	return true;
}

/** Return only parsed POST body fields, never query-string fallbacks. */
function extrachill_api_booking_follow_through_body( WP_REST_Request $request ) {
	return $request->is_json_content_type() ? (array) $request->get_json_params() : (array) $request->get_body_params();
}

/** Resolve the operation from the fixed route suffix. */
function extrachill_api_booking_follow_through_operation( WP_REST_Request $request ) {
	return preg_match( '#/follow-through/(status|correction|withdrawal|receipt-recovery)$#', $request->get_route(), $matches ) ? $matches[1] : '';
}

/** Read venue affinity only from the matched route path. */
function extrachill_api_booking_follow_through_venue( WP_REST_Request $request ) {
	$url_params = $request->get_url_params();
	return absint( $url_params['venue'] ?? 0 );
}

/** Execute exactly one hidden Events artist ability with a strict input projection. */
function extrachill_api_handle_booking_follow_through( WP_REST_Request $request ) {
	$identity = extrachill_api_booking_validate_canonical_identity();
	if ( is_wp_error( $identity ) ) {
		return $identity;
	}
	$operation    = extrachill_api_booking_follow_through_operation( $request );
	$abilities    = array(
		'status'           => EXTRACHILL_API_BOOKING_STATUS_ABILITY,
		'correction'       => EXTRACHILL_API_BOOKING_CORRECTION_ABILITY,
		'withdrawal'       => EXTRACHILL_API_BOOKING_WITHDRAWAL_ABILITY,
		'receipt-recovery' => EXTRACHILL_API_BOOKING_RECOVERY_ABILITY,
	);
	$ability_name = $abilities[ $operation ] ?? '';
	$ability      = '' !== $ability_name ? wp_get_ability( $ability_name ) : null;
	if ( ! extrachill_api_booking_ability_is_hidden( $ability ) ) {
		return new WP_Error( 'booking_follow_through_unavailable', __( 'Booking inquiry access is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}
	$input = extrachill_api_booking_follow_through_input( $request, $operation );
	if ( is_wp_error( $input ) ) {
		return $input;
	}
	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return extrachill_api_booking_follow_through_error( $result, $operation );
	}
	if ( ! is_array( $result ) || ! isset( $result['venue_term_id'] ) || ! is_int( $result['venue_term_id'] ) || extrachill_api_booking_follow_through_venue( $request ) !== $result['venue_term_id'] ) {
		if ( 'receipt-recovery' === $operation ) {
			return extrachill_api_booking_recovery_accepted();
		}
		return extrachill_api_booking_follow_through_unavailable();
	}
	if ( 'receipt-recovery' === $operation ) {
		return extrachill_api_booking_recovery_accepted();
	}
	$projected = extrachill_api_booking_follow_through_response( $result, $operation );
	return is_wp_error( $projected ) ? $projected : new WP_REST_Response( $projected, 200 );
}

/** Require explicit hidden ability metadata and reject malformed providers. */
function extrachill_api_booking_ability_is_hidden( $ability ) {
	return is_object( $ability ) && is_callable( array( $ability, 'get_meta_item' ) ) && false === $ability->get_meta_item( 'show_in_rest', null );
}

/** Build only the Events ability's accepted fields from the POST body. */
function extrachill_api_booking_follow_through_input( WP_REST_Request $request, $operation ) {
	$body = extrachill_api_booking_follow_through_body( $request );
	if ( empty( $body['public_id'] ) ) {
		return new WP_Error( 'booking_follow_through_invalid_request', __( 'A booking inquiry reference is required.', 'extrachill-api' ), array( 'status' => 400 ) );
	}
	$venue = extrachill_api_booking_follow_through_venue( $request );
	if ( $venue < 1 ) {
		return extrachill_api_booking_follow_through_unavailable();
	}
	$input = array(
		'public_id'     => sanitize_text_field( (string) $body['public_id'] ),
		'venue_term_id' => $venue,
	);
	if ( 'status' !== $operation ) {
		$key = (string) ( $body['idempotency_key'] ?? '' );
		if ( '' === $key || strlen( $key ) > 120 || trim( $key ) !== $key || sanitize_text_field( $key ) !== $key ) {
			return new WP_Error( 'booking_idempotency_key_invalid', __( 'A valid idempotency key is required.', 'extrachill-api' ), array( 'status' => 400 ) );
		}
		$input['idempotency_key'] = $key;
	}
	if ( 'receipt-recovery' === $operation ) {
		$input['contact_email'] = sanitize_email( (string) ( $body['contact_email'] ?? '' ) );
		return $input;
	}
	if ( isset( $body['capability'] ) && '' !== $body['capability'] ) {
		$input['capability'] = sanitize_text_field( (string) $body['capability'] );
	}
	if ( in_array( $operation, array( 'correction', 'withdrawal' ), true ) ) {
		$input['expected_version'] = absint( $body['expected_version'] ?? 0 );
	}
	if ( 'correction' === $operation ) {
		$input['correction'] = mb_substr( sanitize_textarea_field( (string) ( $body['correction'] ?? '' ) ), 0, 2000 );
	}

	return $input;
}

/** Normalize and invoke the Events-owned inquiry and attachment contracts. */
function extrachill_api_handle_booking_inquiry( WP_REST_Request $request ) {
	$identity = extrachill_api_booking_validate_canonical_identity();
	if ( is_wp_error( $identity ) ) {
		return $identity;
	}
	$abilities = wp_get_abilities();
	$ability   = $abilities[ EXTRACHILL_API_BOOKING_ABILITY ] ?? null;
	if ( ! $ability || $ability->get_meta_item( 'show_in_rest' ) ) {
		return new WP_Error( 'booking_inquiry_unavailable', __( 'Booking inquiry processing is unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$files = extrachill_api_normalize_booking_files( $request );
	if ( is_wp_error( $files ) ) {
		return $files;
	}
	$input = extrachill_api_booking_ability_input( $request, $files );
	if ( is_wp_error( $input ) ) {
		return $input;
	}
	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return extrachill_api_booking_public_error( $result );
	}
	if ( ! is_array( $result ) || empty( $result['public_id'] ) ) {
		return new WP_Error( 'booking_inquiry_invalid_response', __( 'Booking inquiry processing returned an invalid response.', 'extrachill-api' ), array( 'status' => 502 ) );
	}

	return new WP_REST_Response( $result, 201 );
}

/** Accept anonymous callers or an existing user established by canonical auth. */
function extrachill_api_booking_validate_canonical_identity() {
	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return true;
	}
	$user = wp_get_current_user();
	if ( ! $user->exists() || (int) $user->ID !== $user_id ) {
		return new WP_Error( 'booking_authentication_invalid', __( 'The authenticated booking identity is invalid.', 'extrachill-api' ), array( 'status' => 401 ) );
	}

	return true;
}

/** Build only fields accepted by the hidden ability schema. */
function extrachill_api_booking_ability_input( WP_REST_Request $request, array $files ) {
	$intake = $request->get_param( 'intake' );
	if ( is_string( $intake ) ) {
		$intake = json_decode( $intake, true );
	}
	if ( ! is_array( $intake ) ) {
		return new WP_Error( 'booking_inquiry_invalid_intake', __( 'A valid intake object is required.', 'extrachill-api' ), array( 'status' => 400 ) );
	}
	$input = array(
		'idempotency_key' => sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) ),
		'venue_term_id'   => absint( $request->get_param( 'venue' ) ),
		'intake'          => $intake,
	);
	foreach ( array( 'artist_term_id', 'artist_profile_id' ) as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value && '' !== $value ) {
			$input[ $key ] = absint( $value );
		}
	}
	foreach ( array( 'artist_name', 'contact_name', 'contact_email', 'contact_phone', 'requested_space_key', 'requested_start_at', 'requested_end_at' ) as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value ) {
			$input[ $key ] = '' === $value ? null : $value;
		}
	}
	if ( $files ) {
		$input['attachments'] = array_map(
			static function ( array $file ) {
				return array(
					'name'     => $file['name'],
					'tmp_name' => $file['tmp_name'],
					'error'    => (int) $file['error'],
					'size'     => (int) $file['size'],
					'purpose'  => $file['purpose'],
				);
			},
			$files
		);
	}

	return $input;
}

/** Normalize PHP's single/multiple file shapes and bind declared purposes. */
function extrachill_api_normalize_booking_files( WP_REST_Request $request ) {
	$raw = $request->get_file_params();
	if ( empty( $raw['attachments'] ) ) {
		return array();
	}
	$upload = $raw['attachments'];
	$names  = is_array( $upload['name'] ?? null ) ? $upload['name'] : array( $upload['name'] ?? '' );
	if ( count( $names ) > EXTRACHILL_API_BOOKING_MAX_FILES ) {
		return new WP_Error( 'booking_attachment_count_invalid', __( 'Too many booking attachments were submitted.', 'extrachill-api' ), array( 'status' => 400 ) );
	}
	$files          = array();
	$aggregate_size = 0;
	foreach ( array_keys( $names ) as $index ) {
		$file = array();
		foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $key ) {
			$file[ $key ] = is_array( $upload[ $key ] ?? null ) ? ( $upload[ $key ][ $index ] ?? null ) : ( $upload[ $key ] ?? null );
		}
		if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			continue;
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || '' === (string) $file['tmp_name'] || ! is_readable( $file['tmp_name'] ) || ( ! is_uploaded_file( $file['tmp_name'] ) && ! apply_filters( 'extrachill_api_allow_test_booking_file', false, $file ) ) ) {
			return new WP_Error( 'booking_attachment_upload_failed', __( 'A booking attachment could not be received.', 'extrachill-api' ), array( 'status' => 400 ) );
		}
		$actual_size = filesize( $file['tmp_name'] );
		if ( false === $actual_size || (int) $file['size'] !== $actual_size || $actual_size < 1 || $actual_size > EXTRACHILL_API_BOOKING_MAX_FILE_BYTES ) {
			return new WP_Error( 'booking_attachment_size_invalid', __( 'A booking attachment is outside the allowed size.', 'extrachill-api' ), array( 'status' => 413 ) );
		}
		$aggregate_size += $actual_size;
		if ( $aggregate_size > EXTRACHILL_API_BOOKING_MAX_AGGREGATE_BYTES ) {
			return new WP_Error( 'booking_attachment_aggregate_size_invalid', __( 'The combined booking attachments are too large.', 'extrachill-api' ), array( 'status' => 413 ) );
		}
		$file['name']   = sanitize_file_name( (string) $file['name'] );
		$file['size']   = $actual_size;
		$file['sha256'] = hash_file( 'sha256', $file['tmp_name'] );
		if ( false === $file['sha256'] ) {
			return new WP_Error( 'booking_attachment_upload_failed', __( 'A booking attachment could not be received.', 'extrachill-api' ), array( 'status' => 400 ) );
		}
		$files[] = $file;
	}

	$purposes = $request->get_param( 'attachment_purposes' );
	if ( is_string( $purposes ) ) {
		$purposes = json_decode( $purposes, true );
	}
	if ( count( $files ) !== count( (array) $purposes ) ) {
		return new WP_Error( 'booking_attachment_purpose_mismatch', __( 'Each booking attachment requires one purpose.', 'extrachill-api' ), array( 'status' => 400 ) );
	}
	foreach ( $files as $index => &$file ) {
		$file['purpose'] = sanitize_key( $purposes[ $index ] );
	}
	unset( $file );
	$affinity_context = function_exists( 'extrachill_api_route_affinity_context' ) ? extrachill_api_route_affinity_context( $request ) : array();
	if ( $affinity_context ) {
		$expected = json_decode( (string) $request->get_param( EXTRACHILL_API_BOOKING_FILES ), true );
		if ( ! is_array( $expected ) || extrachill_api_booking_file_descriptors( $files ) !== $expected ) {
			return new WP_Error( 'booking_attachment_transport_invalid', __( 'The booking attachment transport could not be verified.', 'extrachill-api' ), array( 'status' => 400 ) );
		}
	}

	return $files;
}

/** Return only the operation-specific artist-safe response fields. */
function extrachill_api_booking_follow_through_response( array $result, $operation ) {
	$allowlists = array(
		'status'     => array( 'public_id', 'venue', 'submitted_at', 'updated_at', 'status', 'status_label', 'version', 'requested_interval', 'requested_space', 'permitted_actions' ),
		'correction' => array( 'public_id', 'operation', 'version' ),
		'withdrawal' => array( 'public_id', 'operation', 'status', 'status_label', 'version' ),
	);
	$required   = array(
		'status'     => array( 'public_id', 'venue', 'submitted_at', 'updated_at', 'status', 'status_label', 'version', 'requested_interval', 'requested_space', 'permitted_actions' ),
		'correction' => array( 'public_id', 'operation', 'version' ),
		'withdrawal' => array( 'public_id', 'operation', 'version' ),
	);
	if ( ! isset( $allowlists[ $operation ] ) || array_diff( $required[ $operation ], array_keys( $result ) ) ) {
		return extrachill_api_booking_follow_through_unavailable();
	}

	$projected = array_intersect_key( $result, array_flip( $allowlists[ $operation ] ) );
	if ( 'status' === $operation ) {
		$projected['venue']              = array_intersect_key( (array) $result['venue'], array( 'name' => true ) );
		$projected['requested_interval'] = array_intersect_key(
			(array) $result['requested_interval'],
			array(
				'start_at' => true,
				'end_at'   => true,
			)
		);
		$projected['requested_space']    = array_intersect_key(
			(array) $result['requested_space'],
			array(
				'key'   => true,
				'label' => true,
			)
		);
		$projected['permitted_actions']  = array_values( array_intersect( (array) $result['permitted_actions'], array( 'request_correction', 'withdraw', 'request_cancellation' ) ) );
	}

	return $projected;
}

/** Collapse authorization failures and unknown references to one public shape. */
function extrachill_api_booking_follow_through_unavailable() {
	return new WP_Error( 'booking_inquiry_unavailable', __( 'This booking inquiry is unavailable.', 'extrachill-api' ), array( 'status' => 404 ) );
}

/** Return the same recovery response whether or not Events found a match. */
function extrachill_api_booking_recovery_accepted() {
	return new WP_REST_Response( array( 'accepted' => true ), 202 );
}

/** Collapse affinity transport failures without exposing hosts or network details. */
function extrachill_api_booking_follow_through_affinity_error( WP_Error $error ) {
	$data        = (array) $error->get_error_data();
	$headers     = is_array( $data['headers'] ?? null ) ? $data['headers'] : array();
	$retry_after = $data['retry_after'] ?? $headers['Retry-After'] ?? $headers['retry-after'] ?? null;
	$public_data = array( 'status' => 503 );
	if ( is_numeric( $retry_after ) ) {
		$retry_after                = min( HOUR_IN_SECONDS, max( 1, (int) $retry_after ) );
		$public_data['retry_after'] = $retry_after;
		$public_data['headers']     = array( 'Retry-After' => (string) $retry_after );
	}

	return new WP_Error( 'booking_follow_through_unavailable', __( 'Booking inquiry access is temporarily unavailable.', 'extrachill-api' ), $public_data );
}

/** Map Events failures without forwarding messages or private error metadata. */
function extrachill_api_booking_follow_through_error( WP_Error $error, $operation ) {
	$code = $error->get_error_code();
	if ( 'receipt-recovery' === $operation && in_array( $code, array( 'booking_receipt_recovery_forbidden', 'booking_receipt_recovery_status_forbidden' ), true ) ) {
		return extrachill_api_booking_recovery_accepted();
	}
	if ( in_array( $code, array( 'booking_inquiry_forbidden', 'booking_receipt_recovery_forbidden' ), true ) ) {
		return extrachill_api_booking_follow_through_unavailable();
	}
	if ( 'booking_version_conflict' === $code ) {
		$data = (array) $error->get_error_data();
		return new WP_Error(
			'booking_version_conflict',
			__( 'The booking changed since it was read.', 'extrachill-api' ),
			array(
				'status'          => 409,
				'current_version' => max( 1, absint( $data['current_version'] ?? 1 ) ),
			)
		);
	}
	$contracts = array(
		'booking_inquiry_terminal'               => array( 'booking_inquiry_terminal', 409, __( 'This booking inquiry no longer accepts artist changes.', 'extrachill-api' ) ),
		'booking_artist_idempotency_conflict'    => array( 'booking_idempotency_conflict', 409, __( 'This request key was already used for different details.', 'extrachill-api' ) ),
		'booking_artist_idempotency_key_invalid' => array( 'booking_idempotency_key_invalid', 400, __( 'A valid idempotency key is required.', 'extrachill-api' ) ),
		'booking_artist_correction_invalid'      => array( 'booking_correction_invalid', 400, __( 'Describe the correction you need the venue to review.', 'extrachill-api' ) ),
	);
	if ( isset( $contracts[ $code ] ) ) {
		$contract = $contracts[ $code ];
		return new WP_Error( $contract[0], $contract[2], array( 'status' => $contract[1] ) );
	}

	return new WP_Error( 'booking_follow_through_unavailable', __( 'Booking inquiry access is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
}

/** Map only explicitly contracted domain errors to fixed public responses. */
function extrachill_api_booking_public_error( WP_Error $error ) {
	$contracts = array(
		'booking_idempotency_conflict'              => array( 'booking_idempotency_conflict', 409, __( 'This inquiry key was already used for different details.', 'extrachill-api' ), array() ),
		'booking_config_revision_conflict'          => array( 'booking_inquiry_stale_config', 409, __( 'The venue booking configuration changed. Refresh it before resubmitting.', 'extrachill-api' ), array() ),
		'venue_booking_config_version_conflict'     => array( 'booking_inquiry_stale_config', 409, __( 'The venue booking configuration changed. Refresh it before resubmitting.', 'extrachill-api' ), array() ),
		'booking_inquiry_admission_disabled'        => array( 'booking_inquiry_unavailable', 503, __( 'This venue is not accepting booking inquiries.', 'extrachill-api' ), array() ),
		'booking_inquiry_unavailable'               => array( 'booking_inquiry_unavailable', 503, __( 'Booking inquiry processing is temporarily unavailable.', 'extrachill-api' ), array() ),
		'booking_inquiry_reconciliation_required'   => array( 'booking_inquiry_reconciliation_required', 503, __( 'The inquiry outcome requires reconciliation before retrying.', 'extrachill-api' ), array( 'retryable', 'reconciliation_required' ) ),
		'invalid_booking_attachment_count'          => array( 'booking_attachment_rejected', 400, __( 'Too many booking attachments were submitted.', 'extrachill-api' ), array() ),
		'booking_attachment_upload_failed'          => array( 'booking_attachment_rejected', 400, __( 'A booking attachment could not be received.', 'extrachill-api' ), array() ),
		'invalid_booking_attachment_filename'       => array( 'booking_attachment_rejected', 400, __( 'A booking attachment filename is not allowed.', 'extrachill-api' ), array() ),
		'invalid_booking_attachment_purpose'        => array( 'booking_attachment_rejected', 400, __( 'A booking attachment purpose is not supported.', 'extrachill-api' ), array() ),
		'invalid_booking_attachment_type'           => array( 'booking_attachment_rejected', 400, __( 'A booking attachment type is not supported.', 'extrachill-api' ), array() ),
		'invalid_booking_attachment_size'           => array( 'booking_attachment_rejected', 413, __( 'A booking attachment is outside the allowed size.', 'extrachill-api' ), array() ),
		'invalid_booking_attachment_aggregate_size' => array( 'booking_attachment_rejected', 413, __( 'The combined booking attachments are too large.', 'extrachill-api' ), array() ),
		'booking_tax_document_denied'               => array( 'booking_attachment_rejected', 400, __( 'Tax identity documents are not accepted here.', 'extrachill-api' ), array() ),
		'turnstile_missing_token'                   => array( 'turnstile_missing_token', 403, __( 'Security verification is required.', 'extrachill-api' ), array() ),
		'turnstile_failed'                          => array( 'turnstile_failed', 403, __( 'Security verification failed. Refresh the challenge and retry.', 'extrachill-api' ), array() ),
	);
	$contract  = $contracts[ $error->get_error_code() ] ?? null;
	if ( ! is_array( $contract ) ) {
		return new WP_Error( 'booking_inquiry_unavailable', __( 'Booking inquiry processing is temporarily unavailable.', 'extrachill-api' ), array( 'status' => 503 ) );
	}

	$data        = (array) $error->get_error_data();
	$public_data = array( 'status' => $contract[1] );
	foreach ( $contract[3] as $field ) {
		if ( isset( $data[ $field ] ) && is_bool( $data[ $field ] ) ) {
			$public_data[ $field ] = $data[ $field ];
		}
	}

	return new WP_Error( $contract[0], $contract[2], $public_data );
}

/** Add file descriptors to the affinity signature without exposing bytes. */
function extrachill_api_booking_affinity_signature_body( $body, WP_REST_Request $request ) {
	if ( ! preg_match( '#^/extrachill/v1/venues/\d+/booking-inquiries$#', $request->get_route() ) ) {
		return $body;
	}
	$encoded = $request->get_param( EXTRACHILL_API_BOOKING_FILES );
	if ( is_string( $encoded ) ) {
		$decoded = json_decode( $encoded, true );
		if ( is_array( $decoded ) ) {
			$body[ EXTRACHILL_API_BOOKING_FILES ] = $decoded;
		}
		return $body;
	}
	$files = extrachill_api_normalize_booking_files( $request );
	if ( is_wp_error( $files ) || ! $files ) {
		return $body;
	}
	foreach ( $body as $key => $value ) {
		if ( is_array( $value ) ) {
			$body[ $key ] = wp_json_encode( $value );
		}
	}
	$body[ EXTRACHILL_API_BOOKING_FILES ] = extrachill_api_booking_file_descriptors( $files );
	return $body;
}

/** Return signed descriptors used to verify the loopback multipart body. */
function extrachill_api_booking_file_descriptors( array $files ) {
	$descriptors = array();
	foreach ( $files as $file ) {
		$descriptors[] = array(
			'name'    => sanitize_file_name( $file['name'] ),
			'size'    => (int) $file['size'],
			'purpose' => $file['purpose'],
			'hash'    => $file['sha256'],
		);
	}
	return $descriptors;
}

/** Preserve multipart booking files over the signed Events-site loopback. */
function extrachill_api_booking_affinity_file_forward( $response, $target_site, $path, $args, WP_REST_Request $request ) {
	if ( 'events' !== $target_site || ! preg_match( '#^/venues/\d+/booking-inquiries$#', $path ) || ! $request->get_file_params() ) {
		return $response;
	}
	$files = extrachill_api_normalize_booking_files( $request );
	if ( is_wp_error( $files ) ) {
		return $files;
	}
	$site_url = ec_get_site_url( $target_site );
	$host     = wp_parse_url( $site_url, PHP_URL_HOST );
	if ( ! $host ) {
		return new WP_Error( 'route_affinity_target_invalid', 'Could not resolve route-affinity target host.', array( 'status' => 500 ) );
	}
	$boundary = 'ec-' . wp_generate_password( 32, false, false );
	$body     = extrachill_api_booking_multipart_body( (array) ( $args['body'] ?? array() ), $files, $boundary );
	if ( is_wp_error( $body ) ) {
		return $body;
	}
	$auth_headers = function_exists( 'ec_cross_site_build_auth_headers' ) ? ec_cross_site_build_auth_headers( get_current_user_id() ) : array();
	$headers      = array_merge(
		(array) ( $args['headers'] ?? array() ),
		$auth_headers,
		array(
			'Host'         => $host,
			'Accept'       => 'application/json',
			'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
		)
	);
	return wp_remote_request(
		'https://127.0.0.1/wp-json/extrachill/v1' . $path,
		array(
			'method'    => 'POST',
			'headers'   => $headers,
			'body'      => $body,
			'timeout'   => 30,
			'sslverify' => false,
		)
	);
}

/** Build the one endpoint-specific multipart loopback body. */
function extrachill_api_booking_multipart_body( array $fields, array $files, $boundary ) {
	$eol  = "\r\n";
	$body = '';
	foreach ( $fields as $name => $value ) {
		if ( EXTRACHILL_API_BOOKING_FILES === $name ) {
			$value = wp_json_encode( $value );
		} elseif ( is_array( $value ) ) {
			$value = wp_json_encode( $value );
		}
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="' . sanitize_key( $name ) . '"' . $eol . $eol;
		$body .= (string) $value . $eol;
	}
	foreach ( $files as $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Server-controlled temporary upload, not a URL.
		$bytes = file_get_contents( $file['tmp_name'] );
		if ( false === $bytes ) {
			return new WP_Error( 'booking_attachment_transport_failed', __( 'A booking attachment could not be forwarded.', 'extrachill-api' ), array( 'status' => 502 ) );
		}
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="attachments[]"; filename="' . sanitize_file_name( $file['name'] ) . '"' . $eol;
		$body .= 'Content-Type: application/octet-stream' . $eol . $eol;
		$body .= $bytes;
		$body .= $eol;
	}
	return $body . '--' . $boundary . '--' . $eol;
}
