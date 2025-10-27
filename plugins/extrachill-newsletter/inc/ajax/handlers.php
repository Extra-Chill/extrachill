<?php
/**
 * Newsletter AJAX handlers for subscription forms and campaign management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function handle_push_newsletter_to_sendy_ajax() {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		wp_send_json_error(__('Invalid request type', 'extrachill-newsletter'), 400);
		return;
	}

	if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'push_newsletter_to_sendy_nonce')) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'), 401);
		return;
	}

	$post_id = intval($_POST['post_id']);
	if (!$post_id || !current_user_can('edit_post', $post_id)) {
		wp_send_json_error(__('Invalid post ID or insufficient permissions', 'extrachill-newsletter'), 403);
		return;
	}

	$post = get_post($post_id);
	if (!$post || $post->post_type !== 'newsletter') {
		wp_send_json_error(__('Newsletter not found', 'extrachill-newsletter'), 404);
		return;
	}

	$email_data = prepare_newsletter_email_content($post);
	$result = send_newsletter_campaign_to_sendy($post_id, $email_data);

	if (is_wp_error($result)) {
		wp_send_json_error($result->get_error_message(), 500);
	} else {
		wp_send_json_success(__('Campaign successfully created or updated in Sendy', 'extrachill-newsletter'));
	}
}
add_action('wp_ajax_push_newsletter_to_sendy_ajax', 'handle_push_newsletter_to_sendy_ajax');

function handle_submit_newsletter_form() {
	if (!check_ajax_referer('newsletter_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	$result = extrachill_multisite_subscribe($email, 'archive');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_form', 'handle_submit_newsletter_form');
add_action('wp_ajax_nopriv_submit_newsletter_form', 'handle_submit_newsletter_form');

function handle_submit_newsletter_popup_form() {
	if (!check_ajax_referer('newsletter_popup_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	$result = extrachill_multisite_subscribe($email, 'popup');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_popup_form', 'handle_submit_newsletter_popup_form');
add_action('wp_ajax_nopriv_submit_newsletter_popup_form', 'handle_submit_newsletter_popup_form');

function handle_subscribe_to_sendy_home() {
	if (!check_ajax_referer('subscribe_to_sendy_home_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	$result = extrachill_multisite_subscribe($email, 'homepage');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_subscribe_to_sendy_home', 'handle_subscribe_to_sendy_home');
add_action('wp_ajax_nopriv_subscribe_to_sendy_home', 'handle_subscribe_to_sendy_home');

function handle_subscribe_to_sendy_nav() {
	if (!check_ajax_referer('newsletter_nonce', 'subscribe_nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	$result = extrachill_multisite_subscribe($email, 'navigation');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_subscribe_to_sendy', 'handle_subscribe_to_sendy_nav');
add_action('wp_ajax_nopriv_subscribe_to_sendy', 'handle_subscribe_to_sendy_nav');
function handle_newsletter_ajax_error($error_code, $error_message, $context = array()) {
	error_log(sprintf(
		'Newsletter AJAX Error [%s]: %s - Context: %s',
		$error_code,
		$error_message,
		json_encode($context)
	));

	wp_send_json_error($error_message);
}

function validate_newsletter_ajax_security($nonce_action, $require_login = false) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		handle_newsletter_ajax_error('invalid_method', __('Invalid request method', 'extrachill-newsletter'));
		return false;
	}

	if (!check_ajax_referer($nonce_action, 'nonce', false)) {
		handle_newsletter_ajax_error('invalid_nonce', __('Security verification failed', 'extrachill-newsletter'));
		return false;
	}

	if ($require_login && !is_user_logged_in()) {
		handle_newsletter_ajax_error('login_required', __('Please log in to perform this action', 'extrachill-newsletter'));
		return false;
	}

	return true;
}

function log_newsletter_subscription_attempt($email, $source, $success, $error_message = '') {
	$log_data = array(
		'timestamp' => current_time('mysql'),
		'email' => $email,
		'source' => $source,
		'success' => $success,
		'ip_address' => $_SERVER['REMOTE_ADDR'],
		'user_agent' => $_SERVER['HTTP_USER_AGENT'],
	);

	if (!$success && $error_message) {
		$log_data['error'] = $error_message;
	}

	error_log('Newsletter Subscription: ' . json_encode($log_data));
	do_action('newsletter_subscription_logged', $log_data);
}

function handle_submit_newsletter_content_form() {
	if (!check_ajax_referer('newsletter_content_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	$result = extrachill_multisite_subscribe($email, 'content');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_content_form', 'handle_submit_newsletter_content_form');
add_action('wp_ajax_nopriv_submit_newsletter_content_form', 'handle_submit_newsletter_content_form');
function handle_submit_newsletter_footer_form() {
	if (!check_ajax_referer('newsletter_footer_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	$result = extrachill_multisite_subscribe($email, 'footer');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_footer_form', 'handle_submit_newsletter_footer_form');
add_action('wp_ajax_nopriv_submit_newsletter_footer_form', 'handle_submit_newsletter_footer_form');

/**
 * Festival Wire tip submission with Turnstile, rate limiting, and newsletter integration
 *
 * Non-members automatically subscribed to festival_wire_tip newsletter list.
 */
