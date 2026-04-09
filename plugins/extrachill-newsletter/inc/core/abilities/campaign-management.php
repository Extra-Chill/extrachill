<?php
/**
 * Campaign Management Abilities
 *
 * Full Sendy campaign management via direct DB access (read) and Sendy API (write).
 * Provides list, get, and delete for campaigns, plus subscriber status checks.
 *
 * @package ExtraChillNewsletter
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_campaign_management_abilities' );

/**
 * Register campaign management abilities.
 */
function extrachill_newsletter_register_campaign_management_abilities() {

	// --- List Campaigns ---
	wp_register_ability(
		'extrachill/list-campaigns',
		array(
			'label'               => __( 'List Campaigns', 'extrachill-newsletter' ),
			'description'         => __( 'List Sendy campaigns with status, recipient counts, and dates.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'per_page' => array(
						'type'        => 'integer',
						'description' => __( 'Number of campaigns to return.', 'extrachill-newsletter' ),
					),
					'offset'   => array(
						'type'        => 'integer',
						'description' => __( 'Offset for pagination.', 'extrachill-newsletter' ),
					),
					'status'   => array(
						'type'        => 'string',
						'description' => __( 'Filter by status: sent, draft, scheduled.', 'extrachill-newsletter' ),
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'object',
			),
			'execute_callback'    => 'extrachill_newsletter_ability_list_campaigns',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	// --- Get Campaign ---
	wp_register_ability(
		'extrachill/get-campaign',
		array(
			'label'               => __( 'Get Campaign', 'extrachill-newsletter' ),
			'description'         => __( 'Get detailed information about a specific Sendy campaign.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'campaign_id' => array(
						'type'        => 'integer',
						'description' => __( 'Sendy campaign ID.', 'extrachill-newsletter' ),
					),
				),
				'required'   => array( 'campaign_id' ),
			),
			'output_schema'       => array(
				'type'  => 'object',
			),
			'execute_callback'    => 'extrachill_newsletter_ability_get_campaign',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	// --- Delete Campaign ---
	wp_register_ability(
		'extrachill/delete-campaign',
		array(
			'label'               => __( 'Delete Campaign', 'extrachill-newsletter' ),
			'description'         => __( 'Delete a Sendy campaign draft. Cannot delete sent campaigns.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'campaign_id' => array(
						'type'        => 'integer',
						'description' => __( 'Sendy campaign ID to delete.', 'extrachill-newsletter' ),
					),
				),
				'required'   => array( 'campaign_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_delete_campaign',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => true,
				),
			),
		)
	);

	// --- Subscriber Status ---
	wp_register_ability(
		'extrachill/subscriber-status',
		array(
			'label'               => __( 'Subscriber Status', 'extrachill-newsletter' ),
			'description'         => __( 'Check a subscriber\'s status in a Sendy list. Returns Subscribed, Unsubscribed, Bounced, etc.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'email'   => array(
						'type'        => 'string',
						'description' => __( 'Email address to check.', 'extrachill-newsletter' ),
					),
					'list_id' => array(
						'type'        => 'string',
						'description' => __( 'Sendy list ID (encrypted).', 'extrachill-newsletter' ),
					),
				),
				'required'   => array( 'email', 'list_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status' => array( 'type' => 'string' ),
					'email'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_subscriber_status',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * List Sendy campaigns.
 *
 * Queries the Sendy campaigns table directly (same MySQL server).
 *
 * @param array $input {per_page, offset, status}.
 * @return array|WP_Error Campaign list with totals.
 */
function extrachill_newsletter_ability_list_campaigns( $input ) {
	$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
	$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;
	$status   = isset( $input['status'] ) ? sanitize_text_field( $input['status'] ) : '';

	$sendy_db = extrachill_newsletter_get_sendy_db();
	if ( is_wp_error( $sendy_db ) ) {
		return $sendy_db;
	}

	$where = '';
	if ( 'sent' === $status ) {
		$where = 'WHERE sent != "" AND sent IS NOT NULL';
	} elseif ( 'draft' === $status ) {
		$where = 'WHERE (sent = "" OR sent IS NULL) AND (send_date = "" OR send_date IS NULL)';
	} elseif ( 'scheduled' === $status ) {
		$where = 'WHERE send_date != "" AND send_date IS NOT NULL AND send_date != 0';
	}

	$total = (int) $sendy_db->get_var( "SELECT COUNT(*) FROM campaigns {$where}" );

	$campaigns = $sendy_db->get_results(
		$sendy_db->prepare(
			"SELECT id, title, sent, to_send, recipients, send_date, lists, opens_tracking, links_tracking, campaign_stopped FROM campaigns {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		),
		ARRAY_A
	);

	$items = array();
	foreach ( $campaigns as $c ) {
		$items[] = array(
			'id'             => (int) $c['id'],
			'title'          => $c['title'],
			'status'         => extrachill_newsletter_campaign_status( $c ),
			'sent'           => $c['sent'] ? (int) $c['sent'] : null,
			'sent_date'      => $c['sent'] ? gmdate( 'Y-m-d H:i:s', (int) $c['sent'] ) : null,
			'scheduled_date' => ( ! empty( $c['send_date'] ) && '0' !== $c['send_date'] ) ? gmdate( 'Y-m-d H:i:s', (int) $c['send_date'] ) : null,
			'to_send'        => (int) $c['to_send'],
			'recipients'     => (int) $c['recipients'],
			'stopped'        => (bool) $c['campaign_stopped'],
		);
	}

	return array(
		'total'    => $total,
		'per_page' => $per_page,
		'offset'   => $offset,
		'campaigns' => $items,
	);
}

/**
 * Get a single Sendy campaign's details.
 *
 * @param array $input {campaign_id}.
 * @return array|WP_Error Campaign details.
 */
function extrachill_newsletter_ability_get_campaign( $input ) {
	$campaign_id = isset( $input['campaign_id'] ) ? absint( $input['campaign_id'] ) : 0;

	if ( ! $campaign_id ) {
		return new WP_Error( 'missing_campaign_id', 'campaign_id is required.' );
	}

	$sendy_db = extrachill_newsletter_get_sendy_db();
	if ( is_wp_error( $sendy_db ) ) {
		return $sendy_db;
	}

	$campaign = $sendy_db->get_row(
		$sendy_db->prepare( "SELECT * FROM campaigns WHERE id = %d", $campaign_id ),
		ARRAY_A
	);

	if ( ! $campaign ) {
		return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
	}

	return array(
		'id'             => (int) $campaign['id'],
		'title'          => $campaign['title'],
		'from_name'      => $campaign['from_name'],
		'from_email'     => $campaign['from_email'],
		'reply_to'       => $campaign['reply_to'],
		'status'         => extrachill_newsletter_campaign_status( $campaign ),
		'sent'           => $campaign['sent'] ? (int) $campaign['sent'] : null,
		'sent_date'      => $campaign['sent'] ? gmdate( 'Y-m-d H:i:s', (int) $campaign['sent'] ) : null,
		'scheduled_date' => ( ! empty( $campaign['send_date'] ) && '0' !== $campaign['send_date'] ) ? gmdate( 'Y-m-d H:i:s', (int) $campaign['send_date'] ) : null,
		'to_send'        => (int) $campaign['to_send'],
		'recipients'     => (int) $campaign['recipients'],
		'opens_tracking'  => (bool) $campaign['opens_tracking'],
		'links_tracking'  => (bool) $campaign['links_tracking'],
		'stopped'        => (bool) $campaign['campaign_stopped'],
		'errors'         => $campaign['errors'],
	);
}

/**
 * Delete a Sendy campaign (drafts only).
 *
 * @param array $input {campaign_id}.
 * @return array|WP_Error Result.
 */
function extrachill_newsletter_ability_delete_campaign( $input ) {
	$campaign_id = isset( $input['campaign_id'] ) ? absint( $input['campaign_id'] ) : 0;

	if ( ! $campaign_id ) {
		return new WP_Error( 'missing_campaign_id', 'campaign_id is required.' );
	}

	$sendy_db = extrachill_newsletter_get_sendy_db();
	if ( is_wp_error( $sendy_db ) ) {
		return $sendy_db;
	}

	$campaign = $sendy_db->get_row(
		$sendy_db->prepare( "SELECT id, sent, send_date FROM campaigns WHERE id = %d", $campaign_id ),
		ARRAY_A
	);

	if ( ! $campaign ) {
		return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
	}

	$status = extrachill_newsletter_campaign_status( $campaign );
	if ( 'sent' === $status ) {
		return new WP_Error(
			'cannot_delete_sent',
			'Cannot delete a sent campaign. Delete it from the Sendy admin instead.'
		);
	}

	$sendy_db->delete( 'campaigns', array( 'id' => $campaign_id ), array( '%d' ) );

	return array(
		'success' => true,
		'message' => sprintf( 'Campaign %d (%s) deleted.', $campaign_id, $status ),
	);
}

/**
 * Check a subscriber's status in a Sendy list.
 *
 * Uses the Sendy API endpoint for authoritative status.
 *
 * @param array $input {email, list_id}.
 * @return array|WP_Error Status result.
 */
function extrachill_newsletter_ability_subscriber_status( $input ) {
	$email   = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
	$list_id = isset( $input['list_id'] ) ? sanitize_text_field( $input['list_id'] ) : '';

	if ( empty( $email ) || empty( $list_id ) ) {
		return new WP_Error( 'missing_params', 'email and list_id are required.' );
	}

	$config   = get_sendy_config();
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
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'status_check_failed', 'Failed to check subscriber status: ' . $response->get_error_message() );
	}

	$body = trim( wp_remote_retrieve_body( $response ) );

	return array(
		'email'  => $email,
		'status' => $body,
	);
}

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Get a wpdb instance connected to the Sendy database.
 *
 * Sendy runs on the same server with its own database.
 * Returns a wpdb instance or WP_Error if config is missing.
 *
 * @return wpdb|WP_Error
 */
function extrachill_newsletter_get_sendy_db() {
	static $sendy_db = null;

	if ( $sendy_db !== null ) {
		return $sendy_db;
	}

	// Read Sendy config.
	$config_path = '/var/www/sendy/sendy/includes/config.php';
	if ( ! file_exists( $config_path ) ) {
		return new WP_Error( 'sendy_config_missing', 'Sendy config file not found.' );
	}

	// Extract DB credentials from Sendy config.
	$config_content = file_get_contents( $config_path );

	preg_match( "/\\\$dbHost\s*=\s*'([^']+)'/", $config_content, $host_match );
	preg_match( "/\\\$dbUser\s*=\s*'([^']+)'/", $config_content, $user_match );
	preg_match( "/\\\$dbPass\s*=\s*'([^']+)'/", $config_content, $pass_match );
	preg_match( "/\\\$dbName\s*=\s*'([^']+)'/", $config_content, $name_match );
	preg_match( "/\\\$dbPort\s*=\s*(\d+)/", $config_content, $port_match );

	if ( empty( $host_match[1] ) || empty( $user_match[1] ) || empty( $name_match[1] ) ) {
		return new WP_Error( 'sendy_config_invalid', 'Could not parse Sendy DB credentials.' );
	}

	$sendy_db = new wpdb(
		$user_match[1],
		isset( $pass_match[1] ) ? $pass_match[1] : '',
		$name_match[1],
		$host_match[1]
	);

	if ( ! empty( $port_match[1] ) ) {
		$sendy_db->db_connect( false );
		mysqli_query( $sendy_db->dbh, "SET SESSION sql_mode = ''" );
	}

	return $sendy_db;
}

/**
 * Determine campaign status from raw DB row.
 *
 * @param array $campaign Raw campaign row from Sendy DB.
 * @return string Status: sent, scheduled, draft, sending.
 */
function extrachill_newsletter_campaign_status( $campaign ) {
	if ( ! empty( $campaign['sent'] ) && '0' !== $campaign['sent'] ) {
		// If to_send > recipients, still sending.
		if ( (int) $campaign['to_send'] > (int) $campaign['recipients'] ) {
			return 'sending';
		}
		return 'sent';
	}

	if ( ! empty( $campaign['send_date'] ) && '0' !== $campaign['send_date'] ) {
		return 'scheduled';
	}

	return 'draft';
}
