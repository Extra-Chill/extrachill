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
 * Send a transactional EC email from a system-initiated registration context.
 *
 * Registration, onboarding, and the hourly welcome-email cron fallback all need
 * to send branded transactional email, but they run in an *unprivileged* request
 * context: an anonymous visitor (registration POST) or a brand-new subscriber
 * (onboarding) who has none of the `datamachine_manage_*` capabilities. The
 * underlying `datamachine/send-email` ability gates on
 * {@see \DataMachine\Abilities\PermissionHelper::can_manage()}, so calling
 * `ec_send_email()` directly from these contexts makes `WP_Ability::execute()`
 * short-circuit and return a `WP_Error` (code `ability_invalid_permissions`) —
 * NOT the documented `[ 'success' => ... ]` array envelope. That is the root
 * cause of the "ec_send_email returned non-array" failures (#110).
 *
 * The authorization decision for these sends is made at THIS layer: the EC
 * registration flow has already decided the email should go out. We therefore
 * execute the inner ability inside
 * {@see \DataMachine\Abilities\PermissionHelper::run_as_authenticated()}, the
 * canonical seam for callers that have authorized an operation at their own
 * layer and want to run an ability through the standard path.
 *
 * Falls back to a direct `ec_send_email()` call when the Data Machine
 * PermissionHelper is unavailable (e.g. Data Machine deactivated) so behavior
 * degrades gracefully rather than fataling — `ec_send_email()` already returns
 * a well-formed error envelope in that case.
 *
 * @param array $args Arguments forwarded to {@see ec_send_email()}.
 * @return mixed The `ec_send_email()` result envelope (array), or a WP_Error
 *               if the abilities layer is genuinely unreachable.
 */
function extrachill_send_registration_email( array $args ) {
	if ( ! function_exists( 'ec_send_email' ) ) {
		return array(
			'success' => false,
			'error'   => 'ec_send_email() is unavailable — extrachill-network mail layer not loaded.',
		);
	}

	$helper = '\DataMachine\Abilities\PermissionHelper';
	if ( class_exists( $helper ) ) {
		return $helper::run_as_authenticated(
			static function () use ( $args ) {
				return ec_send_email( $args );
			}
		);
	}

	// Data Machine PermissionHelper unavailable — call directly. ec_send_email()
	// still returns a structured envelope (bootstrap-failure error array).
	return ec_send_email( $args );
}

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
	$body_html .= '<p><em>Note: User has not yet customized their profile.</em></p>';

	$result = extrachill_send_registration_email(
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

	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		extrachill_log_email_failure( 'admin_new_user_notification', $user_id, $admin_email, $subject, $result );

		// Fallback to plain wp_mail() so operators get *something* even when the
		// DM ability layer is broken (see Extra-Chill/extrachill-users#56).
		$plain_body  = "A new user has registered on the Extra Chill platform.\n\n";
		$plain_body .= "Username: {$username} (auto-generated)\n";
		$plain_body .= "Email: {$email}\n";
		$plain_body .= "User ID: {$user_id}\n";
		$plain_body .= "Registration Source: {$source_label} ({$method_label})\n";
		$plain_body .= "Registration Page: {$page_display}\n\n";
		$plain_body .= "Edit user in admin: {$edit_url}\n\n";
		$plain_body .= "Note: this is the wp_mail() fallback — the EC branded email layer failed. See debug.log.\n";

		wp_mail( $admin_email, $subject . ' (fallback)', $plain_body );
	}
}

/**
 * Log a registration-email failure with enough detail to debug.
 *
 * Centralizes the failure-log format so all three call sites in this file write
 * consistent entries. Uses error_log() — the canonical extrachill-users
 * operational-logging surface (see inc/auth-tokens/service.php, inc/auth/register.php).
 *
 * @param string $context   Short identifier of the call site (e.g. 'admin_new_user_notification').
 * @param int    $user_id   User the email was for.
 * @param string $recipient Email recipient address.
 * @param string $subject   Email subject.
 * @param mixed  $result    The ec_send_email() result envelope (or whatever non-array value came back).
 */
