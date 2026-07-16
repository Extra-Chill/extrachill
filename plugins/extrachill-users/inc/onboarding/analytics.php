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
 * @return WP_Error Onboarding error.
 */
function ec_users_onboarding_error( string $code, string $message, int $user_id ): WP_Error {
	ec_users_emit_onboarding_event(
		EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED,
		$user_id,
		array( 'error_code' => sanitize_key( $code ) )
	);

	return new WP_Error( $code, $message );
}
