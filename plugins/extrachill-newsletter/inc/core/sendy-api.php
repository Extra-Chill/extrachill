<?php
/**
 * Newsletter Subscription Functions
 *
 * Centralized subscription functions for newsletter integration system.
 * Network-wide availability for cross-site newsletter subscriptions.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get Sendy API configuration from network-wide site options
 *
 * @since 1.0.0
 * @return array Sendy configuration array
 */
function get_sendy_config() {
	$settings = get_site_option( 'extrachill_newsletter_settings', array() );

	$defaults = array(
		'sendy_api_key' => '',
		'sendy_url' => 'https://mail.extrachill.com/sendy',
		'from_name' => 'Extra Chill',
		'from_email' => 'newsletter@extrachill.com',
		'reply_to' => 'chubes@extrachill.com',
		'brand_id' => '1',
	);

	$settings = wp_parse_args( $settings, $defaults );

	return array(
		'api_key' => $settings['sendy_api_key'],
		'sendy_url' => $settings['sendy_url'],
		'from_name' => $settings['from_name'],
		'from_email' => $settings['from_email'],
		'reply_to' => $settings['reply_to'],
		'brand_id' => $settings['brand_id'],
	);
}

/**
 * Get registered newsletter integrations via filter system
 *
 * @since 1.0.0
 * @return array Registered integrations
 */
function get_newsletter_integrations() {
	return apply_filters( 'newsletter_form_integrations', array() );
}

/**
 * Check if newsletter integration is enabled in network settings
 *
 * @since 1.0.0
 * @param string $enable_key Settings key for enable toggle
 * @return bool True if enabled
 */
function newsletter_integration_enabled( $enable_key ) {
	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	return ! empty( $settings[ $enable_key ] );
}

/**
 * Centralized newsletter subscription bridge
 *
 * Validates integration config, retrieves list ID from settings,
 * and makes Sendy API subscription request.
 *
 * @since 1.0.0
 * @param string $email Email address to subscribe
 * @param string $context Integration context (e.g., 'navigation', 'homepage')
 * @return array Response with 'success' boolean and 'message' string
 */
function extrachill_multisite_subscribe( $email, $context ) {
	$integrations = get_newsletter_integrations();

	if ( ! isset( $integrations[ $context ] ) ) {
		return array(
			'success' => false,
			'message' => __( 'Newsletter integration not found', 'extrachill-newsletter' ),
		);
	}

	$integration = $integrations[ $context ];

	if ( ! newsletter_integration_enabled( $integration['enable_key'] ) ) {
		return array(
			'success' => false,
			'message' => __( 'Newsletter integration is disabled', 'extrachill-newsletter' ),
		);
	}

	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	$list_id = isset( $settings[ $integration['list_id_key'] ] ) ? $settings[ $integration['list_id_key'] ] : '';

	if ( empty( $list_id ) ) {
		return array(
			'success' => false,
			'message' => __( 'Newsletter list not configured for this integration', 'extrachill-newsletter' ),
		);
	}

	$config = get_sendy_config();

	if ( ! is_email( $email ) ) {
		return array(
			'success' => false,
			'message' => __( 'Invalid email address', 'extrachill-newsletter' ),
		);
	}

	$subscription_data = array(
		'email' => $email,
		'list' => $list_id,
		'boolean' => 'true',
		'api_key' => $config['api_key'],
	);

	$response = wp_remote_post(
		$config['sendy_url'] . '/subscribe',
		array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body' => $subscription_data,
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( 'Newsletter integration subscription failed: ' . $response->get_error_message() );
		return array(
			'success' => false,
			'message' => __( 'Subscription service unavailable', 'extrachill-newsletter' ),
		);
	}

	$response_body = wp_remote_retrieve_body( $response );

	if ( $response_body === '1' || strpos( $response_body, 'Success' ) !== false ) {
		return array(
			'success' => true,
			'message' => __( 'Successfully subscribed to newsletter', 'extrachill-newsletter' ),
		);
	} else {
		error_log( sprintf( 'Newsletter integration subscription failed for %s via %s: %s', $email, $context, $response_body ) );

		if ( strpos( $response_body, 'Already subscribed' ) !== false ) {
			return array(
				'success' => false,
				'message' => __( 'Email already subscribed', 'extrachill-newsletter' ),
			);
		} elseif ( strpos( $response_body, 'Invalid' ) !== false ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid email address', 'extrachill-newsletter' ),
			);
		} else {
			return array(
				'success' => false,
				'message' => __( 'Subscription failed, please try again', 'extrachill-newsletter' ),
			);
		}
	}
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
