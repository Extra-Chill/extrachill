<?php
/**
 * Self-only entity subscription abilities.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_entity_subscription_abilities' );

/**
 * Register entity subscription abilities.
 *
 * @return void
 */
function extrachill_users_register_entity_subscription_abilities(): void {

	$identity_schema = array(
		'type'       => 'object',
		'properties' => array(
			'entity_type' => array( 'type' => 'string' ),
			'taxonomy'    => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
		),
		'required'   => array( 'entity_type', 'taxonomy', 'slug' ),
	);

	foreach (
		array(
			'entity-subscribe'           => 'Subscribe to Entity',
			'entity-unsubscribe'         => 'Unsubscribe from Entity',
			'entity-subscription-status' => 'Get Entity Subscription Status',
		) as $name => $label
	) {
		wp_register_ability(
			'extrachill/' . $name,
			array(
				'label'               => __( $label, 'extrachill-users' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				'description'         => __( 'Manage your private subscriptions to canonical entity updates.', 'extrachill-users' ),
				'category'            => 'extrachill-users',
				'input_schema'        => $identity_schema,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'extrachill_users_ability_' . str_replace( '-', '_', $name ),
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => 'entity-subscription-status' === $name,
						'idempotent' => true,
					),
				),
			)
		);
	}

	wp_register_ability(
		'extrachill/list-entity-subscriptions',
		array(
			'label'               => __( 'List Entity Subscriptions', 'extrachill-users' ),
			'description'         => __( 'List the authenticated user’s private canonical entity subscription identities.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_list_entity_subscriptions',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	// Producer recipient enumeration is never exposed through REST. Producers
	// use the private service directly, and privileged tooling may use this
	// ability only when it has a network administrator's authorization.
	wp_register_ability(
		'extrachill/resolve-entity-subscription-recipients',
		array(
			'label'               => __( 'Resolve Entity Subscription Recipients', 'extrachill-users' ),
			'description'         => __( 'Resolve private recipient IDs for an authorized entity-update producer.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array_merge(
				$identity_schema,
				array(
					'properties' => array_merge(
						$identity_schema['properties'],
						array(
							'producer' => array( 'type' => 'string' ),
							'delivery' => array( 'type' => 'string' ),
						)
					),
					'required'   => array_merge( $identity_schema['required'], array( 'producer' ) ),
				)
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_entity_subscription_recipients',
			'permission_callback' => 'extrachill_users_entity_subscription_recipient_ability_permission',
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
 * Limit recipient enumeration to network administrators.
 *
 * @return bool
 */
function extrachill_users_entity_subscription_recipient_ability_permission(): bool {
	return current_user_can( 'manage_network_options' );
}

/**
 * Execute a self-only entity subscription operation.
 *
 * @param array  $input Ability input.
 * @param string $operation subscribe, unsubscribe, or status.
 * @return array|WP_Error
 */
function extrachill_users_entity_subscription_ability( array $input, string $operation ) {

	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( 'subscribe' === $operation ) {
		return extrachill_users_subscribe_to_entity( $user_id, $input['entity_type'] ?? '', $input['taxonomy'] ?? '', $input['slug'] ?? '' );
	}
	if ( 'unsubscribe' === $operation ) {
		return extrachill_users_unsubscribe_from_entity( $user_id, $input['entity_type'] ?? '', $input['taxonomy'] ?? '', $input['slug'] ?? '' );
	}

	return extrachill_users_entity_subscription_status( $user_id, $input['entity_type'] ?? '', $input['taxonomy'] ?? '', $input['slug'] ?? '' );
}

/**
 * Subscribe the authenticated user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_entity_subscribe( array $input ) {
	return extrachill_users_entity_subscription_ability( $input, 'subscribe' );
}

/**
 * Unsubscribe the authenticated user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_entity_unsubscribe( array $input ) {
	return extrachill_users_entity_subscription_ability( $input, 'unsubscribe' );
}

/**
 * Get the authenticated user's subscription status.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_entity_subscription_status( array $input ) {
	return extrachill_users_entity_subscription_ability( $input, 'status' );
}

/**
 * List the authenticated user's canonical subscription identities.
 *
 * @param array $input Pagination input.
 * @return array|WP_Error
 */
function extrachill_users_ability_list_entity_subscriptions( array $input ) {
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	return extrachill_users_list_entity_subscriptions( $user_id, $input['page'] ?? 1, $input['per_page'] ?? 50 );
}

/**
 * Resolve recipients through the private producer authorization contract.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_entity_subscription_recipients( array $input ) {
	$recipients = extrachill_users_entity_subscription_recipients(
		$input['producer'] ?? '',
		$input['entity_type'] ?? '',
		$input['taxonomy'] ?? '',
		$input['slug'] ?? '',
		$input['delivery'] ?? 'notification'
	);

	if ( is_wp_error( $recipients ) ) {
		return $recipients;
	}

	return array( 'recipient_ids' => $recipients );
}
