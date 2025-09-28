<?php
/**
 * Newsletter Sendy Integration
 *
 * Handles all Sendy API interactions including campaign creation,
 * updates, subscription management, and email template generation.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sendy API Configuration
 *
 * Gets Sendy configuration from WordPress options with fallback defaults.
 * Settings are managed via the admin settings page.
 *
 * @since 1.0.0
 */
function get_sendy_config() {
	$settings = get_option('extrachill_newsletter_settings', array());

	$defaults = array(
		'sendy_api_key' => '',
		'sendy_url' => 'https://mail.extrachill.com/sendy',
		'from_name' => 'Extra Chill',
		'from_email' => 'newsletter@extrachill.com',
		'reply_to' => 'chubes@extrachill.com',
		'brand_id' => '1',
		'archive_list_id' => '',
		'homepage_list_id' => '',
		'popup_list_id' => '',
		'navigation_list_id' => '',
		'content_list_id' => '',
		'footer_list_id' => '',
		'contact_list_id' => '',
		'campaign_list_id' => '',
	);

	$settings = wp_parse_args($settings, $defaults);

	return array(
		'api_key' => $settings['sendy_api_key'],
		'sendy_url' => $settings['sendy_url'],
		'from_name' => $settings['from_name'],
		'from_email' => $settings['from_email'],
		'reply_to' => $settings['reply_to'],
		'brand_id' => $settings['brand_id'],
		'list_ids' => array(
			'main' => $settings['campaign_list_id'],
			'archive' => $settings['archive_list_id'],
			'popup' => $settings['popup_list_id'],
			'homepage' => $settings['homepage_list_id'],
			'navigation' => $settings['navigation_list_id'],
			'content' => $settings['content_list_id'],
			'footer' => $settings['footer_list_id'],
			'contact' => $settings['contact_list_id'],
		),
	);
}

/**
 * Prepare email content for Sendy
 *
 * Converts WordPress post content to Sendy-compatible HTML email
 * with responsive styling, image optimization, and template structure.
 *
 * @since 1.0.0
 * @param WP_Post $post WordPress post object
 * @return array Email data with subject, HTML template, and plain text
 */
function prepare_newsletter_email_content($post) {
	$content = apply_filters('the_content', $post->post_content);

	// Ensure images are responsive and add necessary styles
	$content = preg_replace('/<img(.+?)src="(.*?)"(.*?)>/i', '<img$1src="$2"$3 style="height: auto; max-width:100%; object-fit:contain;">', $content);

	// Replace YouTube iframe embeds with clickable thumbnails
	$content = preg_replace_callback(
		'/<figure[^>]*>\s*<div class="wp-block-embed__wrapper">\s*<iframe[^>]+src="https:\/\/www\.youtube\.com\/embed\/([a-zA-Z0-9_\-]+)[^"]*"[^>]*><\/iframe>\s*<\/div>\s*<\/figure>/s',
		function($matches) {
			$videoId = $matches[1];
			$videoUrl = "https://www.youtube.com/watch?v=$videoId";
			$thumbnailUrl = "https://img.youtube.com/vi/$videoId/maxresdefault.jpg";
			return '<a href="' . $videoUrl . '" target="_blank"><img src="' . $thumbnailUrl . '" alt="Watch our video" style="height: auto; max-width: 100%; display: block; margin: 0 auto;"></a>';
		},
		$content
	);

	// Apply responsive email styling
	$content = preg_replace('/<figure([^>]*)>/i', '<figure$1 style="text-align: center; margin: auto;">', $content);
	$content = preg_replace('/<figcaption([^>]*)>/i', '<figcaption$1 style="text-align: center;font-size: 15px;padding:5px;">', $content);
	$content = preg_replace('/<p([^>]*)>/i', '<p$1 style="font-size: 16px; line-height:1.75em;">', $content);
	$content = preg_replace('/<h2([^>]*)>/i', '<h2$1 style="text-align: center;">', $content);
	$content = preg_replace('/<(ol|ul)([^>]*)>/i', '<$1$2 style="font-size: 16px; line-height:1.75em;padding-inline-start:20px;">', $content);
	$content = preg_replace('/<li([^>]*)>/i', '<li$1 style="margin: 10px 0;">', $content);

	// Add Extra Chill logo header
	$logo = '<a href="https://extrachill.com" style="text-align: center; display: block; margin: 20px auto;border-bottom:2px solid #53940b;"><img src="https://extrachill.com/wp-content/uploads/2023/09/extra-chill-logo-no-bg-1.png" alt="Extra Chill Logo" style="padding-bottom:10px;max-width: 60px; height: auto; display: block; margin: 0 auto;"></a>';
	$content = $logo . $content;

	$subject = $post->post_title;
	$unsubscribe_link = '<p style="text-align: center; margin-top: 20px; font-size: 16px;"><unsubscribe style="color: #666666; text-decoration: none;">Unsubscribe here</unsubscribe></p>';

	// Generate complete HTML email template
	$html_template = <<<HTML
<html>
<head>
    <title>{$subject}</title>
</head>
<body style="background: #d8d8d8; font-family: Helvetica, sans-serif; padding: 0; margin: 0; width: 100%; display: flex; justify-content: center; align-items: center;">
    <div style="background: #fff; border: 1px solid #000; max-width: 600px; margin: 20px auto; padding: 0 20px; box-sizing: border-box;">
        {$content}
        <footer style="text-align: center; padding-top: 20px; font-size: 16px; line-height: 1.5em;">
            <p>Read this newsletter & all others on the web at <a href="https://extrachill.com/newsletters">extrachill.com/newsletters</a></p>
            <p>You received this email because you've connected with Extra Chill in some way over the years. Thanks for supporting independent music.</p>
            {$unsubscribe_link}
        </footer>
    </div>
</body>
</html>
HTML;

	return array(
		'subject' => $subject,
		'html_template' => $html_template,
		'plain_text' => wp_strip_all_tags($content)
	);
}