function extrachill_log_email_failure( $context, $user_id, $recipient, $subject, $result ) {
	$error = 'unknown error (ec_send_email returned non-array)';
	if ( is_array( $result ) ) {
		if ( ! empty( $result['error'] ) ) {
			$error = (string) $result['error'];
		} elseif ( ! empty( $result['message'] ) ) {
			$error = (string) $result['message'];
		}
	} elseif ( is_wp_error( $result ) ) {
		// The send-email ability returns a WP_Error (not the array envelope) when
		// the inner permission/validation check fails. Surface the REAL code +
		// message instead of the misleading "non-array" default (#110).
		$error = sprintf( '%s: %s', $result->get_error_code(), $result->get_error_message() );
	} elseif ( null === $result ) {
		$error = 'ec_send_email returned null';
	} else {
		$error = sprintf( 'ec_send_email returned unexpected %s', gettype( $result ) );
	}

	$message = sprintf(
		'extrachill-users registration-email failure [%s]: user_id=%d recipient=%s subject="%s" error=%s',
		$context,
		(int) $user_id,
		$recipient,
		$subject,
		$error
	);

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Expected operational logging for registration-email failures (#56).
	error_log( $message );
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

	$body_html  = "<p>Welcome to <strong>Extra Chill</strong>! Now that you're here, this place is a lot more chill.</p>";
	$body_html .= '<p>Make yourself at home. You can join discussions, comment on stories, track concerts, and keep up with replies and activity through notifications.</p>';
	$body_html .= '<p><strong>Account Details:</strong><br>';
	$body_html .= 'Username: <strong>' . esc_html( $username ) . '</strong><br>';
	$body_html .= 'If you forget your password, you can reset it <a href="' . esc_url( $reset_pass_link ) . '">here</a>.</p>';
	$body_html .= '<p>See you around!</p>';
	$body_html .= '<p>Much love,<br>Extra Chill</p>';

	$result = extrachill_send_registration_email(
		array(
			'to'       => $email,
			'subject'  => $subject,
			'template' => 'extrachill/branded',
			'context'  => array(
				'subject_html'   => esc_html( $subject ),
				'body_html'      => $body_html,
				'recipient_name' => $username,
				'cta_url'        => $community_url,
				'cta_label'      => 'See What’s Happening',
				'preheader'      => 'Welcome to Extra Chill — your account is ready.',
			),
		)
	);

	$success = is_array( $result ) && ! empty( $result['success'] );
	if ( ! $success ) {
		extrachill_log_email_failure( 'welcome_email_complete', $user_data->ID, $email, $subject, $result );
		// Returning false here means the orchestrator (extrachill/send-welcome-email
		// ability) will NOT set welcome_email_sent=1, so the hourly cron fallback
		// (extrachill_welcome_email_fallback_callback) can retry on the next run.
	}

	return $success;
}

/**
 * Send welcome email for users who haven't completed onboarding.
 *
 * Encourages optional profile customization after account creation.
 *
 * @param WP_User $user_data User data object.
 * @return bool True if email sent successfully.
 */
function extrachill_send_welcome_email_incomplete( $user_data ) {
	$email         = $user_data->user_email;
	$community_url = ec_get_site_url( 'community' );
	$profile_url   = extrachill_get_user_community_profile_edit_url( $user_data->ID, $email );
	$events_url    = ec_get_site_url( 'events' );
	$artist_url    = ec_get_site_url( 'artist' );

	$subject = 'Make yourself at home at Extra Chill';

	$body_html  = '<p>You’re in. <strong>Extra Chill</strong> is an online music scene: a place to keep up with the music around you, talk with people who care, and take part in what’s happening.</p>';
	$body_html .= '<p>There’s no setup checklist. Make yourself at home:</p>';
	$body_html .= '<ul><li><a href="' . esc_url( $community_url ) . '">See what people are talking about</a></li>';
	$body_html .= '<li><a href="' . esc_url( $events_url ) . '">Find shows and track concerts</a></li>';
	$body_html .= '<li><a href="' . esc_url( $profile_url ) . '">Make your profile yours</a> whenever you’re ready</li></ul>';
	$body_html .= '<p><strong>A few quick answers</strong></p>';
	$body_html .= '<p><strong>Do I need to finish my profile?</strong><br>Nope. Add a photo, bio, links, username, and local scene now or come back later.</p>';
	$body_html .= '<p><strong>Is Extra Chill just a music blog?</strong><br>No. Stories are one part of an interconnected publication, community, event calendar, and set of artist tools.</p>';
	$body_html .= '<p><strong>Where should I start?</strong><br>See what’s happening and jump in whenever something grabs you.</p>';
	$body_html .= '<p>If you make music, you can also explore the <a href="' . esc_url( $artist_url ) . '">artist platform</a> whenever you’re ready.</p>';
	$body_html .= '<p>Much love,<br>Extra Chill</p>';

	$result = extrachill_send_registration_email(
		array(
			'to'       => $email,
			'subject'  => $subject,
			'template' => 'extrachill/branded',
			'context'  => array(
				'subject_html' => esc_html( $subject ),
				'body_html'    => $body_html,
				'cta_url'      => $community_url,
				'cta_label'    => 'See What’s Happening',
				'preheader'    => 'You’re in. See what’s happening and make yourself at home.',
			),
		)
	);

	$success = is_array( $result ) && ! empty( $result['success'] );
	if ( ! $success ) {
		extrachill_log_email_failure( 'welcome_email_incomplete', $user_data->ID, $email, $subject, $result );
		// Returning false here means the orchestrator (extrachill/send-welcome-email
		// ability) will NOT set welcome_email_sent=1, so the hourly cron fallback
		// (extrachill_welcome_email_fallback_callback) can retry on the next run.
	}

	return $success;
}
