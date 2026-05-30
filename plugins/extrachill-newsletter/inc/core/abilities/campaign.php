<?php
/**
 * Push Campaign Ability
 *
 * Core primitive for creating/updating Sendy campaigns from newsletter posts.
 * Replaces send_newsletter_campaign_to_sendy() and prepare_newsletter_email_content().
 *
 * @package ExtraChillNewsletter
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_push_campaign_ability' );

/**
 * Register the push-campaign ability.
 */
function extrachill_newsletter_register_push_campaign_ability() {
	wp_register_ability(
		'extrachill/push-campaign',
		array(
			'label'               => __( 'Push Campaign', 'extrachill-newsletter' ),
			'description'         => __( 'Create or update a Sendy email campaign from a newsletter post. Prepares email content and pushes to Sendy.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Newsletter post ID to create/update campaign for.', 'extrachill-newsletter' ),
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'campaign_id' => array(
						'type' => array( 'string', 'null' ),
					),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_push_campaign',
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
 * Create or update a Sendy campaign from a newsletter post.
 *
 * Prepares the email content from the post (HTML + plain text), checks for
 * existing campaign, and creates or updates via Sendy API.
 *
 * @param array $input {post_id}.
 * @return array|WP_Error Result with success, campaign_id, message.
 */
function extrachill_newsletter_ability_push_campaign( $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', 'post_id is required.' );
	}

	$post = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'post_not_found', 'Post not found.' );
	}

	if ( 'newsletter' !== $post->post_type ) {
		return new WP_Error( 'invalid_post_type', 'Post must be of type newsletter.' );
	}

	// Prepare email content via the canonical builder in email-template.php.
	$email_data = prepare_newsletter_email_content( $post );

	$config      = get_sendy_config();
	$campaign_id = get_post_meta( $post_id, '_sendy_campaign_id', true );
	$campaign_id = $campaign_id ? (string) $campaign_id : null;

	// Check if campaign exists.
	$check_url  = $config['sendy_url'] . '/api/campaigns/status.php';
	$check_data = array(
		'api_key'     => $config['api_key'],
		'campaign_id' => $campaign_id,
	);

	$check_response = wp_remote_post(
		$check_url,
		array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $check_data,
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $check_response ) ) {
		error_log( 'Sendy campaign check failed: ' . $check_response->get_error_message() );
		return array(
			'success'     => false,
			'campaign_id' => $campaign_id,
			'message'     => __( 'Failed to check campaign status', 'extrachill-newsletter' ),
		);
	}

	$exists = wp_remote_retrieve_body( $check_response );

	// Determine API endpoint.
	if ( trim( $exists ) === 'Campaign exists' ) {
		$api_endpoint = '/api/campaigns/update.php';
	} else {
		$api_endpoint = '/api/campaigns/create.php';
		$campaign_id  = null;
	}

	// Prepare campaign data.
	$campaign_data = array(
		'api_key'    => $config['api_key'],
		'from_name'  => $config['from_name'],
		'from_email' => $config['from_email'],
		'reply_to'   => $config['reply_to'],
		'subject'    => $email_data['subject'],
		'plain_text' => $email_data['plain_text'],
		'html_text'  => $email_data['html_template'],
		'brand_id'   => $config['brand_id'],
	);

	if ( $campaign_id ) {
		$campaign_data['campaign_id'] = $campaign_id;
	}

	// Send campaign to Sendy.
	$campaign_response = wp_remote_post(
		$config['sendy_url'] . $api_endpoint,
		array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $campaign_data,
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $campaign_response ) ) {
		error_log( 'Sendy campaign send/update failed: ' . $campaign_response->get_error_message() );
		return array(
			'success'     => false,
			'campaign_id' => $campaign_id,
			'message'     => __( 'Failed to send campaign to Sendy', 'extrachill-newsletter' ),
		);
	}

	$response_body = wp_remote_retrieve_body( $campaign_response );

	// Save new campaign ID if created.
	if ( ! $campaign_id && is_numeric( $response_body ) ) {
		update_post_meta( $post_id, '_sendy_campaign_id', $response_body );
		$campaign_id = (string) $response_body;

		error_log( sprintf( 'Newsletter campaign created for post %d with campaign ID: %s', $post_id, $response_body ) );
	}

	return array(
		'success'     => true,
		'campaign_id' => $campaign_id,
		'message'     => __( 'Campaign pushed to Sendy successfully', 'extrachill-newsletter' ),
	);
}
