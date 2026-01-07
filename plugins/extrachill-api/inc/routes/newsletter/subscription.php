<?php
/**
 * REST route: POST /wp-json/extrachill/v1/newsletter/subscribe
 *
 * Unified newsletter subscription endpoint supporting both single and bulk subscriptions.
 * - Public: Uses 'context' to look up Sendy list from integrations config
 * - Admin: Uses 'list_id' directly (requires manage_options capability)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_newsletter_subscription_route' );

function extrachill_api_register_newsletter_subscription_route() {
	register_rest_route(
		'extrachill/v1',
		'/newsletter/subscribe',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_newsletter_subscribe_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'emails'     => array(
					'required'          => true,
					'type'              => 'array',
					'validate_callback' => 'extrachill_api_validate_emails_array',
				),
				'context'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'list_id'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'source'     => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'source_url' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}

/**
 * Validate emails array parameter
 */
function extrachill_api_validate_emails_array( $emails, $request, $key ) {
	if ( ! is_array( $emails ) || empty( $emails ) ) {
		return new WP_Error( 'invalid_emails', 'emails must be a non-empty array' );
	}

	foreach ( $emails as $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['email'] ) ) {
			return new WP_Error( 'invalid_email_entry', 'Each email entry must have an email field' );
		}
		if ( ! is_email( $entry['email'] ) ) {
			return new WP_Error( 'invalid_email', 'Invalid email address: ' . $entry['email'] );
		}
	}

	return true;
}

function extrachill_api_newsletter_subscribe_handler( $request ) {
	$emails     = $request->get_param( 'emails' );
	$context    = $request->get_param( 'context' );
	$list_id    = $request->get_param( 'list_id' );
	$source     = $request->get_param( 'source' ) ?: '';
	$source_url = $request->get_param( 'source_url' ) ?: '';

	// Determine subscription mode: direct list_id (admin) or context lookup (public)
	$is_admin_mode = ! empty( $list_id );

	if ( $is_admin_mode ) {
		// Admin mode: requires super admin (multisite) or manage_options (single site)
		if ( ! is_super_admin() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'unauthorized',
				'Admin capability required for direct list subscription',
				array( 'status' => 403 )
			);
		}
	} else {
		// Public mode: requires context
		if ( empty( $context ) ) {
			return new WP_Error(
				'missing_context',
				'Either list_id (admin) or context (public) is required',
				array( 'status' => 400 )
			);
		}
	}

	// Verify newsletter functions are available
	if ( $is_admin_mode && ! function_exists( 'extrachill_subscribe_to_list' ) ) {
		return new WP_Error(
			'function_missing',
			'Newsletter subscription function not available. Please ensure extrachill-newsletter plugin is activated.',
			array( 'status' => 500 )
		);
	}

	if ( ! $is_admin_mode && ! function_exists( 'extrachill_multisite_subscribe' ) ) {
		return new WP_Error(
			'function_missing',
			'Newsletter subscription function not available. Please ensure extrachill-newsletter plugin is activated.',
			array( 'status' => 500 )
		);
	}

	// Process subscriptions
	$subscribed         = 0;
	$already_subscribed = 0;
	$failed             = 0;
	$errors             = array();

	foreach ( $emails as $entry ) {
		$email = sanitize_email( $entry['email'] );
		$name  = isset( $entry['name'] ) ? sanitize_text_field( $entry['name'] ) : '';

		if ( $is_admin_mode ) {
			$result = extrachill_subscribe_to_list( $list_id, $email, $name, $source, $source_url );
		} else {
			$result = extrachill_multisite_subscribe( $email, $context, $source_url, $name );
		}

		if ( $result['success'] ) {
			++$subscribed;
		} elseif ( isset( $result['status'] ) && $result['status'] === 'already_subscribed' ) {
			++$already_subscribed;
		} else {
			++$failed;
			$errors[] = $email . ': ' . $result['message'];
		}
	}

	$total   = count( $emails );
	$success = $failed === 0;

	return rest_ensure_response(
		array(
			'success'            => $success,
			'subscribed'         => $subscribed,
			'already_subscribed' => $already_subscribed,
			'failed'             => $failed,
			'errors'             => $errors,
			'message'            => sprintf(
				'Processed %d emails: %d subscribed, %d already subscribed, %d failed',
				$total,
				$subscribed,
				$already_subscribed,
				$failed
			),
		)
	);
}
