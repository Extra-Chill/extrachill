<?php
/**
 * Newsletter AJAX Handlers
 *
 * Handles all AJAX requests for newsletter functionality including
 * subscription forms, campaign management, and popup interactions.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Handler: Push Newsletter to Sendy
 *
 * Handles the admin meta box "Push to Sendy" button functionality.
 * Creates or updates newsletter campaigns in Sendy via API.
 *
 * @since 1.0.0
 */
function handle_push_newsletter_to_sendy_ajax() {
	// Verify request method
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		wp_send_json_error(__('Invalid request type', 'extrachill-newsletter'), 400);
		return;
	}

	// Verify nonce
	if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'push_newsletter_to_sendy_nonce')) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'), 401);
		return;
	}

	// Verify post ID and permissions
	$post_id = intval($_POST['post_id']);
	if (!$post_id || !current_user_can('edit_post', $post_id)) {
		wp_send_json_error(__('Invalid post ID or insufficient permissions', 'extrachill-newsletter'), 403);
		return;
	}

	// Get post and verify it exists
	$post = get_post($post_id);
	if (!$post || $post->post_type !== 'newsletter') {
		wp_send_json_error(__('Newsletter not found', 'extrachill-newsletter'), 404);
		return;
	}

	// Prepare and send email campaign
	$email_data = prepare_newsletter_email_content($post);
	$result = send_newsletter_campaign_to_sendy($post_id, $email_data);

	if (is_wp_error($result)) {
		wp_send_json_error($result->get_error_message(), 500);
	} else {
		wp_send_json_success(__('Campaign successfully created or updated in Sendy', 'extrachill-newsletter'));
	}
}
add_action('wp_ajax_push_newsletter_to_sendy_ajax', 'handle_push_newsletter_to_sendy_ajax');

/**
 * AJAX Handler: Newsletter Archive Subscription Form
 *
 * Handles subscription requests from the newsletter archive page form.
 * Subscribes users to the main newsletter list.
 *
 * @since 1.0.0
 */