/**
 * Send or update campaign in Sendy
 *
 * Handles both creation of new campaigns and updates to existing ones.
 * Uses Sendy API to check campaign existence and create/update accordingly.
 *
 * @since 1.0.0
 * @param int $post_id WordPress post ID
 * @param array $email_data Email content data from prepare_newsletter_email_content()
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function send_newsletter_campaign_to_sendy($post_id, $email_data) {
	$config = get_sendy_config();
	$campaign_id = get_post_meta($post_id, '_sendy_campaign_id', true);

	// Check if campaign exists
	$check_url = $config['sendy_url'] . '/api/campaigns/status.php';
	$check_data = array(
		'api_key' => $config['api_key'],
		'campaign_id' => $campaign_id
	);

	$check_response = wp_remote_post($check_url, array(
		'headers' => array(
			'Content-Type' => 'application/x-www-form-urlencoded'
		),
		'body' => $check_data,
		'timeout' => 30
	));

	if (is_wp_error($check_response)) {
		error_log('Sendy campaign check failed: ' . $check_response->get_error_message());
		return new WP_Error('sendy_check_failed', __('Failed to check campaign status', 'extrachill-newsletter'));
	}

	$exists = wp_remote_retrieve_body($check_response);

	// Determine API endpoint based on campaign existence
	if (trim($exists) === 'Campaign exists') {
		$api_endpoint = '/api/campaigns/update.php';
	} else {
		$api_endpoint = '/api/campaigns/create.php';
		$campaign_id = false; // Reset campaign_id for new creation
	}

	// Prepare campaign data
	$campaign_data = array(
		'api_key' => $config['api_key'],
		'from_name' => $config['from_name'],
		'from_email' => $config['from_email'],
		'reply_to' => $config['reply_to'],
		'subject' => $email_data['subject'],
		'plain_text' => $email_data['plain_text'],
		'html_text' => $email_data['html_template'],
		'list_ids' => $config['list_ids']['main'],
		'brand_id' => $config['brand_id']
	);

	// Include campaign ID for updates
	if ($campaign_id) {
		$campaign_data['campaign_id'] = $campaign_id;
	}

	// Send campaign to Sendy
	$campaign_response = wp_remote_post($config['sendy_url'] . $api_endpoint, array(
		'headers' => array(
			'Content-Type' => 'application/x-www-form-urlencoded'
		),
		'body' => $campaign_data,
		'timeout' => 30
	));

	if (is_wp_error($campaign_response)) {
		error_log('Sendy campaign send/update failed: ' . $campaign_response->get_error_message());
		return new WP_Error('sendy_campaign_failed', __('Failed to send campaign to Sendy', 'extrachill-newsletter'));
	}

	$response_body = wp_remote_retrieve_body($campaign_response);

	// Save new campaign ID if created
	if (!$campaign_id && is_numeric($response_body)) {
		update_post_meta($post_id, '_sendy_campaign_id', $response_body);

		// Log successful campaign creation
		error_log(sprintf('Newsletter campaign created for post %d with campaign ID: %s', $post_id, $response_body));
	}

	return true;
}

/**
 * Subscribe email to Sendy list
 *
 * Handles subscription requests to specific Sendy lists.
 * Used by all newsletter subscription forms throughout the site.
 *
 * @since 1.0.0
 * @param string $email Email address to subscribe
 * @param string $list_key List identifier from config
 * @return array Response with success status and message
 */
