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

	// Integration Configuration Section
	add_settings_section(
		'newsletter_integrations_section',
		__('Integration Configuration', 'extrachill-newsletter'),
		'newsletter_integrations_section_callback',
		'newsletter_settings'
	);

	// Add individual fields
	newsletter_add_settings_fields();
}
add_action('admin_init', 'newsletter_admin_init');

/**
 * Add settings fields
 *
 * Dynamically generates settings fields based on registered integrations.
 *
 * @since 2.0.0
 */
function newsletter_add_settings_fields() {
	// API fields (static)
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

	// Email fields (static)
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

	// Campaign list field (static)
	add_settings_field(
		'campaign_list_id',
		__('Campaign List ID', 'extrachill-newsletter'),
		'newsletter_field_campaign_list',
		'newsletter_settings',
		'newsletter_lists_section'
	);

	// Dynamic integration fields
	newsletter_add_integration_fields();
}

/**
 * Add dynamic integration fields
 *
 * Generates settings fields for all registered newsletter integrations.
 *
 * @since 2.0.0
 */
function newsletter_add_integration_fields() {
	$integrations = get_newsletter_integrations();

	foreach ($integrations as $context => $integration) {
		// Add enable/disable field
		add_settings_field(
			$integration['enable_key'],
			$integration['label'],
			function() use ($integration) {
				newsletter_field_integration_enable($integration);
			},
			'newsletter_settings',
			'newsletter_integrations_section'
		);

		// Add list ID field
		add_settings_field(
			$integration['list_id_key'],
			sprintf(__('%s List ID', 'extrachill-newsletter'), $integration['label']),
			function() use ($integration) {
				newsletter_field_integration_list_id($integration);
			},
			'newsletter_settings',
			'newsletter_lists_section'
		);
	}
}

/**
 * Sanitize settings before saving
 *
 * Dynamically sanitizes all settings including registered integrations.
 *
 * @since 2.0.0
 * @param array $input Raw input data
 * @return array Sanitized data
 */
function newsletter_sanitize_settings($input) {
	$sanitized = array();

	// API Configuration (static)
	$sanitized['sendy_api_key'] = sanitize_text_field($input['sendy_api_key'] ?? '');
	$sanitized['sendy_url'] = esc_url_raw($input['sendy_url'] ?? '');

	// Email Configuration (static)
	$sanitized['from_name'] = sanitize_text_field($input['from_name'] ?? 'Extra Chill');
	$sanitized['from_email'] = sanitize_email($input['from_email'] ?? 'newsletter@extrachill.com');
	$sanitized['reply_to'] = sanitize_email($input['reply_to'] ?? 'chubes@extrachill.com');
	$sanitized['brand_id'] = sanitize_text_field($input['brand_id'] ?? '1');

	// Campaign list (static)
	$sanitized['campaign_list_id'] = sanitize_text_field($input['campaign_list_id'] ?? '');

	// Legacy field
	$sanitized['popup_exclusion_pages'] = sanitize_textarea_field($input['popup_exclusion_pages'] ?? '');

	// Dynamic integration fields
	$integrations = get_newsletter_integrations();
	foreach ($integrations as $context => $integration) {
		// Sanitize enable key
		$sanitized[$integration['enable_key']] = !empty($input[$integration['enable_key']]) ? 1 : 0;

		// Sanitize list ID key
		$sanitized[$integration['list_id_key']] = sanitize_text_field($input[$integration['list_id_key']] ?? '');
	}

	return $sanitized;
}

/**
 * Get newsletter settings with defaults
 *
 * Dynamically generates defaults based on registered integrations.
 *
 * @since 2.0.0
 * @return array Settings array
 */
function get_newsletter_settings() {
	$defaults = array(
		'sendy_api_key' => '',
		'sendy_url' => 'https://mail.extrachill.com/sendy',
		'campaign_list_id' => '',
		'from_name' => 'Extra Chill',
		'from_email' => 'newsletter@extrachill.com',
		'reply_to' => 'chubes@extrachill.com',
		'brand_id' => '1',
		'popup_exclusion_pages' => "home\ncontact-us\nfestival-wire"
	);

	// Add defaults for registered integrations
	$integrations = get_newsletter_integrations();
	foreach ($integrations as $context => $integration) {
		$defaults[$integration['enable_key']] = 1; // Enable by default
		$defaults[$integration['list_id_key']] = '';
	}

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

function newsletter_integrations_section_callback() {
	echo '<p>' . __('Configure newsletter integrations from all active plugins.', 'extrachill-newsletter') . '</p>';

	// Show integration status overview
	$integrations = get_newsletter_integrations();
	if (!empty($integrations)) {
		echo '<div class="newsletter-integrations-overview">';
		echo '<h4>' . __('Registered Integrations:', 'extrachill-newsletter') . '</h4>';
		echo '<ul>';
		foreach ($integrations as $context => $integration) {
			$enabled = newsletter_integration_enabled($integration['enable_key']);
			$status = $enabled ? __('Enabled', 'extrachill-newsletter') : __('Disabled', 'extrachill-newsletter');
			$status_class = $enabled ? 'enabled' : 'disabled';
			echo '<li>';
			echo '<strong>' . esc_html($integration['label']) . '</strong> ';
			echo '<span class="integration-status ' . esc_attr($status_class) . '">(' . esc_html($status) . ')</span>';
			echo '<br><em>' . esc_html($integration['description']) . '</em>';
			if (!empty($integration['plugin'])) {
				echo '<br><small>' . sprintf(__('Provided by: %s', 'extrachill-newsletter'), esc_html($integration['plugin'])) . '</small>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}
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


/**
 * Dynamic integration enable field
 *
 * Renders enable/disable checkbox for a specific integration.
 *
 * @since 2.0.0
 * @param array $integration Integration configuration
 */
function newsletter_field_integration_enable($integration) {
	$settings = get_newsletter_settings();
	$checked = !empty($settings[$integration['enable_key']]) ? 'checked="checked"' : '';
	echo '<label><input type="checkbox" name="extrachill_newsletter_settings[' . esc_attr($integration['enable_key']) . ']" value="1" ' . $checked . ' /> ';
	echo sprintf(__('Enable %s', 'extrachill-newsletter'), esc_html($integration['label'])) . '</label>';
	if (!empty($integration['description'])) {
		echo '<p class="description">' . esc_html($integration['description']) . '</p>';
	}
	if (!empty($integration['plugin'])) {
		echo '<p class="description"><small>' . sprintf(__('Provided by: %s', 'extrachill-newsletter'), esc_html($integration['plugin'])) . '</small></p>';
	}
}

/**
 * Dynamic integration list ID field
 *
 * Renders list ID input field for a specific integration.
 *
 * @since 2.0.0
 * @param array $integration Integration configuration
 */
function newsletter_field_integration_list_id($integration) {
	$settings = get_newsletter_settings();
	$value = isset($settings[$integration['list_id_key']]) ? $settings[$integration['list_id_key']] : '';
	echo '<input type="text" name="extrachill_newsletter_settings[' . esc_attr($integration['list_id_key']) . ']" value="' . esc_attr($value) . '" class="regular-text" />';
	echo '<p class="description">' . sprintf(__('List ID for %s subscriptions.', 'extrachill-newsletter'), esc_html($integration['label'])) . '</p>';
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