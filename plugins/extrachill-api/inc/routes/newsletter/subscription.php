<?php
/**
 * REST route: POST /wp-json/extrachill/v1/newsletter/subscribe
 *
 * Newsletter subscription endpoint for all contexts (homepage, archive, navigation, etc.).
 * Delegates to business logic in extrachill-newsletter plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_newsletter_subscription_route');

function extrachill_api_register_newsletter_subscription_route() {
	register_rest_route('extrachill/v1', '/newsletter/subscribe', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_newsletter_subscribe_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'email' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => 'is_email',
				'sanitize_callback' => 'sanitize_email',
			),
			'context' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'turnstile_response' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	));
}

function extrachill_api_newsletter_subscribe_handler($request) {
	$email = $request->get_param('email');
	$context = $request->get_param('context');
	$turnstile_response = $request->get_param('turnstile_response');

	if (!function_exists('extrachill_multisite_subscribe')) {
		return new WP_Error(
			'function_missing',
			'Newsletter subscription function not available. Please ensure extrachill-newsletter plugin is activated.',
			array('status' => 500)
		);
	}

	if ($context === 'festival_wire_tip' && $turnstile_response) {
		if (!function_exists('ec_verify_turnstile_response')) {
			return new WP_Error(
				'turnstile_missing',
				'Turnstile verification function not available.',
				array('status' => 500)
			);
		}

		$turnstile_verified = ec_verify_turnstile_response($turnstile_response);
		if (!$turnstile_verified) {
			return new WP_Error(
				'turnstile_failed',
				'Security verification failed. Please try again.',
				array('status' => 403)
			);
		}
	}

	$result = extrachill_multisite_subscribe($email, $context);

	if ($result['success']) {
		return rest_ensure_response(array(
			'success' => true,
			'message' => $result['message']
		));
	} else {
		return new WP_Error(
			'subscription_failed',
			$result['message'],
			array('status' => 400)
		);
	}
}
