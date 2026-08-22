<?php
/**
 * Privacy-safe onboarding analytics helpers.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emit a privacy-safe onboarding event through the canonical Ability.
 *
 * @param string $event_type Canonical analytics event constant.
 * @param int    $user_id User ID.
 * @param array  $extra Additional bounded event fields.
 * @return int Event ID, or zero when analytics is unavailable.
 */
function ec_users_emit_onboarding_event( string $event_type, int $user_id, array $extra = array() ): int {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/track-analytics-event' ) : null;
	if ( ! $ability ) {
		return 0;
	}

	$result = $ability->execute(
		array(
			'event_type' => $event_type,
			'event_data' => array_merge( ec_users_get_onboarding_attribution( $user_id ), $extra ),
		)
	);

	return is_int( $result ) ? $result : 0;
}

/**
 * Resolve the bounded access method for an onboarding grant.
 *
 * @param bool $is_artist       Whether artist access was granted.
 * @param bool $is_professional Whether professional access was granted.
 * @return string Canonical grant method.
 */
function ec_users_get_onboarding_artist_grant_method( bool $is_artist, bool $is_professional ): string {
	if ( $is_artist && $is_professional ) {
		return 'artist_and_professional';
	}
	return $is_artist ? 'artist' : 'professional';
}

/**
 * Emit the canonical onboarding-origin artist access transition.
 *
 * The payload is entirely server-owned and restricted to the Analytics-owned
 * contract. User input and registration attribution are deliberately excluded.
 *
 * @param int  $user_id             User receiving access.
 * @param bool $is_artist           Whether artist access was granted.
 * @param bool $is_professional     Whether professional access was granted.
 * @return int Event ID, or zero when analytics is unavailable.
 */
function ec_users_emit_onboarding_artist_access_granted( int $user_id, bool $is_artist, bool $is_professional ): int {
	$method = ec_users_get_onboarding_artist_grant_method( $is_artist, $is_professional );

	if ( ! in_array( $method, EC_ANALYTICS_ARTIST_ACCESS_GRANTED_METHODS, true ) ) {
		return 0;
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/track-analytics-event' ) : null;
	if ( ! $ability ) {
		return 0;
	}

	$result = $ability->execute(
		array(
			'event_type' => EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED,
			'event_data' => array(
				'user_id' => $user_id,
				'source'  => EC_ANALYTICS_ARTIST_ACCESS_GRANTED_SOURCE_ONBOARDING,
				'method'  => $method,
			),
		)
	);

	return is_int( $result ) ? $result : 0;
}

/**
 * Emit one viewed event when a dynamic block renders more than once per request.
 *
 * @param int $user_id User ID.
 * @return int Event ID, or zero when already emitted or analytics is unavailable.
 */
function ec_users_emit_onboarding_viewed_once( int $user_id ): int {
	static $emitted = array();

	if ( isset( $emitted[ $user_id ] ) ) {
		return 0;
	}
	$emitted[ $user_id ] = true;

	return ec_users_emit_onboarding_event( EC_ANALYTICS_EVENT_ONBOARDING_VIEWED, $user_id );
}

/**
 * Get bounded registration attribution for onboarding events.
 *
 * @param int $user_id User ID.
 * @return array Attribution fields.
 */
function ec_users_get_onboarding_attribution( int $user_id ): array {
	$source = sanitize_key( (string) get_user_meta( $user_id, 'registration_source', true ) );
	$method = sanitize_key( (string) get_user_meta( $user_id, 'registration_method', true ) );

	return array(
		'user_id' => $user_id,
		'source'  => $source ? $source : 'unknown',
		'method'  => $method ? $method : 'unknown',
		'surface' => ec_is_onboarding_from_join( $user_id ) ? 'join' : 'registration',
	);
}

/**
 * Emit a safe failure code and return the corresponding error.
 *
 * @param string $code Stable error code.
 * @param string $message User-facing message.
 * @param int    $user_id User ID.
 * @param int    $status  HTTP status for REST consumers.
 * @return WP_Error Onboarding error.
 */
function ec_users_onboarding_error( string $code, string $message, int $user_id, int $status = 400 ): WP_Error {
	ec_users_emit_onboarding_event(
		EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED,
		$user_id,
		array( 'error_code' => sanitize_key( $code ) )
	);

	return new WP_Error( $code, $message, array( 'status' => $status ) );
}
