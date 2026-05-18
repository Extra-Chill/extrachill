<?php
/**
 * Contact Form Email Functions
 *
 * Handles all email operations for the contact form:
 * - Admin notification emails  (extrachill/minimal template)
 * - User confirmation emails   (extrachill/branded template)
 * - Sendy newsletter integration
 *
 * Sends route through `ec_send_email()` (from extrachill-multisite), which
 * delegates to the `datamachine/send-email` ability. Per-site SMTP routing
 * and EC-branded HTML markup are owned by the template layer — this file
 * just supplies the message context.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send contact form notification to site administrator.
 *
 * Uses the `extrachill/minimal` template — admin alerts get a stripped-down
 * shell with no link grid. Submitter Reply-To is preserved via the ability's
 * `reply_to` input so admins can reply directly from their mail client.
 */
function ec_contact_send_admin_email( $name, $email, $subject, $message ) {
	if ( ! function_exists( 'ec_send_email' ) ) {
		return;
	}

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

	ec_send_email( array(
		'to'       => $admin_email,
		'subject'  => 'New submission: ' . $clean_subject,
		'template' => 'extrachill/minimal',
		'context'  => array(
			'body_html' => $body_html,
			'preheader' => sprintf( 'New contact form submission from %s', $clean_subject ),
		),
		'reply_to' => $email,
	) );
}

/**
 * Send confirmation email to user who submitted the contact form.
 *
 * Uses the `extrachill/branded` template — the canonical EC link grid and
 * footer now live in `extrachill-multisite`'s template, so this function
 * only supplies the user-facing message body.
 */
function ec_contact_send_user_confirmation( $name, $email, $subject, $message ) {
	if ( ! function_exists( 'ec_send_email' ) ) {
		return;
	}

	$escaped_message = nl2br( stripslashes( htmlspecialchars( $message, ENT_HTML5, 'UTF-8' ) ) );

	$body_html = <<<HTML
<p>Thank you for reaching out to Extra Chill! We've received your message and will get back to you within 3-5 business days.</p>
<p>Here's a summary of what you sent:</p>
<blockquote>{$escaped_message}</blockquote>
HTML;

	ec_send_email( array(
		'to'       => $email,
		'subject'  => 'Extra Chill Got Your Message',
		'template' => 'extrachill/branded',
		'context'  => array(
			'recipient_name' => $name,
			'body_html'      => $body_html,
			'preheader'      => 'We got your message — here is what happens next',
		),
	) );
}

/**
 * Sync contact form submission to Sendy newsletter list.
 */
function ec_contact_sync_to_sendy( $email ) {
	if ( function_exists( 'extrachill_multisite_subscribe' ) ) {
		extrachill_multisite_subscribe( $email, 'contact' );
	}
}
