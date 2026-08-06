<?php
/**
 * Campaign Management Abilities
 *
 * Campaign management delegated to Data Machine Business public abilities.
 * Provides list, get, and delete operations for Sendy campaigns.
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
}

/**
 * Execute a public Data Machine Business Sendy campaign ability.
 *
 * Data Machine Business owns provider configuration and Sendy mechanics.
 * Newsletter passes only operation input across this boundary.
 *
 * @param string $name  Public ability name.
 * @param array  $input Ability input.
 * @return array|WP_Error
 */
function extrachill_newsletter_execute_sendy_campaign_ability( $name, $input ) {
	$ability = extrachill_newsletter_get_sendy_ability( $name );
	if ( ! $ability ) {
		return new WP_Error(
			'sendy_campaign_provider_unavailable',
			__( 'Sendy campaign management requires the Data Machine Business campaign provider to be active.', 'extrachill-newsletter' )
		);
	}

	return $ability->execute( $input );
}

/**
 * List Sendy campaigns.
 *
 * Delegates through the public DMB campaign ability. When that capability is
 * absent, campaign management returns a stable provider-unavailable error.
 *
 * @param array $input {per_page, offset, status}.
 * @return array|WP_Error Campaign list with totals.
 */
function extrachill_newsletter_ability_list_campaigns( $input ) {
	$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
	$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;
	$status   = isset( $input['status'] ) ? sanitize_text_field( $input['status'] ) : '';

	return extrachill_newsletter_execute_sendy_campaign_ability(
		'datamachine/sendy-list-campaigns',
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

	return extrachill_newsletter_execute_sendy_campaign_ability(
		'datamachine/sendy-get-campaign',
		array( 'campaign_id' => $campaign_id )
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

	return extrachill_newsletter_execute_sendy_campaign_ability(
		'datamachine/sendy-delete-campaign',
		array( 'campaign_id' => $campaign_id )
	);
}
