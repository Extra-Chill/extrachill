<?php
/**
 * Settings Abilities
 *
 * Get and update newsletter settings with computed integration status
 * and validation warnings.
 *
 * @package ExtraChillNewsletter
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_settings_abilities' );

/**
 * Register settings abilities.
 */
function extrachill_newsletter_register_settings_abilities() {

	// --- Get Newsletter Settings ---
	wp_register_ability(
		'extrachill/get-newsletter-settings',
		array(
			'label'               => __( 'Get Newsletter Settings', 'extrachill-newsletter' ),
			'description'         => __( 'Retrieve all newsletter settings including computed integration status and validation warnings for missing list IDs.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => array(),
				'properties' => array(),
			),
			'output_schema'       => array(
				'type' => 'object',
			),
			'execute_callback'    => 'extrachill_newsletter_ability_get_settings',
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

	// --- Update Newsletter Settings ---
	wp_register_ability(
		'extrachill/update-newsletter-settings',
		array(
			'label'               => __( 'Update Newsletter Settings', 'extrachill-newsletter' ),
			'description'         => __( 'Update newsletter sender identity and integration list IDs. Sendy credentials are managed by Data Machine Business.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => array(),
				'properties' => array(
					'from_name'     => array(
						'type'        => 'string',
						'description' => __( 'From name for campaigns.', 'extrachill-newsletter' ),
					),
					'from_email'    => array(
						'type'        => 'string',
						'description' => __( 'From email for campaigns.', 'extrachill-newsletter' ),
					),
					'reply_to'      => array(
						'type'        => 'string',
						'description' => __( 'Reply-to email for campaigns.', 'extrachill-newsletter' ),
					),
					'brand_id'      => array(
						'type'        => 'string',
						'description' => __( 'Sendy brand ID.', 'extrachill-newsletter' ),
					),
					'list_ids'      => array(
						'type'        => 'object',
						'description' => __( 'Map of context keys to Sendy list IDs (e.g. {"homepage": "abc123"}).', 'extrachill-newsletter' ),
					),
				),
			),
			'output_schema'       => array(
				'type' => 'object',
			),
			'execute_callback'    => 'extrachill_newsletter_ability_update_settings',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Get raw newsletter settings from the database.
 *
 * Self-contained helper to avoid dependency on get_newsletter_settings()
 * which is only loaded on the newsletter site admin.
 *
 * @return array Settings array with defaults applied.
 */
function extrachill_newsletter_get_raw_settings() {
	$defaults = extrachill_newsletter_default_settings();

	// Add defaults for registered integrations (list IDs only).
	$integrations = get_newsletter_integrations();
	foreach ( $integrations as $context => $integration ) {
		$defaults[ $integration['list_id_key'] ] = '';
	}

	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	return wp_parse_args( $settings, $defaults );
}

/**
 * Get all newsletter settings with computed integration status.
 *
 * Returns settings plus an integrations array with label, list_id,
 * list_id_set flag, and a warnings array for any integrations missing
 * their list ID — the validation gap that caused the 6-week sync failure.
 *
 * @param array $input Empty input.
 * @return array Settings data with integrations and warnings.
 */
function extrachill_newsletter_ability_get_settings( $input ) {
	unset( $input );
	$settings     = extrachill_newsletter_get_raw_settings();
	$integrations = get_newsletter_integrations();
	$computed     = array();
	$warnings     = array();

	foreach ( $integrations as $context => $integration ) {
		$list_id_key = $integration['list_id_key'];
		$list_id     = isset( $settings[ $list_id_key ] ) ? $settings[ $list_id_key ] : '';
		$list_id_set = ! empty( $list_id );

		$computed[ $context ] = array(
			'label'       => $integration['label'],
			'list_id'     => $list_id,
			'list_id_set' => $list_id_set,
		);

		if ( ! $list_id_set ) {
			$warnings[] = sprintf(
				/* translators: %s: integration label */
				__( 'Integration "%s" has no Sendy list ID configured. Subscriptions via this context will fail.', 'extrachill-newsletter' ),
				$integration['label']
			);
		}
	}

	// Sendy credentials are owned by Data Machine Business; report status only.
	$connection = extrachill_newsletter_get_sendy_connection();
	if ( ! $connection['configured'] ) {
		$warnings[] = __( 'The Sendy API connection is not configured in Data Machine Business (datamachine_sendy_config). Campaign push and subscriptions will fail.', 'extrachill-newsletter' );
	}

	// Return settings with integration list IDs, computed integrations, and warnings.
	// Strip per-integration keys from the flat settings (they're now in computed).
	$core_settings = array(
		'from_name'  => $settings['from_name'],
		'from_email' => $settings['from_email'],
		'reply_to'   => $settings['reply_to'],
		'brand_id'   => $settings['brand_id'],
	);

	return array(
		'settings'     => $core_settings,
		'sendy'        => $connection,
		'integrations' => $computed,
		'warnings'     => $warnings,
	);
}

/**
 * Update newsletter settings.
 *
 * Accepts partial updates — only provided keys are changed.
 * Handles list IDs via the list_ids map (context → list_id).
 *
 * @param array $input Partial settings object.
 * @return array|WP_Error Updated settings (same shape as GET).
 */
function extrachill_newsletter_ability_update_settings( $input ) {
	$existing = get_site_option( 'extrachill_newsletter_settings', array() );

	$existing = is_array( $existing ) ? $existing : array();

	if ( isset( $input['sendy_api_key'] ) || isset( $input['sendy_url'] ) ) {
		return new WP_Error(
			'sendy_connection_not_owned',
			__( 'Sendy credentials are managed by Data Machine Business in the datamachine_sendy_config network option, not in newsletter settings.', 'extrachill-newsletter' ),
			array( 'status' => 400 )
		);
	}

	// Sender identity.
	foreach ( array( 'from_name', 'from_email', 'reply_to', 'brand_id' ) as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$existing[ $key ] = in_array( $key, array( 'from_email', 'reply_to' ), true )
				? sanitize_email( $input[ $key ] )
				: sanitize_text_field( $input[ $key ] );
		}
	}

	// List IDs via list_ids map.
	if ( isset( $input['list_ids'] ) && is_array( $input['list_ids'] ) ) {
		$integrations = get_newsletter_integrations();

		foreach ( $input['list_ids'] as $context => $list_id ) {
			if ( isset( $integrations[ $context ] ) ) {
				$existing[ $integrations[ $context ]['list_id_key'] ] = sanitize_text_field( $list_id );
			}
		}
	}

	update_site_option( 'extrachill_newsletter_settings', $existing );

	// Return same shape as GET.
	return extrachill_newsletter_ability_get_settings( array() );
}
