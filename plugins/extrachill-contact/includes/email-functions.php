<?php
/**
 * Contact Form Email Functions
 *
 * Handles all email operations for the contact form:
 * - Admin notification emails
 * - User confirmation emails
 * - Sendy newsletter integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send contact form notification to site administrator.
 */
function ec_contact_send_admin_email( $name, $email, $subject, $message ) {
	$admin_email = get_option( 'admin_email' );
	$headers     = array(
		'Content-Type: text/html; charset=UTF-8',
		'Reply-To: ' . $email,
	);

	$subject = stripslashes( htmlspecialchars_decode( $subject, ENT_QUOTES ) );
	$escaped_message = nl2br( stripslashes( htmlspecialchars( $message, ENT_HTML5, 'UTF-8' ) ) );

	$body = <<<HTML
<html>
<head>
  <title>New Contact Form Submission</title>
</head>
<body>
  <p><strong>Name:</strong> $name</p>
  <p><strong>Email:</strong> $email</p>
  <p><strong>Subject:</strong> $subject</p>
  <p><strong>Message:</strong></p>
  <div>$escaped_message</div>
</body>
</html>
HTML;

	wp_mail( $admin_email, 'New submission: ' . $subject, $body, $headers );
}

/**
 * Send confirmation email to user who submitted the contact form.
 */
function ec_contact_send_user_confirmation( $name, $email, $subject, $message ) {
	$admin_email  = get_option( 'admin_email' );
	$user_subject = 'Extra Chill Got Your Message';
	$headers      = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: Extra Chill <' . $admin_email . '>',
	);

	$subject = stripslashes( htmlspecialchars_decode( $subject, ENT_QUOTES ) );
	$escaped_message = nl2br( stripslashes( htmlspecialchars( $message, ENT_HTML5, 'UTF-8' ) ) );

	$blog_url         = esc_url( ec_get_site_url( 'main' ) . '/blog' );
	$community_url    = esc_url( ec_get_site_url( 'community' ) );
	$events_url       = esc_url( ec_get_site_url( 'events' ) );
	$artist_url       = esc_url( ec_get_site_url( 'artist' ) );
	$newsletter_url   = esc_url( ec_get_site_url( 'newsletter' ) );
	$shop_url         = esc_url( ec_get_site_url( 'shop' ) );
	$docs_url         = esc_url( ec_get_site_url( 'docs' ) );
	$contact_url      = esc_url( ec_get_site_url( 'main' ) . '/contact/' );
	$tech_support_url = esc_url( ec_get_site_url( 'community' ) . '/r/tech-support' );

	$body = <<<HTML
<html>
<head>
  <title>Extra Chill Got Your Message</title>
</head>
<body>
  <p>Hey $name,</p>
  <p>Thank you for reaching out to Extra Chill! We've received your message and will get back to you within 3-5 business days.</p>
  <p>Here's a summary of what you sent:</p>
  <blockquote>$escaped_message</blockquote>
  <p>While you're waiting, feel free to explore the Extra Chill platform:</p>
  <ul>
    <li><a href="$blog_url">Blog</a></li>
    <li><a href="$community_url">Community</a></li>
    <li><a href="$events_url">Events Calendar</a></li>
    <li><a href="$artist_url">Artist Platform</a></li>
    <li><a href="$newsletter_url">Newsletter</a></li>
    <li><a href="$shop_url">Shop</a></li>
    <li><a href="$docs_url">Documentation</a></li>
  </ul>
  <p><strong>Need Help?</strong></p>
  <ul>
    <li><a href="$contact_url">Contact Us</a></li>
    <li><a href="$tech_support_url">Tech Support</a></li>
  </ul>
  <p>Much love,<br>Extra Chill</p>
</body>
</html>
HTML;

	wp_mail( $email, $user_subject, $body, $headers );
}

/**
 * Sync contact form submission to Sendy newsletter list.
 */
function ec_contact_sync_to_sendy( $email ) {
	if ( function_exists( 'extrachill_multisite_subscribe' ) ) {
		try {
			extrachill_multisite_subscribe( $email, 'contact' );
		} catch ( Exception $e ) {
			error_log( 'Contact form Sendy sync failed: ' . $e->getMessage() );
		}
	}
}
