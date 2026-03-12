<?php
/**
 * Ban System Abilities
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_ban_system_abilities' );

function extrachill_users_register_ban_system_abilities() {
	wp_register_ability(
		'extrachill/get-user-ban-status',
		array(
			'label'               => __( 'Get User Ban Status', 'extrachill-users' ),
			'description'         => __( 'Retrieve the current ban status for a user.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_user_ban_status',
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
		'extrachill/ban-user',
		array(
			'label'               => __( 'Ban User', 'extrachill-users' ),
			'description'         => __( 'Suspend a user account and revoke active browser sessions.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'   => array( 'type' => 'integer' ),
					'reason'    => array( 'type' => 'string' ),
					'note'      => array( 'type' => 'string' ),
					'source'    => array( 'type' => 'string' ),
					'banned_by' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_ban_user',
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
		'extrachill/unban-user',
		array(
			'label'               => __( 'Unban User', 'extrachill-users' ),
			'description'         => __( 'Remove the suspension state from a user account.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_unban_user',
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

function extrachill_users_ability_get_user_ban_status( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	return extrachill_users_get_ban_status( $user_id );
}

function extrachill_users_ability_ban_user( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	return extrachill_users_ban_user(
		$user_id,
		array(
			'reason'    => isset( $input['reason'] ) ? (string) $input['reason'] : '',
			'note'      => isset( $input['note'] ) ? (string) $input['note'] : '',
			'source'    => isset( $input['source'] ) ? (string) $input['source'] : '',
			'banned_by' => isset( $input['banned_by'] ) ? absint( $input['banned_by'] ) : get_current_user_id(),
		)
	);
}

function extrachill_users_ability_unban_user( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	return extrachill_users_unban_user( $user_id );
}
