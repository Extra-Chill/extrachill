<?php
/**
 * Moderation Abilities
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_moderation_abilities' );

function extrachill_users_register_moderation_abilities() {
	wp_register_ability(
		'extrachill/get-user-moderation-status',
		array(
			'label'               => __( 'Get User Moderation Status', 'extrachill-users' ),
			'description'         => __( 'Retrieve the current moderation state for a user.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_user_moderation_status',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/moderate-user',
		array(
			'label'               => __( 'Moderate User', 'extrachill-users' ),
			'description'         => __( 'Apply a moderation action such as ban or suspension to a user account.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'    => array( 'type' => 'integer' ),
					'state'      => array( 'type' => 'string' ),
					'reason_key' => array( 'type' => 'string' ),
					'reason'     => array( 'type' => 'string' ),
					'note'       => array( 'type' => 'string' ),
					'source'     => array( 'type' => 'string' ),
					'acted_by'   => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id', 'state', 'reason_key' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_moderate_user',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/clear-user-moderation',
		array(
			'label'               => __( 'Clear User Moderation', 'extrachill-users' ),
			'description'         => __( 'Remove moderation state from a user account.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_clear_user_moderation',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);
}

function extrachill_users_ability_get_user_moderation_status( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	return extrachill_users_get_moderation_status( $user_id );
}

function extrachill_users_ability_moderate_user( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	return extrachill_users_apply_moderation_action(
		$user_id,
		array(
			'state'      => isset( $input['state'] ) ? (string) $input['state'] : 'banned',
			'reason_key' => isset( $input['reason_key'] ) ? (string) $input['reason_key'] : 'other',
			'reason'     => isset( $input['reason'] ) ? (string) $input['reason'] : '',
			'note'       => isset( $input['note'] ) ? (string) $input['note'] : '',
			'source'     => isset( $input['source'] ) ? (string) $input['source'] : '',
			'acted_by'   => isset( $input['acted_by'] ) ? absint( $input['acted_by'] ) : get_current_user_id(),
		)
	);
}

function extrachill_users_ability_clear_user_moderation( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	return extrachill_users_clear_moderation_action( $user_id );
}
