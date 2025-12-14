<?php
/**
 * REST route: POST /wp-json/extrachill/v1/newsletter/campaign/push
 *
 * Pushes newsletter post to Sendy as email campaign.
 * Delegates to business logic in extrachill-newsletter plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_newsletter_campaign_route');

function extrachill_api_register_newsletter_campaign_route() {
	register_rest_route('extrachill/v1', '/newsletter/campaign/push', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_newsletter_campaign_push_handler',
		'permission_callback' => function() {
			return current_user_can('edit_posts');
		},
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	));
}

function extrachill_api_newsletter_campaign_push_handler($request) {
	$post_id = $request->get_param('post_id');

	$post = get_post($post_id);
	if (!$post || $post->post_type !== 'newsletter') {
		return new WP_Error(
			'invalid_post',
			'Newsletter post not found.',
			array('status' => 404)
		);
	}

	if (!function_exists('prepare_newsletter_email_content')) {
		return new WP_Error(
			'function_missing',
			'Email template function not available. Please ensure extrachill-newsletter plugin is activated.',
			array('status' => 500)
		);
	}

	if (!function_exists('send_newsletter_campaign_to_sendy')) {
		return new WP_Error(
			'function_missing',
			'Sendy API function not available. Please ensure extrachill-newsletter plugin is activated.',
			array('status' => 500)
		);
	}

	$email_data = prepare_newsletter_email_content($post);
	$result = send_newsletter_campaign_to_sendy($post_id, $email_data);

	if (is_wp_error($result)) {
		return new WP_Error(
			'sendy_failed',
			$result->get_error_message(),
			array('status' => 500)
		);
	}

	$campaign_id = get_post_meta($post_id, '_sendy_campaign_id', true);

	return rest_ensure_response(array(
		'message'     => 'Successfully pushed to Sendy!',
		'campaign_id' => $campaign_id,
	));
}
