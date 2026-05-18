<?php
/**
 * Registration Email Templates
 *
 * Email template functions called by the extrachill/send-welcome-email ability
 * and admin notification hook. These are renderers only — orchestration lives
 * in the abilities.
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
	if ( ! $user_data instanceof WP_User ) {
		return;
	}
	$username = $user_data->user_login;
	$email    = $user_data->user_email;

	$admin_email = get_option( 'admin_email' );
	$subject     = 'New User Registration Notification';

	$source_label = $registration_source ? sanitize_text_field( (string) $registration_source ) : 'Unknown';
	$method_label = $registration_method ? sanitize_text_field( (string) $registration_method ) : 'Unknown';
	$page_display = $registration_page ? esc_url( $registration_page ) : 'Unknown';
	$edit_url     = admin_url( "user-edit.php?user_id={$user_id}" );

	$body_html  = '<p>A new user has registered on the Extra Chill platform.</p>';
	$body_html .= '<p><strong>Username:</strong> ' . esc_html( $username ) . ' (auto-generated)<br>';
	$body_html .= '<strong>Email:</strong> ' . esc_html( $email ) . '<br>';
	$body_html .= '<strong>User ID:</strong> ' . (int) $user_id . '<br>';
	$body_html .= '<strong>Registration Source:</strong> ' . esc_html( $source_label ) . ' (' . esc_html( $method_label ) . ')<br>';
	$body_html .= '<strong>Registration Page:</strong> ' . esc_html( $page_display ) . '</p>';
	$body_html .= '<p><a href="' . esc_url( $edit_url ) . '">Edit user in admin</a></p>';
	$body_html .= '<p><em>Note: User has not yet completed onboarding — profile URL not available until username is chosen.</em></p>';

	ec_send_email(
		array(
			'to'       => $admin_email,
			'subject'  => $subject,
			'template' => 'extrachill/minimal',
			'context'  => array(
				'subject_html' => esc_html( $subject ),
				'body_html'    => $body_html,
				'preheader'    => 'New user registered: ' . $username,
			),
		)
	);
}

add_action( 'extrachill_new_user_registered', 'extrachill_notify_admin_new_user', 10, 4 );

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
	$community_url   = ec_get_site_url( 'community' );

	$subject = 'Welcome to the Extra Chill Community!';

	$body_html  = "<p>Welcome to <strong>Extra Chill</strong>! Now that you're here, this place is a lot more chill!</p>";
	$body_html .= '<p>With your account, you can now participate in community discussions, comment on posts, and follow your favorite artists.</p>';
	$body_html .= '<p><strong>Account Details:</strong><br>';
	$body_html .= 'Username: <strong>' . esc_html( $username ) . '</strong><br>';
	$body_html .= 'If you forget your password, you can reset it <a href="' . esc_url( $reset_pass_link ) . '">here</a>.</p>';
	$body_html .= '<p>See you around!</p>';
	$body_html .= '<p>Much love,<br>Extra Chill</p>';

	$result = ec_send_email(
		array(
			'to'       => $email,
			'subject'  => $subject,
			'template' => 'extrachill/branded',
			'context'  => array(
				'subject_html'   => esc_html( $subject ),
				'body_html'      => $body_html,
				'recipient_name' => $username,
				'cta_url'        => $community_url . '/t/introductions-thread',
				'cta_label'      => 'Introduce Yourself',
				'preheader'      => 'Welcome to Extra Chill — your account is ready.',
			),
		)
	);

	return ! empty( $result['success'] );
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

	$subject = 'Complete Your Extra Chill Account Setup!';

	$body_html  = "<p>Welcome to <strong>Extra Chill</strong>! You're almost ready to join the community.</p>";
	$body_html .= '<p><strong><a href="' . esc_url( $onboarding_url ) . '">Complete your account setup</a></strong> to choose your username and get started.</p>';
	$body_html .= '<p>Once set up, you can participate in community discussions, comment on posts, and follow your favorite artists.</p>';
	$body_html .= '<p><strong>Account Details:</strong><br>';
	$body_html .= 'Email: <strong>' . esc_html( $email ) . '</strong><br>';
	$body_html .= 'If you forget your password, you can reset it <a href="' . esc_url( $reset_pass_link ) . '">here</a>.</p>';
	$body_html .= '<p>See you around!</p>';
	$body_html .= '<p>Much love,<br>Extra Chill</p>';

	$result = ec_send_email(
		array(
			'to'       => $email,
			'subject'  => $subject,
			'template' => 'extrachill/branded',
			'context'  => array(
				'subject_html' => esc_html( $subject ),
				'body_html'    => $body_html,
				'cta_url'      => $onboarding_url,
				'cta_label'    => 'Complete Your Account Setup',
				'preheader'    => 'Finish setting up your Extra Chill account.',
			),
		)
	);

	return ! empty( $result['success'] );
}
