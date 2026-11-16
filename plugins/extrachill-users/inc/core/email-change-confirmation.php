<?php
/**
 * Front-end email change confirmation.
 *
 * The confirmation link in the "Email Change Request" email lands on the
 * member Settings page instead of wp-admin/profile.php. Members without
 * wp-admin access are redirected away from wp-admin before core can process
 * `newuseremail`, so the change could never complete for them.
 *
 * Mirrors core's wp-admin/user-edit.php confirmation logic.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/** Query arg carrying the confirmation hash. */
const EXTRACHILL_USERS_CONFIRM_EMAIL_ARG = 'ec_confirm_email';

/** Query arg carrying the confirmation result back to Settings. */
const EXTRACHILL_USERS_EMAIL_CHANGE_STATUS_ARG = 'ec_email_change';

/**
 * Member Settings page URL on the community site.
 *
 * @param array<string, string> $args Query args to add.
 * @return string
 */
function extrachill_users_settings_url( array $args = array() ): string {
	$base = function_exists( 'ec_get_site_url' ) ? (string) ec_get_site_url( 'community' ) : home_url();
	$url  = untrailingslashit( $base ) . '/settings';

	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Confirmation link sent in the email change request.
 *
 * @param string $hash Pending change hash stored in `_new_email`.
 * @return string
 */
function extrachill_users_email_change_confirm_url( string $hash ): string {
	return extrachill_users_settings_url( array( EXTRACHILL_USERS_CONFIRM_EMAIL_ARG => $hash ) );
}

/**
 * Apply a pending email change when the hash matches.
 *
 * @param int    $user_id User confirming the change.
 * @param string $hash    Hash from the confirmation link.
 * @return string|WP_Error The new email address, or an error.
 */
function extrachill_users_confirm_email_change( int $user_id, string $hash ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extrachill-users' ) );
	}

	$pending = get_user_meta( $user_id, '_new_email', true );
	if ( ! is_array( $pending ) || empty( $pending['hash'] ) || empty( $pending['newemail'] ) ) {
		return new WP_Error( 'no_pending_email', __( 'There is no pending email change for your account.', 'extrachill-users' ) );
	}

	if ( '' === $hash || ! hash_equals( (string) $pending['hash'], $hash ) ) {
		return new WP_Error( 'invalid_email_hash', __( 'This email confirmation link is invalid or has already been used.', 'extrachill-users' ) );
	}

	$new_email = sanitize_email( trim( (string) $pending['newemail'] ) );
	if ( ! is_email( $new_email ) ) {
		delete_user_meta( $user_id, '_new_email' );
		return new WP_Error( 'invalid_email', __( 'The pending email address is not valid.', 'extrachill-users' ) );
	}

	$existing = email_exists( $new_email );
	if ( $existing && (int) $existing !== $user_id ) {
		delete_user_meta( $user_id, '_new_email' );
		return new WP_Error( 'email_exists', __( 'That email address is already in use by another account.', 'extrachill-users' ) );
	}

	if ( is_multisite() ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mirrors core wp-admin/user-edit.php.
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT user_login FROM {$wpdb->signups} WHERE user_login = %s", $user->user_login ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mirrors core wp-admin/user-edit.php.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->signups} SET user_email = %s WHERE user_login = %s", $new_email, $user->user_login ) );
		}
	}

	$result = wp_update_user(
		array(
			'ID'         => $user_id,
			'user_email' => $new_email,
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	delete_user_meta( $user_id, '_new_email' );

	return $new_email;
}

/**
 * Map a confirmation result to the status flag shown on Settings.
 *
 * @param string|WP_Error $result Result of extrachill_users_confirm_email_change().
 * @return string
 */
function extrachill_users_email_change_status( $result ): string {
	if ( ! is_wp_error( $result ) ) {
		return 'confirmed';
	}
	if ( 'email_exists' === $result->get_error_code() ) {
		return 'taken';
	}
	return 'invalid';
}

/**
 * Notice for a Settings page status flag.
 *
 * @param string $status Status flag from the query string.
 * @return array{type: string, message: string}|null
 */
function extrachill_users_email_change_notice( string $status ): ?array {
	switch ( $status ) {
		case 'confirmed':
			return array(
				'type'    => 'success',
				'message' => __( 'Your email address has been updated.', 'extrachill-users' ),
			);
		case 'taken':
			return array(
				'type'    => 'error',
				'message' => __( 'That email address is already in use by another account. Your email was not changed.', 'extrachill-users' ),
			);
		case 'invalid':
			return array(
				'type'    => 'error',
				'message' => __( 'This email confirmation link is invalid or has already been used. Your email was not changed.', 'extrachill-users' ),
			);
	}
	return null;
}

/**
 * Handle confirmation links on the front end. Thin adapter around
 * extrachill_users_confirm_email_change().
 */
function extrachill_users_handle_email_change_confirmation(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The hash is the single-use secret from the email.
	if ( is_admin() || ! isset( $_GET[ EXTRACHILL_USERS_CONFIRM_EMAIL_ARG ] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The hash is the single-use secret from the email.
	$hash = sanitize_text_field( wp_unslash( (string) $_GET[ EXTRACHILL_USERS_CONFIRM_EMAIL_ARG ] ) );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( extrachill_users_email_change_confirm_url( $hash ) ) );
		exit;
	}

	$result = extrachill_users_confirm_email_change( get_current_user_id(), $hash );
	$target = extrachill_users_settings_url(
		array( EXTRACHILL_USERS_EMAIL_CHANGE_STATUS_ARG => extrachill_users_email_change_status( $result ) )
	) . '#tab-account-details';

	// Trusted network URL, not request input.
	wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Network-resolved destination.
	exit;
}
add_action( 'template_redirect', 'extrachill_users_handle_email_change_confirmation', 1 );