function subscribe_email_to_sendy($email, $list_key = 'archive') {
	$config = get_sendy_config();

	// Validate email
	if (!is_email($email)) {
		return array(
			'success' => false,
			'message' => __('Invalid email address', 'extrachill-newsletter')
		);
	}

	// Get list ID for the specified key
	$list_id = isset($config['list_ids'][$list_key]) ? $config['list_ids'][$list_key] : $config['list_ids']['archive'];

	// Prepare subscription data
	$subscription_data = array(
		'email' => $email,
		'list' => $list_id,
		'boolean' => 'true',
		'api_key' => $config['api_key']
	);

	// Send subscription request
	$response = wp_remote_post($config['sendy_url'] . '/subscribe', array(
		'headers' => array(
			'Content-Type' => 'application/x-www-form-urlencoded'
		),
		'body' => $subscription_data,
		'timeout' => 30
	));

	// Handle HTTP errors
	if (is_wp_error($response)) {
		error_log('Sendy subscription failed: ' . $response->get_error_message());
		return array(
			'success' => false,
			'message' => __('Subscription service unavailable', 'extrachill-newsletter')
		);
	}

	$response_body = wp_remote_retrieve_body($response);

	// Parse Sendy response
	if ($response_body === '1' || strpos($response_body, 'Success') !== false) {
		return array(
			'success' => true,
			'message' => __('Successfully subscribed to newsletter', 'extrachill-newsletter')
		);
	} else {
		// Log specific Sendy error for debugging
		error_log(sprintf('Sendy subscription failed for %s: %s', $email, $response_body));

		// Provide user-friendly error messages
		if (strpos($response_body, 'Already subscribed') !== false) {
			return array(
				'success' => false,
				'message' => __('Email already subscribed', 'extrachill-newsletter')
			);
		} elseif (strpos($response_body, 'Invalid') !== false) {
			return array(
				'success' => false,
				'message' => __('Invalid email address', 'extrachill-newsletter')
			);
		} else {
			return array(
				'success' => false,
				'message' => __('Subscription failed, please try again', 'extrachill-newsletter')
			);
		}
	}
}

/**
 * Auto-sync newsletter to Sendy on publish
 *
 * Automatically sends newsletter to Sendy when post is published.
 * Hooked to post save to maintain workflow integration.
 *
 * @since 1.0.0
 * @param int $post_id WordPress post ID
 * @param WP_Post $post WordPress post object
 * @param bool $update Whether this is an update
 */
function auto_sync_newsletter_to_sendy($post_id, $post, $update) {
	if (!check_newsletter_conditions($post_id, $post)) {
		return;
	}

	$email_data = prepare_newsletter_email_content($post);
	$result = send_newsletter_campaign_to_sendy($post_id, $email_data);

	// Log results for debugging
	if (is_wp_error($result)) {
		error_log(sprintf('Newsletter auto-sync failed for post %d: %s', $post_id, $result->get_error_message()));
	} else {
		error_log(sprintf('Newsletter auto-sync successful for post %d', $post_id));
	}
}
// Commented out auto-sync - manual push preferred for now
// add_action('save_post', 'auto_sync_newsletter_to_sendy', 10, 3);

/**
 * Get newsletter subscription stats
 *
 * Retrieves subscription statistics from Sendy for reporting.
 * Can be extended for admin dashboard widgets.
 *
 * @since 1.0.0
 * @param string $list_key List identifier from config
 * @return array|WP_Error Subscription stats or error
 */
