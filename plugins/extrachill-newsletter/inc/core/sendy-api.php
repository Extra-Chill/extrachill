<?php
/**
 * Sendy API Integration
 *
 * Network-wide subscription functions and Sendy API integration.
 * Provides centralized configuration and subscription bridge.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

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
 * Subscribe email address via Sendy API using integration context
 *
 * Validates integration context exists and list ID is configured,
 * then delegates to extrachill_subscribe_to_list().
 *
 * @param string $email      Email address to subscribe.
 * @param string $context    Form context (homepage, navigation, content, archive).
 * @param string $source_url Optional URL of the page where the form was submitted.
 * @param string $name       Optional subscriber name.
 * @return array Success status and message.
 */
function extrachill_multisite_subscribe( $email, $context, $source_url = '', $name = '' ) {
	$integrations = get_newsletter_integrations();

	if ( ! isset( $integrations[ $context ] ) ) {
		return array(
			'success' => false,
			'message' => __( 'Newsletter integration not found', 'extrachill-newsletter' ),
		);
	}

	$integration = $integrations[ $context ];
	$settings    = get_site_option( 'extrachill_newsletter_settings', array() );
	$list_id     = isset( $settings[ $integration['list_id_key'] ] ) ? $settings[ $integration['list_id_key'] ] : '';

	if ( empty( $list_id ) ) {
		return array(
			'success' => false,
			'message' => __( 'Newsletter list not configured for this integration', 'extrachill-newsletter' ),
		);
	}

	return extrachill_subscribe_to_list( $list_id, $email, $name, $context, $source_url );
}

/**
 * Subscribe email address directly to a Sendy list
 *
 * Low-level subscription function that sends directly to Sendy API.
 * Used by extrachill_multisite_subscribe() and admin bulk operations.
 *
 * @param string $list_id    Sendy list ID.
 * @param string $email      Email address to subscribe.
 * @param string $name       Optional subscriber name.
 * @param string $source     Optional source identifier for tracking (e.g., 'homepage', 'bandcamp-scraper').
 * @param string $source_url Optional URL of the page where the form was submitted.
 * @return array Success status, message, and status code for bulk processing.
 */
function extrachill_subscribe_to_list( $list_id, $email, $name = '', $source = '', $source_url = '' ) {
	$config = get_sendy_config();

	if ( ! is_email( $email ) ) {
		return array(
			'success' => false,
			'message' => __( 'Invalid email address', 'extrachill-newsletter' ),
			'status'  => 'invalid',
		);
	}

	if ( empty( $list_id ) ) {
		return array(
			'success' => false,
			'message' => __( 'List ID is required', 'extrachill-newsletter' ),
			'status'  => 'error',
		);
	}

	$subscription_data = array(
		'email'   => $email,
		'list'    => $list_id,
		'boolean' => 'true',
		'api_key' => $config['api_key'],
	);

	if ( ! empty( $name ) ) {
		$subscription_data['name'] = sanitize_text_field( $name );
	}

	$response = wp_remote_post(
		$config['sendy_url'] . '/subscribe',
		array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $subscription_data,
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( 'Newsletter subscription failed: ' . $response->get_error_message() );
		return array(
			'success' => false,
			'message' => __( 'Subscription service unavailable', 'extrachill-newsletter' ),
			'status'  => 'error',
		);
	}

	$response_body = wp_remote_retrieve_body( $response );

	if ( $response_body === '1' || strpos( $response_body, 'Success' ) !== false ) {
		/**
		 * Fires when a newsletter subscription is successful.
		 *
		 * @param string $source     Source identifier (context or custom source like 'bandcamp-scraper').
		 * @param string $list_id    Sendy list ID.
		 * @param string $source_url URL of the page where the form was submitted.
		 */
		do_action( 'extrachill_newsletter_subscribed', $source, $list_id, $source_url );

		// Track analytics (skip auto-subscriptions during registration) - delay until Abilities API is ready.
		if ( 'registration' !== $source ) {
			$analytics_data = array(
				'event_type' => 'newsletter_signup',
				'event_data' => array(
					'context' => $source,
					'list_id' => $list_id,
				),
				'source_url' => $source_url,
			);
			
			add_action( 'wp_abilities_api_init', function() use ( $analytics_data ) {
				if ( function_exists( 'wp_execute_ability' ) ) {
					wp_execute_ability( 'extrachill/track-analytics-event', $analytics_data );
				}
			}, 20 );
		}

		return array(
			'success' => true,
			'message' => __( 'Successfully subscribed to newsletter', 'extrachill-newsletter' ),
			'status'  => 'subscribed',
		);
	}

	error_log( sprintf( 'Newsletter subscription failed for %s via %s: %s', $email, $source ?: 'direct', $response_body ) );

	if ( strpos( $response_body, 'Already subscribed' ) !== false ) {
		return array(
			'success' => false,
			'message' => __( 'Email already subscribed', 'extrachill-newsletter' ),
			'status'  => 'already_subscribed',
		);
	}

	if ( strpos( $response_body, 'Invalid' ) !== false ) {
		return array(
			'success' => false,
			'message' => __( 'Invalid email address', 'extrachill-newsletter' ),
			'status'  => 'invalid',
		);
	}

	return array(
		'success' => false,
		'message' => __( 'Subscription failed, please try again', 'extrachill-newsletter' ),
		'status'  => 'failed',
	);
}

/**
 * Send or update Sendy campaign
 *
 * Checks campaign existence, creates new or updates existing campaign via Sendy API.
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