function handle_submit_newsletter_form() {
	// Verify nonce
	if (!check_ajax_referer('newsletter_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	// Sanitize and validate email
	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	// Subscribe to main newsletter list
	$result = subscribe_email_to_sendy($email, 'archive');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_form', 'handle_submit_newsletter_form');
add_action('wp_ajax_nopriv_submit_newsletter_form', 'handle_submit_newsletter_form');

/**
 * AJAX Handler: Newsletter Popup Subscription Form
 *
 * Handles subscription requests from the site-wide newsletter popup.
 * Uses a separate list for popup-specific tracking.
 *
 * @since 1.0.0
 */
function handle_submit_newsletter_popup_form() {
	// Verify nonce
	if (!check_ajax_referer('newsletter_popup_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	// Sanitize and validate email
	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	// Subscribe to popup-specific list
	$result = subscribe_email_to_sendy($email, 'popup');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_popup_form', 'handle_submit_newsletter_popup_form');
add_action('wp_ajax_nopriv_submit_newsletter_popup_form', 'handle_submit_newsletter_popup_form');

/**
 * AJAX Handler: Homepage Newsletter Subscription Form
 *
 * Handles subscription requests from the homepage newsletter section.
 * Uses homepage-specific list for tracking source attribution.
 *
 * @since 1.0.0
 */
function handle_subscribe_to_sendy_home() {
	// Verify nonce
	if (!check_ajax_referer('subscribe_to_sendy_home_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	// Sanitize and validate email
	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	// Subscribe to homepage-specific list
	$result = subscribe_email_to_sendy($email, 'homepage');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_subscribe_to_sendy_home', 'handle_subscribe_to_sendy_home');
add_action('wp_ajax_nopriv_subscribe_to_sendy_home', 'handle_subscribe_to_sendy_home');

/**
 * AJAX Handler: Navigation Menu Newsletter Subscription
 *
 * Handles subscription requests from the navigation menu newsletter form.
 * Uses main newsletter list for general subscriptions.
 *
 * @since 1.0.0
 */
function handle_subscribe_to_sendy_nav() {
	// Verify nonce (using standard subscription nonce)
	if (!check_ajax_referer('newsletter_nonce', 'subscribe_nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	// Sanitize and validate email
	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	// Subscribe to navigation newsletter list
	$result = subscribe_email_to_sendy($email, 'navigation');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_subscribe_to_sendy', 'handle_subscribe_to_sendy_nav');
add_action('wp_ajax_nopriv_subscribe_to_sendy', 'handle_subscribe_to_sendy_nav');


/**
 * Handle AJAX errors gracefully
 *
 * Provides consistent error handling and logging for all AJAX endpoints.
 * Can be extended for user-friendly error messages.
 *
 * @since 1.0.0
 * @param string $error_code Error identifier
 * @param string $error_message User-facing error message
 * @param array $context Additional error context for logging
 */
function handle_newsletter_ajax_error($error_code, $error_message, $context = array()) {
	// Log error with context for debugging
	error_log(sprintf(
		'Newsletter AJAX Error [%s]: %s - Context: %s',
		$error_code,
		$error_message,
		json_encode($context)
	));

	// Send user-friendly error response
	wp_send_json_error($error_message);
}

/**
 * Validate AJAX request security
 *
 * Centralized security validation for newsletter AJAX requests.
 * Checks nonces, capabilities, and request parameters.
 *
 * @since 1.0.0
 * @param string $nonce_action Nonce action to verify
 * @param bool $require_login Whether user login is required
 * @return bool True if valid, false otherwise
 */
function validate_newsletter_ajax_security($nonce_action, $require_login = false) {
	// Check request method
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		handle_newsletter_ajax_error('invalid_method', __('Invalid request method', 'extrachill-newsletter'));
		return false;
	}

	// Verify nonce
	if (!check_ajax_referer($nonce_action, 'nonce', false)) {
		handle_newsletter_ajax_error('invalid_nonce', __('Security verification failed', 'extrachill-newsletter'));
		return false;
	}

	// Check login requirement
	if ($require_login && !is_user_logged_in()) {
		handle_newsletter_ajax_error('login_required', __('Please log in to perform this action', 'extrachill-newsletter'));
		return false;
	}

	return true;
}

/**
 * Log newsletter subscription attempts
 *
 * Tracks subscription attempts for analytics and debugging.
 * Can be extended for conversion rate tracking.
 *
 * @since 1.0.0
 * @param string $email Email address
 * @param string $source Subscription source (archive, popup, homepage, nav)
 * @param bool $success Whether subscription succeeded
 * @param string $error_message Error message if failed
 */
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

	// Log to WordPress error log for now
	// Can be extended to store in custom database table
	error_log('Newsletter Subscription: ' . json_encode($log_data));

	// Hook for custom tracking systems
	do_action('newsletter_subscription_logged', $log_data);
}

/**
 * AJAX Handler: Content Newsletter Subscription Form
 *
 * Handles subscription requests from the post-content newsletter form.
 * Uses content-specific list for tracking engagement attribution.
 *
 * @since 1.0.0
 */
function handle_submit_newsletter_content_form() {
	// Verify nonce
	if (!check_ajax_referer('newsletter_content_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	// Sanitize and validate email
	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	// Subscribe to content-specific list
	$result = subscribe_email_to_sendy($email, 'content');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_content_form', 'handle_submit_newsletter_content_form');
add_action('wp_ajax_nopriv_submit_newsletter_content_form', 'handle_submit_newsletter_content_form');

/**
 * AJAX Handler: Footer Newsletter Subscription Form
 *
 * Handles subscription requests from the footer newsletter form.
 * Uses footer-specific list for tracking source attribution.
 *
 * @since 1.0.0
 */
function handle_submit_newsletter_footer_form() {
	// Verify nonce
	if (!check_ajax_referer('newsletter_footer_nonce', 'nonce', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	// Sanitize and validate email
	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	// Subscribe to footer-specific list
	$result = subscribe_email_to_sendy($email, 'footer');

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_footer_form', 'handle_submit_newsletter_footer_form');
add_action('wp_ajax_nopriv_submit_newsletter_footer_form', 'handle_submit_newsletter_footer_form');

/**
 * AJAX Handler: Festival Wire Tip Submission
 *
 * Handles Festival Wire tip submissions with comprehensive validation:
 * - Nonce and rate limiting verification
 * - Community member detection via session cookie
 * - Cloudflare Turnstile anti-spam verification
 * - Email validation and newsletter subscription for non-members
 * - Admin email notification
 *
 * @since 2.0.0
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
	$turnstile_secret_key = get_option( 'ec_turnstile_secret_key' );
	if ( ! empty( $turnstile_secret_key ) ) {
		$verify_result = newsletter_verify_turnstile_response( $turnstile_response, $turnstile_secret_key );

		if ( ! $verify_result['success'] ) {
			wp_send_json_error( array( 'message' => __('Turnstile verification failed. Please try again.', 'extrachill-newsletter') ) );
		}
	}

	// Newsletter subscription for non-community members via integration system
	if ( ! $is_community_member && ! empty( $email ) ) {
		$newsletter_result = subscribe_via_integration( $email, 'festival_wire_tip' );
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

/**
 * Verify Cloudflare Turnstile anti-spam response
 *
 * Validates Turnstile token with Cloudflare API for spam protection.
 * Includes comprehensive error handling and logging.
 *
 * @since 2.0.0
 * @param string $turnstile_response The turnstile response token
 * @param string $secret_key The secret key for Turnstile
 * @return array Verification result with success status
 */
function newsletter_verify_turnstile_response( $turnstile_response, $secret_key ) {
	$verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	$args = array(
		'body' => array(
			'secret' => $secret_key,
			'response' => $turnstile_response,
			'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
		),
        'timeout' => 15,
	);

	$response = wp_remote_post( $verify_url, $args );

	// Handle connection errors
	if ( is_wp_error( $response ) ) {
        error_log('Newsletter Turnstile Verification Error: ' . $response->get_error_message());
		return array( 'success' => false, 'error' => 'Connection error: ' . $response->get_error_message() );
	}

	// Validate HTTP response
	$response_code = wp_remote_retrieve_response_code( $response );
    if ( $response_code !== 200 ) {
        error_log('Newsletter Turnstile Verification HTTP Error: Code ' . $response_code . ' Body: ' . wp_remote_retrieve_body($response));
        return array( 'success' => false, 'error' => 'HTTP error: ' . $response_code );
    }

	// Parse and validate JSON response
	$response_body = wp_remote_retrieve_body( $response );
	$result = json_decode( $response_body, true );

    if ( $result === null ) {
        error_log('Newsletter Turnstile Verification JSON Decode Error: Body - ' . $response_body);
        return array( 'success' => false, 'error' => 'Invalid response format' );
    }

    // Log verification failures and validate response format
    if ( isset( $result['success'] ) && ! $result['success'] && isset( $result['error-codes'] ) ) {
         error_log('Newsletter Turnstile Verification Failed: ' . implode(', ', $result['error-codes']));
    } elseif ( ! isset( $result['success'] ) ) {
         error_log('Newsletter Turnstile Verification Unexpected Response: ' . $response_body);
         return array( 'success' => false, 'error' => 'Unexpected response format' );
    }

	return $result;
}

/**
 * Check IP address rate limiting for tip submissions
 *
 * Prevents spam by limiting submission frequency per IP address.
 * Uses WordPress transients for temporary storage.
 *
 * @since 2.0.0
 * @param string $ip The IP address to check
 * @return bool True if rate limited, false otherwise
 */
function newsletter_is_tip_rate_limited( $ip ) {
	$transient_key = 'newsletter_tip_rate_limit_' . md5( $ip );
	$last_submission = get_transient( $transient_key );

	return $last_submission !== false;
}

/**
 * Set rate limit for IP address after successful submission
 *
 * Creates temporary block for IP address to prevent rapid submissions.
 * Rate limit duration is 5 minutes (300 seconds).
 *
 * @since 2.0.0
 * @param string $ip The IP address to rate limit
 */
function newsletter_set_tip_rate_limit( $ip ) {
	$transient_key = 'newsletter_tip_rate_limit_' . md5( $ip );
	set_transient( $transient_key, time(), 300 ); // 5 minutes
}