function handle_newsletter_festival_wire_tip_submission() {
	// Security and rate limiting verification
	if ( ! check_ajax_referer( 'newsletter_festival_tip_nonce', 'newsletter_festival_tip_nonce_field', false ) ) {
		wp_send_json_error( array( 'message' => __('Security check failed.', 'extrachill-newsletter') ) );
	}

	$user_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
	if ( ! empty( $user_ip ) && newsletter_is_tip_rate_limited( $user_ip ) ) {
		wp_send_json_error( array( 'message' => __('Please wait before submitting another tip.', 'extrachill-newsletter') ) );
	}

	// Community member detection via WordPress native authentication
	$is_community_member = is_user_logged_in();
	$user_details = null;
	if ( $is_community_member ) {
		$user = wp_get_current_user();
		$user_details = array(
			'username' => $user->user_nicename,
			'email' => $user->user_email,
			'userID' => $user->ID,
		);
	}

	// Input validation and sanitization
	$content = isset( $_POST['content'] ) ? sanitize_textarea_field( $_POST['content'] ) : '';
	$email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
	$turnstile_response = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( $_POST['cf-turnstile-response'] ) : '';
	$honeypot = isset( $_POST['website'] ) ? sanitize_text_field( $_POST['website'] ) : '';

	// Anti-spam honeypot check
	if ( ! empty( $honeypot ) ) {
		wp_send_json_error( array( 'message' => __('Spam detected.', 'extrachill-newsletter') ) );
	}

	if ( empty( $content ) ) {
		wp_send_json_error( array( 'message' => __('Please enter your tip.', 'extrachill-newsletter') ) );
	}

	// Email requirement for non-community members
	if ( ! $is_community_member ) {
		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => __('Email address is required.', 'extrachill-newsletter') ) );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __('Please enter a valid email address.', 'extrachill-newsletter') ) );
		}
	}

	// Content length validation
	if ( strlen( $content ) > 1000 ) {
		wp_send_json_error( array( 'message' => __('Your tip is too long. Please keep it under 1000 characters.', 'extrachill-newsletter') ) );
	}

	if ( strlen( $content ) < 10 ) {
		wp_send_json_error( array( 'message' => __('Please provide a more detailed tip (at least 10 characters).', 'extrachill-newsletter') ) );
	}

	// Cloudflare Turnstile anti-spam verification
	if ( ec_is_turnstile_configured() ) {
		if ( ! ec_verify_turnstile_response( $turnstile_response ) ) {
			wp_send_json_error( array( 'message' => __('Turnstile verification failed. Please try again.', 'extrachill-newsletter') ) );
		}
	}

	// Newsletter subscription for non-community members via integration system
	if ( ! $is_community_member && ! empty( $email ) ) {
		$newsletter_result = extrachill_multisite_subscribe( $email, 'festival_wire_tip' );
		if ( ! $newsletter_result['success'] ) {
			error_log( 'Festival tip newsletter subscription failed for email: ' . $email . ' - ' . $newsletter_result['message'] );
		}
	}

	// Email notification to admin
	$to = get_option( 'admin_email' );
	$subject = __('New Festival Wire Tip Submission', 'extrachill-newsletter');

	$message = __("A new festival tip has been submitted:\n\n", 'extrachill-newsletter');
	$message .= __("Tip: ", 'extrachill-newsletter') . $content . "\n\n";
	$message .= __("User Type: ", 'extrachill-newsletter') . ( $is_community_member ? __('Community Member (', 'extrachill-newsletter') . $user_details['username'] . ')' : __('Guest', 'extrachill-newsletter') ) . "\n";
	if ( ! $is_community_member && ! empty( $email ) ) {
		$message .= __("Email: ", 'extrachill-newsletter') . $email . "\n";
	}
	$message .= __("IP Address: ", 'extrachill-newsletter') . $user_ip . "\n";
	$message .= __("Submitted on: ", 'extrachill-newsletter') . current_time( 'mysql' ) . "\n";

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	$email_sent = wp_mail( $to, $subject, $message, $headers );

	// Handle submission result
	if ( $email_sent ) {
		if ( ! empty( $user_ip ) ) {
			newsletter_set_tip_rate_limit( $user_ip );
		}
		$success_message = $is_community_member
			? __('Thank you for your tip! We will review it soon.', 'extrachill-newsletter')
			: __('Thank you for your tip! We will review it soon and have added you to our festival updates.', 'extrachill-newsletter');
		wp_send_json_success( array( 'message' => $success_message ) );
	} else {
		wp_send_json_error( array( 'message' => __('There was an error sending your tip. Please try again later.', 'extrachill-newsletter') ) );
	}
}
add_action( 'wp_ajax_newsletter_festival_wire_tip_submission', 'handle_newsletter_festival_wire_tip_submission' );
add_action( 'wp_ajax_nopriv_newsletter_festival_wire_tip_submission', 'handle_newsletter_festival_wire_tip_submission' );
function newsletter_is_tip_rate_limited( $ip ) {
	$transient_key = 'newsletter_tip_rate_limit_' . md5( $ip );
	$last_submission = get_transient( $transient_key );

	return $last_submission !== false;
}

function newsletter_set_tip_rate_limit( $ip ) {
	$transient_key = 'newsletter_tip_rate_limit_' . md5( $ip );
	set_transient( $transient_key, time(), 300 );
}