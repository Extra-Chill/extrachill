<?php
/**
 * Registration newsletter consent policy.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_NEWSLETTER_CONSENT_META_KEY = 'extrachill_newsletter_registration_consent';
const EXTRACHILL_USERS_NEWSLETTER_CONSENT_POLICY   = 'registration-newsletter-v1';

/**
 * Record registration consent and, when affirmative, delegate delivery to Newsletter.
 *
 * Consent persistence is authoritative and happens before the optional delivery
 * side effect. A delivery failure never changes the account-registration result.
 *
 * @param int    $user_id             Newly registered user ID.
 * @param string $email               Registration email address.
 * @param bool   $consented           Whether the user explicitly opted in.
 * @param string $registration_source Registration transport source.
 * @param string $registration_method Registration method.
 * @param string $source_url          URL where consent was collected.
 * @return array Stored consent receipt.
 */
function extrachill_users_record_registration_newsletter_consent( int $user_id, string $email, bool $consented, string $registration_source = '', string $registration_method = '', string $source_url = '' ): array {
	$existing = get_user_meta( $user_id, EXTRACHILL_USERS_NEWSLETTER_CONSENT_META_KEY, true );
	if ( is_array( $existing )
		&& EXTRACHILL_USERS_NEWSLETTER_CONSENT_POLICY === ( $existing['policy'] ?? '' )
		&& (bool) ( $existing['consented'] ?? false ) === $consented
	) {
		return $existing;
	}

	$receipt = array(
		'consented'           => $consented,
		'recorded_at'         => gmdate( 'c' ),
		'source'              => 'registration',
		'registration_source' => sanitize_text_field( $registration_source ),
		'registration_method' => sanitize_text_field( $registration_method ),
		'source_url'          => esc_url_raw( $source_url ),
		'policy'              => EXTRACHILL_USERS_NEWSLETTER_CONSENT_POLICY,
		'delivery_status'     => $consented ? 'pending' : 'not_requested',
	);

	update_user_meta( $user_id, EXTRACHILL_USERS_NEWSLETTER_CONSENT_META_KEY, $receipt );
	$stored = get_user_meta( $user_id, EXTRACHILL_USERS_NEWSLETTER_CONSENT_META_KEY, true );
	if ( ! is_array( $stored ) || $stored !== $receipt ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Consent persistence failure needs operational visibility without failing account creation.
		error_log( 'Registration newsletter consent receipt could not be persisted for user ' . $user_id . '.' );
		return $receipt;
	}

	$subscriber = apply_filters( 'extrachill_users_registration_newsletter_subscriber', 'extrachill_network_subscribe' );
	if ( ! $consented || ! is_callable( $subscriber ) ) {
		return $receipt;
	}

	$result = call_user_func( $subscriber, $email, 'registration', $receipt['source_url'] );
	if ( is_wp_error( $result ) ) {
		$receipt['delivery_status'] = 'failed';
		$message                    = $result->get_error_message();
	} else {
		$success                    = ! empty( $result['success'] );
		$status                     = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : '';
		$receipt['delivery_status'] = $success || 'already_subscribed' === $status ? ( $status ? $status : 'subscribed' ) : 'failed';
		$message                    = isset( $result['message'] ) ? (string) $result['message'] : '';
	}

	update_user_meta( $user_id, EXTRACHILL_USERS_NEWSLETTER_CONSENT_META_KEY, $receipt );
	if ( 'failed' === $receipt['delivery_status'] ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Newsletter delivery is deliberately non-fatal to registration.
		error_log( 'Registration newsletter subscription failed: ' . $message );
	}

	return $receipt;
}
