<?php
/**
 * Moderation Email Helpers
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_send_moderation_email( WP_User $user, array $status ) {
	$reason_key = isset( $status['reason_key'] ) ? (string) $status['reason_key'] : 'other';
	$state      = isset( $status['state'] ) ? (string) $status['state'] : 'banned';
	$reason     = isset( $status['reason'] ) ? (string) $status['reason'] : '';

	if ( 'spam' === $reason_key ) {
		$subject = __( 'Your Extra Chill account has been permanently banned for spam', 'extrachill-users' );
		$message = __( 'Your account has been permanently banned for spam and all associated public content has been removed from public view.', 'extrachill-users' );
	} elseif ( 'suspended' === $state ) {
		$subject = __( 'Your Extra Chill account has been suspended', 'extrachill-users' );
		$message = __( 'Your account has been suspended. Please contact support if you believe this is a mistake.', 'extrachill-users' );
	} else {
		$subject = __( 'Your Extra Chill account has been banned', 'extrachill-users' );
		$message = __( 'Your account has been banned. Please contact support if you believe this is a mistake.', 'extrachill-users' );
	}

	if ( $reason ) {
		$message .= "\n\n" . sprintf( __( 'Reason: %s', 'extrachill-users' ), $reason );
	}

	return wp_mail( $user->user_email, $subject, $message );
}
