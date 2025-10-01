<?php
/**
 * Newsletter Popup Module
 *
 * Handles newsletter popup functionality as a dedicated module.
 * Manages popup display conditions, script enqueuing, and settings.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize newsletter popup module
 *
 * Registers hooks only if popup is enabled in settings.
 * This ensures the popup can be completely disabled.
 *
 * @since 1.0.1
 */
function newsletter_popup_init() {
	// Check if popup is enabled in admin settings
	$settings = get_site_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_popup'])) {
		return; // Exit early if popup is disabled
	}

	// Register popup hooks only when enabled
	add_action('wp_footer', 'newsletter_popup_enqueue_scripts');
}
add_action('init', 'newsletter_popup_init');

/**
 * Enqueue Newsletter Popup Scripts
 *
 * Conditionally loads newsletter popup JavaScript based on user session
 * and page context. Excludes certain pages from popup display.
 *
 * @since 1.0.1
 */
function newsletter_popup_enqueue_scripts() {
	// Don't load if user is logged in (community user)
	if (is_user_logged_in()) {
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

	// Allow filtering of popup exclusions
	$load_script = apply_filters('newsletter_popup_should_load', $load_script);

	// Load popup script if conditions are met
	if ($load_script) {
		$script_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter-popup.js';
		if (file_exists($script_path)) {
			wp_enqueue_script(
				'newsletter-popup',
				EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter-popup.js',
				array('jquery'),
				filemtime($script_path),
				true
			);

			// Localize popup-specific variables
			wp_localize_script('newsletter-popup', 'newsletter_popup_vars', array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('newsletter_popup_nonce'),
				'popup_text' => apply_filters('newsletter_popup_text', 'Independent music journalism with personality! Enter your email for a good time.'),
				'placeholder_text' => apply_filters('newsletter_popup_placeholder', 'Enter your email'),
				'button_text' => apply_filters('newsletter_popup_button', 'Subscribe'),
				'close_text' => apply_filters('newsletter_popup_close', "Sorry, I'm Not That Chill"),
				'instagram_url' => apply_filters('newsletter_popup_instagram', 'https://www.instagram.com/extrachill'),
				'newsletters_url' => apply_filters('newsletter_popup_archive', '/newsletters')
			));
		}
	}
}

/**
 * Check if popup should be excluded on current page
 *
 * Allows other plugins/themes to add popup exclusions.
 *
 * @since 1.0.1
 * @param array $exclusions Array of page exclusion conditions
 * @return array Modified exclusions array
 */
function newsletter_popup_default_exclusions($exclusions = array()) {
	// Default exclusions
	$default_exclusions = array(
		'homepage' => is_front_page(),
		'contact' => is_page('contact-us'),
		'festival_wire_archive' => is_post_type_archive('festival_wire'),
		'logged_in_users' => is_user_logged_in()
	);

	return array_merge($exclusions, $default_exclusions);
}
add_filter('newsletter_popup_exclusions', 'newsletter_popup_default_exclusions');