function get_newsletter_subscription_stats($list_key = 'archive') {
	$config = get_sendy_config();
	$list_id = isset($config['list_ids'][$list_key]) ? $config['list_ids'][$list_key] : $config['list_ids']['archive'];

	$stats_data = array(
		'api_key' => $config['api_key'],
		'list_id' => $list_id
	);

	$response = wp_remote_post($config['sendy_url'] . '/api/subscribers/active-subscriber-count.php', array(
		'headers' => array(
			'Content-Type' => 'application/x-www-form-urlencoded'
		),
		'body' => $stats_data,
		'timeout' => 30
	));

	if (is_wp_error($response)) {
		return new WP_Error('sendy_stats_failed', __('Failed to retrieve subscription stats', 'extrachill-newsletter'));
	}

	$subscriber_count = wp_remote_retrieve_body($response);

	if (is_numeric($subscriber_count)) {
		return array(
			'list_key' => $list_key,
			'list_id' => $list_id,
			'subscriber_count' => intval($subscriber_count)
		);
	} else {
		return new WP_Error('sendy_stats_invalid', __('Invalid stats response from Sendy', 'extrachill-newsletter'));
	}
}

/**
 * Get registered newsletter integrations
 *
 * Returns all newsletter form integrations registered via the filter system.
 * This enables plugins to register their own newsletter integrations.
 *
 * @since 2.0.0
 * @return array Array of registered integrations
 */
function get_newsletter_integrations() {
	return apply_filters('newsletter_form_integrations', array());
}

/**
 * Check if a newsletter integration is enabled
 *
 * Checks if a specific integration is enabled in the settings.
 *
 * @since 2.0.0
 * @param string $enable_key The settings key for the enable toggle
 * @return bool True if enabled, false otherwise
 */
function newsletter_integration_enabled($enable_key) {
	$settings = get_option('extrachill_newsletter_settings', array());
	return !empty($settings[$enable_key]);
}

/**
 * Subscribe email via registered integration
 *
 * Handles subscription requests using the integration system.
 * Validates the integration exists and is enabled before processing.
 *
 * @since 2.0.0
 * @param string $email Email address to subscribe
 * @param string $context Integration context key
 * @return array Response with success status and message
 */
function subscribe_via_integration($email, $context) {
	$integrations = get_newsletter_integrations();

	if (!isset($integrations[$context])) {
		return array(
			'success' => false,
			'message' => __('Newsletter integration not found', 'extrachill-newsletter')
		);
	}

	$integration = $integrations[$context];

	// Check if integration is enabled
	if (!newsletter_integration_enabled($integration['enable_key'])) {
		return array(
			'success' => false,
			'message' => __('Newsletter integration is disabled', 'extrachill-newsletter')
		);
	}

	// Get the list key from integration config
	$settings = get_option('extrachill_newsletter_settings', array());
	$list_id = isset($settings[$integration['list_id_key']]) ? $settings[$integration['list_id_key']] : '';

	if (empty($list_id)) {
		return array(
			'success' => false,
			'message' => __('Newsletter list not configured for this integration', 'extrachill-newsletter')
		);
	}

	// Use existing subscribe function with the list ID
	$config = get_sendy_config();

	// Validate email
	if (!is_email($email)) {
		return array(
			'success' => false,
			'message' => __('Invalid email address', 'extrachill-newsletter')
		);
	}

	// Prepare subscription data
	$subscription_data = array(
		'email' => $email,
		'list' => $list_id,
		'boolean' => 'true',
		'api_key' => $config['api_key']
	);

	// Send subscription request
	$response = wp_remote_post($config['sendy_url'] . '/subscribe', array(
		'headers' => array(
			'Content-Type' => 'application/x-www-form-urlencoded'
		),
		'body' => $subscription_data,
		'timeout' => 30
	));

	// Handle HTTP errors
	if (is_wp_error($response)) {
		error_log('Newsletter integration subscription failed: ' . $response->get_error_message());
		return array(
			'success' => false,
			'message' => __('Subscription service unavailable', 'extrachill-newsletter')
		);
	}

	$response_body = wp_remote_retrieve_body($response);

	// Parse Sendy response
	if ($response_body === '1' || strpos($response_body, 'Success') !== false) {
		return array(
			'success' => true,
			'message' => __('Successfully subscribed to newsletter', 'extrachill-newsletter')
		);
	} else {
		// Log specific Sendy error for debugging
		error_log(sprintf('Newsletter integration subscription failed for %s via %s: %s', $email, $context, $response_body));

		// Provide user-friendly error messages
		if (strpos($response_body, 'Already subscribed') !== false) {
			return array(
				'success' => false,
				'message' => __('Email already subscribed', 'extrachill-newsletter')
			);
		} elseif (strpos($response_body, 'Invalid') !== false) {
			return array(
				'success' => false,
				'message' => __('Invalid email address', 'extrachill-newsletter')
			);
		} else {
			return array(
				'success' => false,
				'message' => __('Subscription failed, please try again', 'extrachill-newsletter')
			);
		}
	}
}