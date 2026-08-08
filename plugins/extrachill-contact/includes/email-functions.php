<?php
/**
 * Contact Form Email Functions
 *
 * @package ExtraChillContact
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run an email authorized by the contact submission flow.
 *
 * Data Machine's mail ability is management-gated. The public contact ability
 * authorizes these specific sends after Turnstile and input validation.
 *
 * @param array<string, mixed> $args Email arguments.
 * @return mixed Provider result.
 */
function ec_contact_send_email( array $args ) {
	if ( ! function_exists( 'ec_send_email' ) ) {
		return new WP_Error( 'email_provider_unavailable', 'The email provider is unavailable.' );
	}

	$helper = '\\DataMachine\\Abilities\\PermissionHelper';
	if ( class_exists( $helper ) ) {
		return $helper::run_as_authenticated(
			static function () use ( $args ) {
				return ec_send_email( $args );
			}
		);
	}

	return ec_send_email( $args );
}

/**
 * Normalize an email provider response into the contact side-effect contract.
 *
 * @param mixed $result   Provider result.
 * @param bool  $optional Whether this delivery is optional for submission success.
 * @return array{success: bool, status: string, provider: string, optional: bool, retryable: bool, message: string}
 */
function ec_contact_normalize_email_result( $result, bool $optional = false ): array {
	if ( is_wp_error( $result ) ) {
		return array(
			'success'   => false,
			'status'    => 'email_provider_unavailable' === $result->get_error_code() ? 'provider_unavailable' : 'failed',
			'provider'  => 'datamachine/send-email',
			'optional'  => $optional,
			'retryable' => true,
			'message'   => $result->get_error_message(),
		);
	}

	if ( ! is_array( $result ) || ! array_key_exists( 'success', $result ) || ! is_bool( $result['success'] ) ) {
		return array(
			'success'   => false,
			'status'    => 'malformed_provider_response',
			'provider'  => 'datamachine/send-email',
			'optional'  => $optional,
			'retryable' => true,
			'message'   => 'The email provider returned an invalid response.',
		);
	}

	if ( true === $result['success'] ) {
		return array(
			'success'   => true,
			'status'    => 'delivered',
			'provider'  => 'datamachine/send-email',
			'optional'  => $optional,
			'retryable' => false,
			'message'   => isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : 'Email accepted for delivery.',
		);
	}

	$message = $result['error'] ?? $result['message'] ?? 'Email delivery failed.';
	return array(
		'success'   => false,
		'status'    => 'failed',
		'provider'  => 'datamachine/send-email',
		'optional'  => $optional,
		'retryable' => true,
		'message'   => is_string( $message ) ? $message : 'Email delivery failed.',
	);
}

/**
 * Send the contact notification to the site administrator.
 *
 * @return array{success: bool, status: string, provider: string, optional: bool, retryable: bool, message: string}
 */
function ec_contact_send_admin_email( $name, $email, $subject, $message ): array {
	$admin_email     = get_option( 'admin_email' );
	$clean_subject   = stripslashes( htmlspecialchars_decode( $subject, ENT_QUOTES ) );
	$escaped_message = nl2br( stripslashes( htmlspecialchars( $message, ENT_HTML5, 'UTF-8' ) ) );
	$escaped_name    = esc_html( $name );
	$escaped_email   = esc_html( $email );
	$escaped_subject = esc_html( $clean_subject );

	$body_html = <<<HTML
<p><strong>Name:</strong> {$escaped_name}</p>
<p><strong>Email:</strong> {$escaped_email}</p>
<p><strong>Subject:</strong> {$escaped_subject}</p>
<p><strong>Message:</strong></p>
<div>{$escaped_message}</div>
HTML;

	$result = ec_contact_send_email(
		array(
			'to'       => $admin_email,
			'subject'  => 'New submission: ' . $clean_subject,
			'template' => 'extrachill/minimal',
			'context'  => array(
				'body_html' => $body_html,
				'preheader' => sprintf( 'New contact form submission from %s', $clean_subject ),
			),
			'reply_to' => $email,
		)
	);

	return ec_contact_normalize_email_result( $result, false );
}

/**
 * Send the submitter confirmation.
 *
 * @return array{success: bool, status: string, provider: string, optional: bool, retryable: bool, message: string}
 */
function ec_contact_send_user_confirmation( $name, $email, $subject, $message ): array {
	$escaped_message = nl2br( stripslashes( htmlspecialchars( $message, ENT_HTML5, 'UTF-8' ) ) );

	$body_html = <<<HTML
<p>Thank you for reaching out to Extra Chill! We've received your message and will get back to you within 3-5 business days.</p>
<p>Here's a summary of what you sent:</p>
<blockquote>{$escaped_message}</blockquote>
HTML;

	$result = ec_contact_send_email(
		array(
			'to'       => $email,
			'subject'  => 'Extra Chill Got Your Message',
			'template' => 'extrachill/branded',
			'context'  => array(
				'recipient_name' => $name,
				'body_html'      => $body_html,
				'preheader'      => 'We got your message - here is what happens next',
			),
		)
	);

	return ec_contact_normalize_email_result( $result, true );
}

/**
 * Sync the submitter to the contact newsletter list.
 *
 * Newsletter synchronization is optional for submission success, but its
 * provider outcome is always represented explicitly.
 *
 * @return array{success: bool, status: string, provider: string, optional: bool, retryable: bool, message: string}
 */
function ec_contact_sync_to_sendy( $email ): array {
	if ( ! function_exists( 'extrachill_network_subscribe' ) ) {
		return array(
			'success'   => false,
			'status'    => 'provider_unavailable',
			'provider'  => 'extrachill/subscribe',
			'optional'  => true,
			'retryable' => true,
			'message'   => 'Newsletter synchronization is unavailable.',
		);
	}

	$result = extrachill_network_subscribe( $email, 'contact' );
	if ( is_wp_error( $result ) ) {
		return array(
			'success'   => false,
			'status'    => 'failed',
			'provider'  => 'extrachill/subscribe',
			'optional'  => true,
			'retryable' => true,
			'message'   => $result->get_error_message(),
		);
	}

	if ( ! is_array( $result ) || ! isset( $result['status'] ) || ! is_string( $result['status'] ) || ! array_key_exists( 'success', $result ) || ! is_bool( $result['success'] ) ) {
		return array(
			'success'   => false,
			'status'    => 'malformed_provider_response',
			'provider'  => 'extrachill/subscribe',
			'optional'  => true,
			'retryable' => true,
			'message'   => 'The newsletter provider returned an invalid response.',
		);
	}

	$status  = sanitize_key( $result['status'] );
	$success = true === $result['success'] || 'already_subscribed' === $status;
	$message = isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : 'Newsletter synchronization completed.';

	return array(
		'success'   => $success,
		'status'    => $status,
		'provider'  => 'extrachill/subscribe',
		'optional'  => true,
		'retryable' => ! $success,
		'message'   => $message,
	);
}
