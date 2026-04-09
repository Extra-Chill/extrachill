<?php
/**
 * Subscribe Ability
 *
 * Core primitive for newsletter subscriptions via Sendy.
 * Replaces extrachill_multisite_subscribe() and extrachill_subscribe_to_list().
 *
 * @package ExtraChillNewsletter
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_subscribe_ability' );

/**
 * Register the subscribe ability.
 */
function extrachill_newsletter_register_subscribe_ability() {
	wp_register_ability(
		'extrachill/subscribe',
		array(
			'label'               => __( 'Subscribe', 'extrachill-newsletter' ),
			'description'         => __( 'Subscribe an email address to a Sendy newsletter list. Resolves list ID from integration context or accepts a direct list ID.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'email'      => array(
						'type'        => 'string',
						'description' => __( 'Email address to subscribe.', 'extrachill-newsletter' ),
					),
					'context'    => array(
						'type'        => 'string',
						'description' => __( 'Integration context (e.g. homepage, navigation, content, archive, contact).', 'extrachill-newsletter' ),
					),
					'list_id'    => array(
						'type'        => 'string',
						'description' => __( 'Direct Sendy list ID. If provided, context lookup is skipped.', 'extrachill-newsletter' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'Optional subscriber name.', 'extrachill-newsletter' ),
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => __( 'URL of the page where the subscription originated.', 'extrachill-newsletter' ),
					),
				),
				'required'   => array( 'email' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_subscribe',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Subscribe an email address to a Sendy list.
 *
 * Resolves the list ID from context if not directly provided, validates the
 * email, sends the subscription request to Sendy, fires hooks, and tracks analytics.
 *
 * @param array $input {email, context, list_id, name, source_url}.
 * @return array|WP_Error Result with success, message, status keys, or WP_Error on failure.
 */
function extrachill_newsletter_ability_subscribe( $input ) {
	$email      = isset( $input['email'] ) ? $input['email'] : '';
	$context    = isset( $input['context'] ) ? $input['context'] : '';
	$list_id    = isset( $input['list_id'] ) ? $input['list_id'] : '';
	$name       = isset( $input['name'] ) ? $input['name'] : '';
	$source_url = isset( $input['source_url'] ) ? $input['source_url'] : '';

	if ( empty( $email ) ) {
		return new WP_Error( 'missing_email', 'Email address is required.' );
	}

	// Resolve list_id from context if not directly provided.
	if ( empty( $list_id ) ) {
		if ( empty( $context ) ) {
			return new WP_Error(
				'missing_list_id',
				'Either list_id or context is required to determine the subscription list.'
			);
		}

		$integrations = get_newsletter_integrations();

		if ( ! isset( $integrations[ $context ] ) ) {
			return new WP_Error(
				'invalid_context',
				__( 'Newsletter integration not found', 'extrachill-newsletter' )
			);
		}

		$integration = $integrations[ $context ];
		$settings    = get_site_option( 'extrachill_newsletter_settings', array() );
		$list_id     = isset( $settings[ $integration['list_id_key'] ] ) ? $settings[ $integration['list_id_key'] ] : '';

		if ( empty( $list_id ) ) {
			return new WP_Error(
				'list_not_configured',
				__( 'Newsletter list not configured for this integration', 'extrachill-newsletter' )
			);
		}
	}

	// Validate email.
	if ( ! is_email( $email ) ) {
		return array(
			'success' => false,
			'message' => __( 'Invalid email address', 'extrachill-newsletter' ),
			'status'  => 'invalid',
		);
	}

	// Send subscription to Sendy.
	$config = get_sendy_config();

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
	$source        = ! empty( $context ) ? $context : 'direct';

	if ( '1' === $response_body || strpos( $response_body, 'Success' ) !== false ) {
		/** This action is documented in inc/core/sendy-api.php */
		do_action( 'extrachill_newsletter_subscribed', $source, $list_id, $source_url );

		// Track analytics (skip auto-subscriptions during registration).
		if ( 'registration' !== $source ) {
			$analytics_ability = wp_get_ability( 'extrachill/track-analytics-event' );
			if ( $analytics_ability ) {
				$analytics_ability->execute(
					array(
						'event_type' => 'newsletter_signup',
						'event_data' => array(
							'context' => $source,
							'list_id' => $list_id,
						),
						'source_url' => $source_url,
					)
				);
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Successfully subscribed to newsletter', 'extrachill-newsletter' ),
			'status'  => 'subscribed',
		);
	}

	error_log( sprintf( 'Newsletter subscription failed for %s via %s: %s', $email, $source, $response_body ) );

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
