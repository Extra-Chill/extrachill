<?php
/**
 * Registration Email System
 *
 * Sends HTML welcome email via Abilities API after onboarding completion
 * and admin notification on extrachill_new_user_registered hook.
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
	$message .= "\nUser Profile: " . extrachill_get_user_profile_url( $user_id, $email );

	wp_mail( $admin_email, $subject, $message );
}

add_action( 'extrachill_new_user_registered', 'extrachill_notify_admin_new_user', 10, 4 );

/**
 * Send welcome email after onboarding completion.
 *
 * @param int   $user_id User ID.
 * @param array $data    Onboarding data.
 */
function extrachill_send_welcome_email_on_onboarding( $user_id, $data ) {
	$ability = wp_get_ability( 'extrachill/send-welcome-email' );
	if ( $ability ) {
		$ability->execute(
			array(
				'user_id'    => $user_id,
				'email_type' => 'onboarding_complete',
			)
		);
	}
}

add_action( 'ec_onboarding_completed', 'extrachill_send_welcome_email_on_onboarding', 10, 2 );

/**
 * Send welcome email for users who completed onboarding.
 *
 * Uses the user's final username and provides personalized welcome.
 *
 * @param WP_User $user_data User data object.
 * @return bool True if email sent successfully.
 */
function extrachill_send_welcome_email_complete( $user_data ) {
	$username        = $user_data->user_login;
	$email           = $user_data->user_email;
	$reset_pass_link = ec_get_site_url( 'community' ) . '/reset-password/';

	$subject       = 'Welcome to the Extra Chill Community!';
	$message       = '<html><body>';
	$message      .= '<p>Hello <strong>' . esc_html( $username ) . '</strong>,</p>';
	$message      .= "<p>Welcome to <strong>Extra Chill</strong>! Now that you're here, this place is a lot more chill!</p>";
	$message      .= '<p>With your account, you can now participate in community discussions, comment on posts, and follow your favorite artists.</p>';
	$message      .= "<p>Get started by <a href='" . esc_url( ec_get_site_url( 'community' ) . '/t/introductions-thread' ) . "'>introducing yourself in The Back Bar</a>!</p>";
	$message      .= '<p><strong>Account Details:</strong><br>';
	$message      .= 'Username: <strong>' . esc_html( $username ) . '</strong><br>';
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

	return wp_mail( $email, $subject, $message, $headers );
}

/**
 * Send welcome email for users who haven't completed onboarding.
 *
 * Encourages user to complete their account setup.
 *
 * @param WP_User $user_data User data object.
 * @return bool True if email sent successfully.
 */
function extrachill_send_welcome_email_incomplete( $user_data ) {
	$email           = $user_data->user_email;
	$reset_pass_link = ec_get_site_url( 'community' ) . '/reset-password/';
	$onboarding_url  = ec_get_site_url( 'community' ) . '/onboarding/';

	$subject       = 'Complete Your Extra Chill Account Setup!';
	$message       = '<html><body>';
	$message      .= '<p>Hello!</p>';
	$message      .= "<p>Welcome to <strong>Extra Chill</strong>! You're almost ready to join the community.</p>";
	$message      .= "<p><strong><a href='" . esc_url( $onboarding_url ) . "'>Complete your account setup</a></strong> to choose your username and get started.</p>";
	$message      .= '<p>Once set up, you can participate in community discussions, comment on posts, and follow your favorite artists.</p>';
	$message      .= '<p><strong>Account Details:</strong><br>';
	$message      .= 'Email: <strong>' . esc_html( $email ) . '</strong><br>';
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

	return wp_mail( $email, $subject, $message, $headers );
}
