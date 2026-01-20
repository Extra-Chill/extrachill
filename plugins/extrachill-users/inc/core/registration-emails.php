<?php
/**
 * Registration Email System
 *
 * Sends HTML welcome email on user_register hook and admin notification on extrachill_new_user_registered hook.
 *
 * @package ExtraChill\Users
 */

/**
 * Send admin notification on new user registration.
 *
 * @param int    $user_id              User ID
 * @param string $registration_page     URL where registration occurred.
 * @param string $registration_source   Source label (e.g. web, extrachill-app).
 * @param string $registration_method   Method label (e.g. standard, google).
 */
function extrachill_notify_admin_new_user( $user_id, $registration_page, $registration_source, $registration_method ) {
	$user_data = get_userdata( $user_id );
	$username  = $user_data->user_login;
	$email     = $user_data->user_email;

	$admin_email = get_option( 'admin_email' );
	$subject     = 'New User Registration Notification';

	$message      = "A new user has registered on the Extra Chill platform.\n\n";
	$message     .= 'Username: ' . $username . " (auto-generated)\n";
	$message     .= 'Email: ' . $email . "\n";
	$message     .= 'User ID: ' . $user_id . "\n";
	$source_label = $registration_source ? sanitize_text_field( (string) $registration_source ) : 'Unknown';
	$method_label = $registration_method ? sanitize_text_field( (string) $registration_method ) : 'Unknown';

	$message .= "Registration Source: {$source_label} ({$method_label})\n";
	$message .= 'Registration Page: ' . ( $registration_page ? esc_url( $registration_page ) : 'Unknown' ) . "\n";
	$message .= "\nUser Profile: " . ec_get_user_profile_url( $user_id, $email );

	wp_mail( $admin_email, $subject, $message );
}

add_action( 'extrachill_new_user_registered', 'extrachill_notify_admin_new_user', 10, 4 );

/**
 * Send welcome email to new user.
 *
 * @param int $user_id User ID
 */
function send_welcome_email_to_new_user( $user_id ) {
	$user_data       = get_userdata( $user_id );
	$username        = $user_data->user_login;
	$email           = $user_data->user_email;
	$reset_pass_link = ec_get_site_url( 'community' ) . '/reset-password/';

	$subject       = 'Welcome to the Extra Chill Community!';
	$message       = '<html><body>';
	$message      .= '<p>Hello <strong>' . $username . '</strong>,</p>';
	$message      .= "<p>Welcome to <strong>Extra Chill</strong>! Now that you're here, this place is a lot more chill!</p>";
	$message      .= '<p>With your account, you can now participate in community discussions, comment on posts, and follow your favorite artists.</p>';
	$message      .= "<p>Get started by <a href='" . esc_url( ec_get_site_url( 'community' ) . '/t/introductions-thread' ) . "'>introducing yourself in The Back Bar</a>!</p>";
	$message      .= '<p><strong>Account Details:</strong><br>';
	$message      .= 'Username: <strong>' . $username . '</strong><br>';
	$message      .= "If you forget your password, you can reset it <a href='" . esc_url( $reset_pass_link ) . "'>here</a>.</p>";
	$main_site_url = ec_get_site_url( 'main' );
	$community_url = ec_get_site_url( 'community' );
	$message      .= '<p><strong>Explore the Platform:</strong><br>';
	$message      .= "<a href='" . esc_url( $main_site_url . '/blog' ) . "'>Blog</a><br>";
	$message      .= "<a href='" . esc_url( $community_url ) . "'>Community</a><br>";
	$message      .= "<a href='" . esc_url( ec_get_site_url( 'events' ) ) . "'>Events Calendar</a><br>";
	$message      .= "<a href='" . esc_url( ec_get_site_url( 'artist' ) ) . "'>Artist Platform</a><br>";
	$message      .= "<a href='" . esc_url( ec_get_site_url( 'newsletter' ) ) . "'>Newsletter</a><br>";
	$message      .= "<a href='" . esc_url( ec_get_site_url( 'shop' ) ) . "'>Shop</a><br>";
	$message      .= "<a href='" . esc_url( ec_get_site_url( 'docs' ) ) . "'>Documentation</a></p>";
	$message      .= '<p><strong>Need Help?</strong><br>';
	$message      .= "<a href='" . esc_url( $main_site_url . '/contact/' ) . "'>Contact Us</a><br>";
	$message      .= "<a href='" . esc_url( $community_url . '/r/tech-support' ) . "'>Tech Support</a></p>";
	$message      .= '<p>See you around!</p>';
	$message      .= '<p>Much love,<br>';
	$message      .= 'Extra Chill</p>';
	$message      .= '</body></html>';

	$from_email = get_option( 'admin_email' );
	$headers    = array( 'Content-Type: text/html; charset=UTF-8', 'From: Extra Chill <' . $from_email . '>' );

	wp_mail( $email, $subject, $message, $headers );
}

add_action( 'user_register', 'send_welcome_email_to_new_user' );
