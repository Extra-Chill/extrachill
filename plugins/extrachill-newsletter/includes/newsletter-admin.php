<?php
/**
 * Newsletter Admin Functionality
 *
 * Handles admin menu registration and settings management
 * for the newsletter plugin administration interface.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add Sendy Settings submenu to Newsletter menu
 *
 * @since 1.0.0
 */
function newsletter_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=newsletter',
		__('Sendy Settings', 'extrachill-newsletter'),
		__('Sendy Settings', 'extrachill-newsletter'),
		'manage_options',
		'newsletter-sendy-settings',
		'newsletter_settings_page'
	);
}
add_action('admin_menu', 'newsletter_admin_menu');

/**
 * Display the settings page
 *
 * @since 1.0.0
 */
function newsletter_settings_page() {
	// Check user permissions
	if (!current_user_can('manage_options')) {
		wp_die(__('You do not have sufficient permissions to access this page.', 'extrachill-newsletter'));
	}

	// Load the settings page template
	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'settings-page.php';
}

/**
 * Initialize admin settings
 *
 * @since 1.0.0
 */
function newsletter_admin_init() {
	// Register settings
	register_setting(
		'newsletter_settings_group',
		'extrachill_newsletter_settings',
		array(
			'sanitize_callback' => 'newsletter_sanitize_settings',
		)
	);

	// API Configuration Section
	add_settings_section(
		'newsletter_api_section',
		__('API Configuration', 'extrachill-newsletter'),
		'newsletter_api_section_callback',
		'newsletter_settings'
	);

	// List Configuration Section
	add_settings_section(
		'newsletter_lists_section',
		__('List Configuration', 'extrachill-newsletter'),
		'newsletter_lists_section_callback',
		'newsletter_settings'
	);

	// Email Configuration Section
	add_settings_section(
		'newsletter_email_section',
		__('Email Configuration', 'extrachill-newsletter'),
		'newsletter_email_section_callback',
		'newsletter_settings'
	);

	// Feature Configuration Section
	add_settings_section(
		'newsletter_features_section',
		__('Feature Configuration', 'extrachill-newsletter'),
		'newsletter_features_section_callback',
		'newsletter_settings'
	);

	// Add individual fields
	newsletter_add_settings_fields();
}
add_action('admin_init', 'newsletter_admin_init');

/**
 * Add settings fields
 *
 * @since 1.0.0
 */
