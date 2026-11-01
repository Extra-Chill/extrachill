<?php
/**
 * Member subscription policy and account-deletion cleanup.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_user_self_deleted', 'extrachill_newsletter_unsubscribe_deleted_user', 10, 2 );

/** Single member-facing preference key; every network list feeds one newsletter. */
const EXTRACHILL_NEWSLETTER_MEMBER_KEY = 'newsletter';

/**
 * Resolve every configured integration list, deduplicated by Sendy ID.
 *
 * @return array<string, array{name: string, list_id: string}>
 */
function extrachill_newsletter_subscription_lists(): array {
	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	$lists    = array();
	$seen     = array();
	foreach ( get_newsletter_integrations() as $key => $integration ) {
		$id = $settings[ $integration['list_id_key'] ] ?? '';
		if ( ! is_string( $id ) || '' === trim( $id ) || in_array( $id, $seen, true ) ) {
			continue;
		}
		$seen[]        = $id;
		$lists[ $key ] = array(
			'name'    => (string) $integration['label'],
			'list_id' => $id,
		);
	}
	return $lists;
}

/**
 * List a member joins when they turn the newsletter on: the account
 * registration list, falling back to the homepage signup list.
 *
 * @return string Sendy list ID, or empty string when neither is configured.
 */
function extrachill_newsletter_member_signup_list_id(): string {
	$lists = extrachill_newsletter_subscription_lists();
	foreach ( array( 'registration', 'homepage' ) as $key ) {
		if ( isset( $lists[ $key ] ) ) {
			return $lists[ $key ]['list_id'];
		}
	}
	return '';
}

/**
 * Get the canonical DMB API configuration, never the legacy newsletter copy.
 *
 * @return array
 */
function extrachill_newsletter_subscription_config(): array {
	$class = EXTRACHILL_NEWSLETTER_DMB_SENDY_CLASS;
	// DMB is an optional runtime dependency and may be absent from analysis.
	// @phpstan-ignore function.impossibleType
	return class_exists( $class ) && method_exists( $class, 'get_campaign_config' ) ? $class::get_campaign_config() : array();
}

/**
 * Unsubscribe from one list. Sendy's boolean response is 1 on success.
 *
 * @param string $list_id Sendy list ID.
 * @param string $email Account email.
 * @return true|WP_Error
 */
function extrachill_newsletter_sendy_unsubscribe( string $list_id, string $email ) {
	$config = extrachill_newsletter_subscription_config();
	if ( empty( $config['api_key'] ) || empty( $config['sendy_url'] ) ) {
		return new WP_Error( 'sendy_config_incomplete', 'Sendy API key or URL not configured.' );
	}
	$response = wp_remote_post(
		rtrim( $config['sendy_url'], '/' ) . '/unsubscribe',
		array(
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'api_key' => $config['api_key'],
				'email'   => $email,
				'list'    => $list_id,
				'boolean' => 'true',
			),
			'timeout' => 15,
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = wp_remote_retrieve_response_code( $response );
	$body = trim( wp_remote_retrieve_body( $response ) );
	if ( $code < 200 || $code >= 300 || ! in_array( $body, array( '1', 'Email does not exist.' ), true ) ) {
		return new WP_Error( 'sendy_unsubscribe_failed', 'Sendy did not confirm unsubscribe.' );
	}
	return true;
}

/**
 * Best-effort cleanup across every configured network integration list.
 *
 * Each list is isolated so one failed request cannot skip the remaining lists.
 *
 * @param string $email Account email.
 * @return void
 */
function extrachill_newsletter_unsubscribe_everywhere( string $email ): void {
	if ( '' === $email ) {
		return;
	}
	foreach ( extrachill_newsletter_subscription_lists() as $list ) {
		try {
			$result = extrachill_newsletter_sendy_unsubscribe( $list['list_id'], $email );
			if ( ! is_wp_error( $result ) ) {
				continue;
			}
		} catch ( Throwable $error ) {
			// Do not log provider responses or exceptions: they may contain PII.
			unset( $error );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cleanup failure must be visible without blocking deletion or logging PII.
		error_log( 'Newsletter account deletion: Sendy unsubscribe failed for a configured list.' );
	}
}

/**
 * Handle the pre-deletion account lifecycle action.
 *
 * Deliberately do not also define extrachill_network_unsubscribe: users invokes
 * that optional callable before this action, which would duplicate every call.
 *
 * @param int    $user_id Deleted account ID (not needed).
 * @param string $email Account email supplied before the account is removed.
 * @return void
 */
function extrachill_newsletter_unsubscribe_deleted_user( int $user_id, string $email ): void {
	unset( $user_id );
	extrachill_newsletter_unsubscribe_everywhere( $email );
}
