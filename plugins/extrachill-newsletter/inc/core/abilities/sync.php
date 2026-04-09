<?php
/**
 * Sync Subscribers Ability
 *
 * Bulk sync users to a Sendy list. Supports explicit email lists,
 * date-based user queries, and dry-run previews.
 *
 * @package ExtraChillNewsletter
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_sync_ability' );

/**
 * Register the sync-subscribers ability.
 */
function extrachill_newsletter_register_sync_ability() {
	wp_register_ability(
		'extrachill/sync-subscribers',
		array(
			'label'               => __( 'Sync Subscribers', 'extrachill-newsletter' ),
			'description'         => __( 'Bulk sync email addresses to a Sendy list. Provide explicit emails or a date to sync users registered after that date.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'context' => array(
						'type'        => 'string',
						'description' => __( 'Integration context to determine the target list.', 'extrachill-newsletter' ),
					),
					'emails'  => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Explicit list of email addresses to sync.', 'extrachill-newsletter' ),
					),
					'since'   => array(
						'type'        => 'string',
						'description' => __( 'ISO date string. Sync users registered after this date.', 'extrachill-newsletter' ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => __( 'Preview results without actually subscribing.', 'extrachill-newsletter' ),
					),
				),
				'required'   => array( 'context' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'synced'            => array( 'type' => 'integer' ),
					'already_subscribed' => array( 'type' => 'integer' ),
					'skipped'           => array( 'type' => 'integer' ),
					'failed'            => array( 'type' => 'integer' ),
					'errors'            => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'total'             => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_sync_subscribers',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
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
 * Bulk sync email addresses to a Sendy list.
 *
 * @param array $input {context, emails, since, dry_run}.
 * @return array|WP_Error Sync results or error.
 */
function extrachill_newsletter_ability_sync_subscribers( $input ) {
	$context = isset( $input['context'] ) ? $input['context'] : '';
	$emails  = isset( $input['emails'] ) ? (array) $input['emails'] : array();
	$since   = isset( $input['since'] ) ? $input['since'] : '';
	$dry_run = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : false;

	if ( empty( $context ) ) {
		return new WP_Error( 'missing_context', 'context is required to determine the target list.' );
	}

	// Resolve list_id from context.
	$integrations = get_newsletter_integrations();

	if ( ! isset( $integrations[ $context ] ) ) {
		return new WP_Error( 'invalid_context', __( 'Newsletter integration not found', 'extrachill-newsletter' ) );
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

	// Determine email list.
	if ( empty( $emails ) && ! empty( $since ) ) {
		global $wpdb;

		$since_sql = sanitize_text_field( $since );
		$emails    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_email FROM {$wpdb->users} WHERE user_registered > %s ORDER BY user_registered ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from global.
				$since_sql
			)
		);

		if ( empty( $emails ) ) {
			return array(
				'synced'            => 0,
				'already_subscribed' => 0,
				'failed'            => 0,
				'errors'            => array(),
				'total'             => 0,
			);
		}
	}

	if ( empty( $emails ) ) {
		return new WP_Error( 'no_emails', 'Provide either emails or since parameter.' );
	}

	$subscribe_ability = wp_get_ability( 'extrachill/subscribe' );
	if ( ! $subscribe_ability ) {
		return new WP_Error( 'ability_not_available', 'Subscribe ability is not available.' );
	}

	$results = array(
		'synced'            => 0,
		'already_subscribed' => 0,
		'skipped'           => 0,
		'failed'            => 0,
		'errors'            => array(),
		'total'             => count( $emails ),
	);

	if ( $dry_run ) {
		// In dry run, just return the count without subscribing.
		$results['dry_run'] = true;
		return $results;
	}

	$config = get_sendy_config();

	foreach ( $emails as $email ) {
		// Guard: check subscription status before subscribing.
		// Skip anyone who previously unsubscribed, bounced, or complained.
		$status = extrachill_newsletter_check_subscriber_status( $email, $list_id, $config );

		if ( is_wp_error( $status ) ) {
			// Status check failed — skip to avoid re-subscribing blindly.
			$results['skipped']++;
			$results['errors'][] = $email . ': status check failed (' . $status->get_error_message() . ')';
			continue;
		}

		// Skip users who explicitly opted out or had deliverability issues.
		if ( in_array( $status, array( 'Unsubscribed', 'Bounced', 'Complained', 'Soft bounced' ), true ) ) {
			$results['skipped']++;
			continue;
		}

		// "Email does not exist in list" means they're new — safe to subscribe.
		// "Subscribed" or "Unconfirmed" — re-subscribing is fine.

		$result = $subscribe_ability->execute(
			array(
				'email'   => $email,
				'list_id' => $list_id,
				'context' => $context,
			)
		);

		if ( is_wp_error( $result ) ) {
			$results['failed']++;
			$results['errors'][] = $email . ': ' . $result->get_error_message();
			continue;
		}

		if ( ! empty( $result['success'] ) ) {
			$results['synced']++;
		} elseif ( isset( $result['status'] ) && 'already_subscribed' === $result['status'] ) {
			$results['already_subscribed']++;
		} else {
			$results['failed']++;
			$results['errors'][] = $email . ': ' . ( isset( $result['message'] ) ? $result['message'] : 'Unknown error' );
		}
	}

	return $results;
}

/**
 * Check a subscriber's status in a Sendy list via API.
 *
 * Returns the status string from Sendy: Subscribed, Unsubscribed, Bounced,
 * Complained, Unconfirmed, Soft bounced, or "Email does not exist in list".
 *
 * @param string $email  Email address.
 * @param string $list_id Encrypted Sendy list ID.
 * @param array  $config  Sendy config (api_key, sendy_url).
 * @return string|WP_Error Status string or error.
 */
function extrachill_newsletter_check_subscriber_status( $email, $list_id, $config ) {
	$response = wp_remote_post(
		$config['sendy_url'] . '/api/subscribers/subscription-status.php',
		array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'api_key' => $config['api_key'],
				'email'   => $email,
				'list_id' => $list_id,
			),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return trim( wp_remote_retrieve_body( $response ) );
}