function newsletter_add_settings_fields() {
	// API fields
	add_settings_field(
		'sendy_api_key',
		__('Sendy API Key', 'extrachill-newsletter'),
		'newsletter_field_api_key',
		'newsletter_settings',
		'newsletter_api_section'
	);

	add_settings_field(
		'sendy_url',
		__('Sendy URL', 'extrachill-newsletter'),
		'newsletter_field_sendy_url',
		'newsletter_settings',
		'newsletter_api_section'
	);

	// List fields
	add_settings_field(
		'archive_list_id',
		__('Archive Page List ID', 'extrachill-newsletter'),
		'newsletter_field_archive_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	add_settings_field(
		'homepage_list_id',
		__('Homepage List ID', 'extrachill-newsletter'),
		'newsletter_field_homepage_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	add_settings_field(
		'popup_list_id',
		__('Popup List ID', 'extrachill-newsletter'),
		'newsletter_field_popup_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	add_settings_field(
		'navigation_list_id',
		__('Navigation List ID', 'extrachill-newsletter'),
		'newsletter_field_navigation_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	add_settings_field(
		'content_list_id',
		__('Content Form List ID', 'extrachill-newsletter'),
		'newsletter_field_content_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	add_settings_field(
		'footer_list_id',
		__('Footer Form List ID', 'extrachill-newsletter'),
		'newsletter_field_footer_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	add_settings_field(
		'contact_list_id',
		__('Contact Form List ID', 'extrachill-newsletter'),
		'newsletter_field_contact_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	add_settings_field(
		'campaign_list_id',
		__('Campaign List ID', 'extrachill-newsletter'),
		'newsletter_field_campaign_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	// Email fields
	add_settings_field(
		'from_name',
		__('From Name', 'extrachill-newsletter'),
		'newsletter_field_from_name',
		'newsletter_settings',
		'newsletter_email_section'
	);

	add_settings_field(
		'from_email',
		__('From Email', 'extrachill-newsletter'),
		'newsletter_field_from_email',
		'newsletter_settings',
		'newsletter_email_section'
	);

	add_settings_field(
		'reply_to',
		__('Reply To', 'extrachill-newsletter'),
		'newsletter_field_reply_to',
		'newsletter_settings',
		'newsletter_email_section'
	);

	add_settings_field(
		'brand_id',
		__('Brand ID', 'extrachill-newsletter'),
		'newsletter_field_brand_id',
		'newsletter_settings',
		'newsletter_email_section'
	);

	// Feature fields
	add_settings_field(
		'enable_popup',
		__('Enable Popup', 'extrachill-newsletter'),
		'newsletter_field_enable_popup',
		'newsletter_settings',
		'newsletter_features_section'
	);

	add_settings_field(
		'enable_navigation',
		__('Enable Navigation Form', 'extrachill-newsletter'),
		'newsletter_field_enable_navigation',
		'newsletter_settings',
		'newsletter_features_section'
	);

	add_settings_field(
		'enable_content',
		__('Enable Content Form', 'extrachill-newsletter'),
		'newsletter_field_enable_content',
		'newsletter_settings',
		'newsletter_features_section'
	);

	add_settings_field(
		'enable_footer',
		__('Enable Footer Form', 'extrachill-newsletter'),
		'newsletter_field_enable_footer',
		'newsletter_settings',
		'newsletter_features_section'
	);
}

/**
 * Sanitize settings before saving
 *
 * @since 1.0.0
 * @param array $input Raw input data
 * @return array Sanitized data
 */
function newsletter_sanitize_settings($input) {
	$sanitized = array();

	// API Configuration
	$sanitized['sendy_api_key'] = sanitize_text_field($input['sendy_api_key'] ?? '');
	$sanitized['sendy_url'] = esc_url_raw($input['sendy_url'] ?? '');

	// List Configuration
	$sanitized['archive_list_id'] = sanitize_text_field($input['archive_list_id'] ?? '');
	$sanitized['homepage_list_id'] = sanitize_text_field($input['homepage_list_id'] ?? '');
	$sanitized['popup_list_id'] = sanitize_text_field($input['popup_list_id'] ?? '');
	$sanitized['navigation_list_id'] = sanitize_text_field($input['navigation_list_id'] ?? '');
	$sanitized['content_list_id'] = sanitize_text_field($input['content_list_id'] ?? '');
	$sanitized['footer_list_id'] = sanitize_text_field($input['footer_list_id'] ?? '');
	$sanitized['contact_list_id'] = sanitize_text_field($input['contact_list_id'] ?? '');
	$sanitized['campaign_list_id'] = sanitize_text_field($input['campaign_list_id'] ?? '');

	// Email Configuration
	$sanitized['from_name'] = sanitize_text_field($input['from_name'] ?? 'Extra Chill');
	$sanitized['from_email'] = sanitize_email($input['from_email'] ?? 'newsletter@extrachill.com');
	$sanitized['reply_to'] = sanitize_email($input['reply_to'] ?? 'chubes@extrachill.com');
	$sanitized['brand_id'] = sanitize_text_field($input['brand_id'] ?? '1');

	// Feature Configuration
	$sanitized['enable_popup'] = !empty($input['enable_popup']) ? 1 : 0;
	$sanitized['enable_navigation'] = !empty($input['enable_navigation']) ? 1 : 0;
	$sanitized['enable_content'] = !empty($input['enable_content']) ? 1 : 0;
	$sanitized['enable_footer'] = !empty($input['enable_footer']) ? 1 : 0;
	$sanitized['popup_exclusion_pages'] = sanitize_textarea_field($input['popup_exclusion_pages'] ?? '');

	return $sanitized;
}

/**
 * Get newsletter settings with defaults
 *
 * @since 1.0.0
 * @return array Settings array
 */
function get_newsletter_settings() {
	$defaults = array(
		'sendy_api_key' => '',
		'sendy_url' => 'https://mail.extrachill.com/sendy',
		'archive_list_id' => '',
		'homepage_list_id' => '',
		'popup_list_id' => '',
		'navigation_list_id' => '',
		'content_list_id' => '',
		'footer_list_id' => '',
		'contact_list_id' => '',
		'campaign_list_id' => '',
		'from_name' => 'Extra Chill',
		'from_email' => 'newsletter@extrachill.com',
		'reply_to' => 'chubes@extrachill.com',
		'brand_id' => '1',
		'enable_popup' => 1,
		'enable_navigation' => 1,
		'enable_content' => 1,
		'enable_footer' => 1,
		'popup_exclusion_pages' => "home\ncontact-us\nfestival-wire"
	);

	$settings = get_option('extrachill_newsletter_settings', array());
	return wp_parse_args($settings, $defaults);
}

// Section callbacks
function newsletter_api_section_callback() {
	echo '<p>' . __('Configure your Sendy API connection settings.', 'extrachill-newsletter') . '</p>';
}

function newsletter_lists_section_callback() {
	echo '<p>' . __('Set the Sendy list IDs for different subscription forms.', 'extrachill-newsletter') . '</p>';
}

function newsletter_email_section_callback() {
	echo '<p>' . __('Configure email sender information and branding.', 'extrachill-newsletter') . '</p>';
}

function newsletter_features_section_callback() {
	echo '<p>' . __('Enable or disable newsletter features throughout the site.', 'extrachill-newsletter') . '</p>';
}

// Field callbacks
function newsletter_field_api_key() {
	$settings = get_newsletter_settings();
	$value = $settings['sendy_api_key'];
	echo '<input type="password" name="extrachill_newsletter_settings[sendy_api_key]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('Your Sendy API key from Sendy settings.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_sendy_url() {
	$settings = get_newsletter_settings();
	$value = $settings['sendy_url'];
	echo '<input type="url" name="extrachill_newsletter_settings[sendy_url]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('Your Sendy installation URL (without trailing slash).', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_archive_list() {
	$settings = get_newsletter_settings();
	$value = $settings['archive_list_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[archive_list_id]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('List ID for newsletter archive page subscriptions.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_homepage_list() {
	$settings = get_newsletter_settings();
	$value = $settings['homepage_list_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[homepage_list_id]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('List ID for homepage newsletter subscriptions.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_popup_list() {
	$settings = get_newsletter_settings();
	$value = $settings['popup_list_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[popup_list_id]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('List ID for newsletter popup subscriptions.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_navigation_list() {
	$settings = get_newsletter_settings();
	$value = $settings['navigation_list_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[navigation_list_id]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('List ID for navigation menu subscriptions.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_content_list() {
	$settings = get_newsletter_settings();
	$value = $settings['content_list_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[content_list_id]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('List ID for post-content form subscriptions.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_footer_list() {
	$settings = get_newsletter_settings();
	$value = $settings['footer_list_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[footer_list_id]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('List ID for footer form subscriptions.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_contact_list() {
	$settings = get_newsletter_settings();
	$value = $settings['contact_list_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[contact_list_id]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('List ID for contact form subscriptions.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_campaign_list() {
	$settings = get_newsletter_settings();
	$value = $settings['campaign_list_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[campaign_list_id]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('List ID for newsletter campaigns (comma-separated for multiple lists).', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_from_name() {
	$settings = get_newsletter_settings();
	$value = $settings['from_name'];
	echo '<input type="text" name="extrachill_newsletter_settings[from_name]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('Name that appears in the "From" field of newsletters.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_from_email() {
	$settings = get_newsletter_settings();
	$value = $settings['from_email'];
	echo '<input type="email" name="extrachill_newsletter_settings[from_email]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('Email address that appears in the "From" field of newsletters.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_reply_to() {
	$settings = get_newsletter_settings();
	$value = $settings['reply_to'];
	echo '<input type="email" name="extrachill_newsletter_settings[reply_to]" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . __('Email address for replies to newsletters.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_brand_id() {
	$settings = get_newsletter_settings();
	$value = $settings['brand_id'];
	echo '<input type="text" name="extrachill_newsletter_settings[brand_id]" value="' . esc_attr($value) . '" class="small-text" />';
	echo '<p class="description">' . __('Sendy brand ID for newsletter campaigns.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_enable_popup() {
	$settings = get_newsletter_settings();
	$checked = $settings['enable_popup'] ? 'checked="checked"' : '';
	echo '<label><input type="checkbox" name="extrachill_newsletter_settings[enable_popup]" value="1" ' . $checked . ' /> ';
	echo __('Enable newsletter popup on site pages', 'extrachill-newsletter') . '</label>';

	echo '<br><br><label for="popup_exclusion_pages">' . __('Pages to exclude from popup (one slug per line):', 'extrachill-newsletter') . '</label><br>';
	echo '<textarea name="extrachill_newsletter_settings[popup_exclusion_pages]" id="popup_exclusion_pages" rows="4" cols="50">' . esc_textarea($settings['popup_exclusion_pages']) . '</textarea>';
	echo '<p class="description">' . __('Enter page slugs (one per line) where the popup should not appear.', 'extrachill-newsletter') . '</p>';
}

function newsletter_field_enable_navigation() {
	$settings = get_newsletter_settings();
	$checked = $settings['enable_navigation'] ? 'checked="checked"' : '';
	echo '<label><input type="checkbox" name="extrachill_newsletter_settings[enable_navigation]" value="1" ' . $checked . ' /> ';
	echo __('Enable newsletter form in navigation menu', 'extrachill-newsletter') . '</label>';
}

function newsletter_field_enable_content() {
	$settings = get_newsletter_settings();
	$checked = $settings['enable_content'] ? 'checked="checked"' : '';
	echo '<label><input type="checkbox" name="extrachill_newsletter_settings[enable_content]" value="1" ' . $checked . ' /> ';
	echo __('Enable newsletter form after post content', 'extrachill-newsletter') . '</label>';
}

function newsletter_field_enable_footer() {
	$settings = get_newsletter_settings();
	$checked = $settings['enable_footer'] ? 'checked="checked"' : '';
	echo '<label><input type="checkbox" name="extrachill_newsletter_settings[enable_footer]" value="1" ' . $checked . ' /> ';
	echo __('Enable newsletter form above footer', 'extrachill-newsletter') . '</label>';
}

/**
 * Enqueue admin assets
 *
 * @since 1.0.0
 */
function newsletter_admin_assets($hook) {
	// Only load on our settings page
	if ('newsletter_page_newsletter-sendy-settings' !== $hook) {
		return;
	}

	// Enqueue admin CSS
	$css_file = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/admin.css';
	if (file_exists($css_file)) {
		wp_enqueue_style(
			'newsletter-admin',
			EXTRACHILL_NEWSLETTER_ASSETS_URL . 'admin.css',
			array(),
			filemtime($css_file)
		);
	}

	// Enqueue admin JS
	$js_file = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/admin.js';
	if (file_exists($js_file)) {
		wp_enqueue_script(
			'newsletter-admin',
			EXTRACHILL_NEWSLETTER_ASSETS_URL . 'admin.js',
			array('jquery'),
			filemtime($js_file),
			true
		);

		// Localize script for AJAX
		wp_localize_script('newsletter-admin', 'newsletterAdmin', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('newsletter_admin_nonce'),
			'messages' => array(
				'missing_fields' => __('Please enter API key and Sendy URL first.', 'extrachill-newsletter'),
				'testing' => __('Testing...', 'extrachill-newsletter'),
				'connection_failed' => __('Connection test failed', 'extrachill-newsletter'),
				'test_connection' => __('Test Connection', 'extrachill-newsletter')
			)
		));
	}
}
add_action('admin_enqueue_scripts', 'newsletter_admin_assets');

/**
 * AJAX handler for testing Sendy connection
 *
 * @since 1.0.0
 */
function newsletter_test_connection() {
	// Check nonce and permissions
	if (!wp_verify_nonce($_POST['nonce'], 'newsletter_admin_nonce') || !current_user_can('manage_options')) {
		wp_send_json_error(__('Permission denied', 'extrachill-newsletter'));
		return;
	}

	$api_key = sanitize_text_field($_POST['api_key']);
	$sendy_url = esc_url_raw($_POST['sendy_url']);

	if (empty($api_key) || empty($sendy_url)) {
		wp_send_json_error(__('API key and Sendy URL are required', 'extrachill-newsletter'));
		return;
	}

	// Test connection by checking API key
	$test_data = array(
		'api_key' => $api_key,
	);

	$response = wp_remote_post($sendy_url . '/api/campaigns/create.php', array(
		'body' => $test_data,
		'timeout' => 10,
	));

	if (is_wp_error($response)) {
		wp_send_json_error(__('Connection failed: ', 'extrachill-newsletter') . $response->get_error_message());
		return;
	}

	$body = wp_remote_retrieve_body($response);

	// Check if API key is valid (Sendy returns specific messages for invalid keys)
	if (strpos($body, 'Invalid API key') !== false) {
		wp_send_json_error(__('Invalid API key', 'extrachill-newsletter'));
		return;
	}

	wp_send_json_success(__('Connection test successful', 'extrachill-newsletter'));
}
add_action('wp_ajax_newsletter_test_connection', 'newsletter_test_connection');