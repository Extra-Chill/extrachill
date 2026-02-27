<?php
/**
 * Email Send Tracking
 *
 * Logs all wp_mail() calls (successes and failures) as analytics events.
 * Provides visibility into email delivery across the network — catches
 * silent SMTP failures that would otherwise go unnoticed.
 *
 * Event types:
 * - `email_sent`   — wp_mail() returned true
 * - `email_failed` — wp_mail() returned false or wp_mail_failed fired
 *
 * @package ExtraChill\Analytics
 * @since 0.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capture email arguments before send for later logging.
 *
 * WordPress fires the `wp_mail` filter before sending. We stash the
 * mail arguments so the `wp_mail_failed` handler has context about
 * what was being sent when the failure occurred.
 *
 * @param array $args Mail arguments: to, subject, message, headers, attachments.
 * @return array Unmodified mail arguments (passthrough filter).
 */
function extrachill_analytics_capture_mail_args( $args ) {
	global $extrachill_analytics_last_mail;

	$extrachill_analytics_last_mail = array(
		'to'      => is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'],
		'subject' => $args['subject'] ?? '',
		'time'    => current_time( 'mysql', true ),
	);

	return $args;
}
add_filter( 'wp_mail', 'extrachill_analytics_capture_mail_args', 999 );

/**
 * Log successful email sends.
 *
 * Hooks into `wp_mail_succeeded` (WP 5.9+). Falls back to nothing on
 * older versions — the `wp_mail` filter + `wp_mail_failed` pair still
 * catches failures regardless.
 *
 * @param array $mail_data Data about the sent email.
 */
function extrachill_analytics_log_email_sent( $mail_data ) {
	$to      = is_array( $mail_data['to'] ) ? implode( ', ', $mail_data['to'] ) : $mail_data['to'];
	$subject = $mail_data['subject'] ?? '';

	$context = extrachill_analytics_detect_email_context();

	extrachill_track_analytics_event(
		'email_sent',
		array(
			'to'      => $to,
			'subject' => $subject,
			'context' => $context,
		)
	);
}
add_action( 'wp_mail_succeeded', 'extrachill_analytics_log_email_sent' );

/**
 * Log failed email sends.
 *
 * WordPress fires `wp_mail_failed` with a WP_Error when PHPMailer
 * throws an exception or SMTP authentication fails.
 *
 * @param WP_Error $error The error object with failure details.
 */
function extrachill_analytics_log_email_failed( $error ) {
	global $extrachill_analytics_last_mail;

	$to      = '';
	$subject = '';

	// Try to get recipient/subject from the error data first.
	$error_data = $error->get_error_data();
	if ( is_array( $error_data ) ) {
		if ( isset( $error_data['to'] ) ) {
			$to = is_array( $error_data['to'] ) ? implode( ', ', $error_data['to'] ) : $error_data['to'];
		}
		if ( isset( $error_data['subject'] ) ) {
			$subject = $error_data['subject'];
		}
	}

	// Fall back to captured args if error data didn't have them.
	if ( empty( $to ) && ! empty( $extrachill_analytics_last_mail['to'] ) ) {
		$to = $extrachill_analytics_last_mail['to'];
	}
	if ( empty( $subject ) && ! empty( $extrachill_analytics_last_mail['subject'] ) ) {
		$subject = $extrachill_analytics_last_mail['subject'];
	}

	$context = extrachill_analytics_detect_email_context();

	extrachill_track_analytics_event(
		'email_failed',
		array(
			'to'      => $to,
			'subject' => $subject,
			'error'   => $error->get_error_message(),
			'context' => $context,
		)
	);

	// Clear stashed args.
	$extrachill_analytics_last_mail = null;
}
add_action( 'wp_mail_failed', 'extrachill_analytics_log_email_failed' );

/**
 * Detect which plugin/system triggered the email.
 *
 * Walks the call stack to find the originating plugin or theme.
 * Returns a human-readable context string.
 *
 * @return string Context identifier (e.g., 'extrachill-contact', 'woocommerce', 'wordpress').
 */
function extrachill_analytics_detect_email_context() {
	$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

	foreach ( $trace as $frame ) {
		if ( empty( $frame['file'] ) ) {
			continue;
		}

		$file = str_replace( '\\', '/', $frame['file'] );

		// Check plugins.
		if ( preg_match( '/wp-content\/plugins\/([^\/]+)/', $file, $matches ) ) {
			$plugin_slug = $matches[1];

			// Skip ourselves and the SMTP transport plugin.
			if ( in_array( $plugin_slug, array( 'extrachill-analytics', 'easy-wp-smtp', 'fluent-smtp' ), true ) ) {
				continue;
			}

			return $plugin_slug;
		}

		// Check themes.
		if ( preg_match( '/wp-content\/themes\/([^\/]+)/', $file, $matches ) ) {
			return 'theme:' . $matches[1];
		}

		// Check mu-plugins.
		if ( false !== strpos( $file, 'wp-content/mu-plugins' ) ) {
			return 'mu-plugin';
		}
	}

	return 'wordpress';
}
