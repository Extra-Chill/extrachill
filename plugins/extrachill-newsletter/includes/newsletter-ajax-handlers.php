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
 * Enqueue Newsletter Popup Scripts
 *
 * Conditionally loads newsletter popup JavaScript based on user session
 * and page context. Excludes certain pages from popup display.
 *
 * @since 1.0.0
 */
function enqueue_newsletter_popup_scripts() {
	// Don't load if user has session token (community user)
	if (isset($_COOKIE['ecc_user_session_token'])) {
		return;
	}

	// Determine if popup should load on current page
	$load_script = true;

	// Exclude homepage
	if (is_front_page()) {
		$load_script = false;
	}

	// Exclude contact page
	if (is_page('contact-us')) {
		$load_script = false;
	}

	// Exclude Festival Wire archive
	if (is_post_type_archive('festival_wire')) {
		$load_script = false;
	}

	// Load popup script if conditions are met
	if ($load_script) {
		$script_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter.js';
		if (file_exists($script_path)) {
			wp_enqueue_script(
				'newsletter-popup-subscribe',
				EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter.js',
				array('jquery'),
				filemtime($script_path),
				true
			);

			// Localize popup-specific variables
			wp_localize_script('newsletter-popup-subscribe', 'newsletter_vars', array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('newsletter_popup_nonce')
			));
		}
	}
}

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