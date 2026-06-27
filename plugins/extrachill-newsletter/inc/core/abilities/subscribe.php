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

	$source = ! empty( $context ) ? $context : 'direct';

	// Delegate the Sendy API mechanics to the generic data-machine-business
	// Sendy primitive. This plugin owns the policy (list resolution, validation,
	// hooks, analytics); the mechanism (the raw API call) lives one layer down.
	$result = extrachill_newsletter_sendy_subscribe( $list_id, $email, $name );

	if ( is_wp_error( $result ) ) {
		error_log( 'Newsletter subscription failed: ' . $result->get_error_message() );
		return array(
			'success' => false,
			'message' => __( 'Subscription service unavailable', 'extrachill-newsletter' ),
			'status'  => 'error',
		);
	}

	$status = isset( $result['status'] ) ? $result['status'] : 'failed';

	if ( ! empty( $result['success'] ) || 'subscribed' === $status ) {
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

	error_log( sprintf( 'Newsletter subscription failed for %s via %s: %s', $email, $source, isset( $result['raw'] ) ? $result['raw'] : $status ) );

	if ( 'already_subscribed' === $status ) {
		return array(
			'success' => false,
			'message' => __( 'Email already subscribed', 'extrachill-newsletter' ),
			'status'  => 'already_subscribed',
		);
	}

	if ( 'invalid' === $status ) {
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
 * Subscribe an email to a Sendy list via the generic DMB Sendy primitive.
 *
 * Delegates the raw Sendy API call to `datamachine/sendy-subscribe`, the single
 * canonical Sendy client (config-injected, EC-agnostic) provided by
 * data-machine-business. This plugin owns the policy (list resolution,
 * validation, hooks, analytics); the mechanism lives one layer down. The Data
 * Machine suite is a hard runtime dependency, so there is no in-plugin fallback
 * Sendy client — keeping one would re-introduce the duplicate this consolidation
 * removed.
 *
 * @param string $list_id Sendy list ID.
 * @param string $email   Email address.
 * @param string $name    Optional subscriber name.
 * @return array|WP_Error Normalised result {success, status, message, raw}.
 */
function extrachill_newsletter_sendy_subscribe( $list_id, $email, $name = '' ) {
	$ability = extrachill_newsletter_get_sendy_ability( 'datamachine/sendy-subscribe' );

	if ( ! $ability ) {
		return new WP_Error(
			'sendy_primitive_unavailable',
			__( 'Newsletter subscriptions require the Data Machine Business Sendy integration to be active.', 'extrachill-newsletter' )
		);
	}

	return $ability->execute(
		array(
			'config'  => extrachill_newsletter_sendy_dmb_config(),
			'list_id' => $list_id,
			'email'   => $email,
			'name'    => $name,
		)
	);
}
