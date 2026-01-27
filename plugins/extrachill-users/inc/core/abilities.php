<?php
/**
 * Abilities API Integration
 *
 * Registers user management capabilities via the WordPress Abilities API.
 *
 * @package ExtraChill\Users
 * @since 0.5.16
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_users_register_category' );
add_action( 'wp_abilities_api_init', 'extrachill_users_register_abilities' );

/**
 * Register users ability category.
 */
function extrachill_users_register_category() {
	wp_register_ability_category(
		'extrachill-users',
		array(
			'label'       => __( 'Extra Chill Users', 'extrachill-users' ),
			'description' => __( 'User management capabilities', 'extrachill-users' ),
		)
	);
}

/**
 * Register user abilities.
 */
function extrachill_users_register_abilities() {
	wp_register_ability(
		'extrachill/send-welcome-email',
		array(
			'label'        => __( 'Send Welcome Email', 'extrachill-users' ),
			'description'  => __( 'Send welcome email to user with content based on onboarding status.', 'extrachill-users' ),
			'category'     => 'extrachill-users',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'    => array(
						'type'        => 'integer',
						'description' => __( 'User ID to send welcome email to.', 'extrachill-users' ),
					),
					'email_type' => array(
						'type'        => 'string',
						'enum'        => array( 'onboarding_complete', 'onboarding_incomplete' ),
						'description' => __( 'Type of welcome email. onboarding_complete uses final username; onboarding_incomplete encourages finishing setup.', 'extrachill-users' ),
					),
				),
				'required'   => array( 'user_id', 'email_type' ),
			),
			'output_schema'       => array(
				'type'        => 'boolean',
				'description' => __( 'True if email sent successfully.', 'extrachill-users' ),
			),
			'execute_callback'    => 'extrachill_users_ability_send_welcome_email',
			'permission_callback' => '__return_true',
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
 * Execute callback for send-welcome-email ability.
 *
 * @param array $input Input parameters (user_id, email_type).
 * @return bool True if email sent successfully.
 */
function extrachill_users_ability_send_welcome_email( $input ) {
	if ( empty( $input['user_id'] ) || empty( $input['email_type'] ) ) {
		return false;
	}

	$user_id    = absint( $input['user_id'] );
	$email_type = $input['email_type'];

	$already_sent = get_user_meta( $user_id, 'welcome_email_sent', true );
	if ( '1' === $already_sent ) {
		return false;
	}

	$user_data = get_userdata( $user_id );
	if ( ! $user_data ) {
		return false;
	}

	$result = false;

	if ( 'onboarding_complete' === $email_type ) {
		$result = extrachill_send_welcome_email_complete( $user_data );
	} elseif ( 'onboarding_incomplete' === $email_type ) {
		$result = extrachill_send_welcome_email_incomplete( $user_data );
	}

	if ( $result ) {
		update_user_meta( $user_id, 'welcome_email_sent', '1' );
	}

	return $result;
}
