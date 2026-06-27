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
 * Get a generic DMB SendyClient bound to this plugin's Sendy config.
 *
 * The campaign read/delete mechanics (Sendy DB queries) live in the generic
 * data-machine-business Sendy primitive. When DMB is active this returns a
 * configured SendyClient so this plugin can delegate the mechanics down a
 * layer; when DMB is absent it returns null and callers fall back to their
 * legacy in-plugin DB queries.
 *
 * @return \DataMachineBusiness\Sendy\SendyClient|null
 */
function extrachill_newsletter_dmb_sendy_client() {
	if ( ! class_exists( '\\DataMachineBusiness\\Sendy\\SendyClient' ) ) {
		return null;
	}

	return new \DataMachineBusiness\Sendy\SendyClient( extrachill_newsletter_sendy_dmb_config() );
}

/**
 * List Sendy campaigns.
 *
 * Delegates the campaigns-table query to the single canonical DMB Sendy client.
 * The Data Machine suite is a hard runtime dependency, so there is no in-plugin
 * DB fallback.
 *
 * @param array $input {per_page, offset, status}.
 * @return array|WP_Error Campaign list with totals.
 */
function extrachill_newsletter_ability_list_campaigns( $input ) {
	$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
	$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;
	$status   = isset( $input['status'] ) ? sanitize_text_field( $input['status'] ) : '';

	$client = extrachill_newsletter_dmb_sendy_client();
	if ( ! $client ) {
		return extrachill_newsletter_sendy_client_unavailable_error();
	}

	return $client->list_campaigns(
		array(
			'per_page' => $per_page,
			'offset'   => $offset,
			'status'   => $status,
		)
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

	$client = extrachill_newsletter_dmb_sendy_client();
	if ( ! $client ) {
		return extrachill_newsletter_sendy_client_unavailable_error();
	}

	return $client->get_campaign( $campaign_id );
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

	$client = extrachill_newsletter_dmb_sendy_client();
	if ( ! $client ) {
		return extrachill_newsletter_sendy_client_unavailable_error();
	}

	return $client->delete_campaign( $campaign_id );
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

	// Delegate the Sendy API status check to the single canonical DMB Sendy
	// client. The Data Machine suite is a hard runtime dependency, so there is
	// no in-plugin API fallback.
	$client = extrachill_newsletter_dmb_sendy_client();
	if ( ! $client ) {
		return extrachill_newsletter_sendy_client_unavailable_error();
	}

	$status = $client->subscriber_status( $list_id, $email );
	if ( is_wp_error( $status ) ) {
		return new WP_Error( 'status_check_failed', 'Failed to check subscriber status: ' . $status->get_error_message() );
	}

	return array(
		'email'  => $email,
		'status' => $status,
	);
}

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Standard error returned when the canonical DMB Sendy client is unavailable.
 *
 * The Sendy mechanics (API + read-only DB) live in the single config-injected
 * client provided by data-machine-business. The Data Machine suite is a hard
 * runtime dependency of this plugin, so when the client cannot be resolved the
 * correct behaviour is to surface the missing dependency rather than fall back
 * to a duplicate in-plugin implementation.
 *
 * @return WP_Error
 */
function extrachill_newsletter_sendy_client_unavailable_error() {
	return new WP_Error(
		'sendy_primitive_unavailable',
		__( 'Sendy campaign management requires the Data Machine Business Sendy integration to be active.', 'extrachill-newsletter' )
	);
}

/**
 * Resolve Sendy DB connection credentials from explicit configuration.
 *
 * Credentials are NEVER hardcoded or scraped from Sendy's config.php. They are
 * supplied deliberately via (in priority order):
 *
 *   1. The `extrachill_newsletter_sendy_db` filter — the recommended path.
 *      Return an array with host/user/pass/name (and optional port). Wire this
 *      from wp-config.php constants or a secrets manager so secrets stay out of
 *      the database.
 *   2. The `sendy_db` key of the `extrachill_newsletter_settings` network
 *      option, entered through the Newsletter Settings admin screen.
 *
 * @return array|WP_Error {host, user, pass, name, port} or WP_Error if unset.
 */
function extrachill_newsletter_get_sendy_db_credentials() {
	$defaults = array(
		'host' => '',
		'user' => '',
		'pass' => '',
		'name' => '',
		'port' => '',
	);

	/**
	 * Filter the Sendy database connection credentials.
	 *
	 * Preferred way to supply Sendy DB credentials without storing them in the
	 * options table. Return an array of {host, user, pass, name, port}.
	 *
	 * @param array $creds Credential array (empty by default).
	 */
	$creds = apply_filters( 'extrachill_newsletter_sendy_db', array() );

	if ( empty( $creds ) || ! is_array( $creds ) ) {
		$settings = get_site_option( 'extrachill_newsletter_settings', array() );
		$creds    = isset( $settings['sendy_db'] ) && is_array( $settings['sendy_db'] ) ? $settings['sendy_db'] : array();
	}

	$creds = wp_parse_args( $creds, $defaults );

	if ( empty( $creds['host'] ) || empty( $creds['user'] ) || empty( $creds['name'] ) ) {
		return new WP_Error(
			'sendy_db_not_configured',
			__( 'Sendy database credentials are not configured. Set them via the extrachill_newsletter_sendy_db filter or the Newsletter Settings screen.', 'extrachill-newsletter' )
		);
	}

	return $creds;
}
