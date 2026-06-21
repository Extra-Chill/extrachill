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

	// Delegate the Sendy campaign create/update mechanics to the generic DMB
	// primitive. This plugin owns the policy: building the email content from
	// the post, the sender identity/brand from settings, and tracking the
	// resulting campaign ID in post meta. The raw API transport lives one layer
	// down in data-machine-business.
	$result = extrachill_newsletter_sendy_push_campaign(
		array(
			'campaign_id' => $campaign_id,
			'from_name'   => $config['from_name'],
			'from_email'  => $config['from_email'],
			'reply_to'    => $config['reply_to'],
			'subject'     => $email_data['subject'],
			'plain_text'  => $email_data['plain_text'],
			'html_text'   => $email_data['html_template'],
			'brand_id'    => $config['brand_id'],
		)
	);

	if ( is_wp_error( $result ) ) {
		error_log( 'Sendy campaign send/update failed: ' . $result->get_error_message() );
		return array(
			'success'     => false,
			'campaign_id' => $campaign_id,
			'message'     => __( 'Failed to send campaign to Sendy', 'extrachill-newsletter' ),
		);
	}

	if ( empty( $result['success'] ) ) {
		return array(
			'success'     => false,
			'campaign_id' => isset( $result['campaign_id'] ) ? $result['campaign_id'] : $campaign_id,
			'message'     => ! empty( $result['message'] ) ? $result['message'] : __( 'Failed to send campaign to Sendy', 'extrachill-newsletter' ),
		);
	}

	$new_campaign_id = isset( $result['campaign_id'] ) ? $result['campaign_id'] : $campaign_id;

	// Persist a newly-created campaign ID in post meta.
	if ( ! empty( $result['created'] ) && $new_campaign_id ) {
		update_post_meta( $post_id, '_sendy_campaign_id', $new_campaign_id );
		error_log( sprintf( 'Newsletter campaign created for post %d with campaign ID: %s', $post_id, $new_campaign_id ) );
	}

	return array(
		'success'     => true,
		'campaign_id' => $new_campaign_id,
		'message'     => __( 'Campaign pushed to Sendy successfully', 'extrachill-newsletter' ),
	);
}

/**
 * Push a Sendy campaign via the generic DMB Sendy primitive.
 *
 * Delegates the campaign create/update API mechanics to
 * `datamachine/sendy-push-campaign` when data-machine-business is active. Falls
 * back to a direct API call (legacy mechanics) when the primitive is
 * unavailable so campaign pushing keeps working regardless of DMB activation.
 *
 * @param array $campaign Campaign content + sender identity (see push_campaign).
 * @return array|WP_Error Result {success, campaign_id, created, message, raw}.
 */
function extrachill_newsletter_sendy_push_campaign( $campaign ) {
	$ability = extrachill_newsletter_get_sendy_ability( 'datamachine/sendy-push-campaign' );

	if ( $ability ) {
		return $ability->execute(
			array_merge(
				array( 'config' => extrachill_newsletter_sendy_dmb_config() ),
				$campaign
			)
		);
	}

	// Legacy fallback: direct Sendy API calls.
	$config      = get_sendy_config();
	$campaign_id = isset( $campaign['campaign_id'] ) && '' !== (string) $campaign['campaign_id'] ? (string) $campaign['campaign_id'] : null;

	$api_endpoint = '/api/campaigns/create.php';
	if ( $campaign_id ) {
		$check_response = wp_remote_post(
			$config['sendy_url'] . '/api/campaigns/status.php',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'api_key'     => $config['api_key'],
					'campaign_id' => $campaign_id,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $check_response ) ) {
			return $check_response;
		}

		if ( 'Campaign exists' === trim( wp_remote_retrieve_body( $check_response ) ) ) {
			$api_endpoint = '/api/campaigns/update.php';
		} else {
			$campaign_id = null;
		}
	}

	$body = array(
		'api_key'    => $config['api_key'],
		'from_name'  => isset( $campaign['from_name'] ) ? $campaign['from_name'] : '',
		'from_email' => isset( $campaign['from_email'] ) ? $campaign['from_email'] : '',
		'reply_to'   => isset( $campaign['reply_to'] ) ? $campaign['reply_to'] : '',
		'subject'    => isset( $campaign['subject'] ) ? $campaign['subject'] : '',
		'plain_text' => isset( $campaign['plain_text'] ) ? $campaign['plain_text'] : '',
		'html_text'  => isset( $campaign['html_text'] ) ? $campaign['html_text'] : '',
		'brand_id'   => isset( $campaign['brand_id'] ) ? $campaign['brand_id'] : '',
	);

	if ( $campaign_id ) {
		$body['campaign_id'] = $campaign_id;
	}

	$response = wp_remote_post(
		$config['sendy_url'] . $api_endpoint,
		array(
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => $body,
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$raw     = wp_remote_retrieve_body( $response );
	$created = false;

	if ( ! $campaign_id && is_numeric( trim( $raw ) ) ) {
		$campaign_id = (string) trim( $raw );
		$created     = true;
	}

	$success = $created || ( '/api/campaigns/update.php' === $api_endpoint && false === stripos( $raw, 'error' ) );

	return array(
		'success'     => $success,
		'campaign_id' => $campaign_id,
		'created'     => $created,
		'message'     => $success ? '' : trim( $raw ),
		'raw'         => $raw,
	);
}
