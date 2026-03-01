<?php
/**
 * Create User Ability
 *
 * Core primitive for user account creation on community.extrachill.com.
 * All registration paths (form, Google OAuth, REST API, CLI) delegate here.
 *
 * @package ExtraChill\Users
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_create_user_ability' );

/**
 * Register the create-user ability.
 */
function extrachill_users_register_create_user_ability() {
	wp_register_ability(
		'extrachill/create-user',
		array(
			'label'               => __( 'Create User', 'extrachill-users' ),
			'description'         => __( 'Create a new user account on community.extrachill.com. Generates username, sets onboarding meta, fires registration hooks, and tracks analytics.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'email'               => array(
						'type'        => 'string',
						'description' => __( 'User email address.', 'extrachill-users' ),
					),
					'password'            => array(
						'type'        => 'string',
						'description' => __( 'User password.', 'extrachill-users' ),
					),
					'username'            => array(
						'type'        => 'string',
						'description' => __( 'Auto-generated username (temporary until onboarding).', 'extrachill-users' ),
					),
					'from_join'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether user registered via /join flow.', 'extrachill-users' ),
					),
					'registration_page'   => array(
						'type'        => 'string',
						'description' => __( 'URL where registration occurred.', 'extrachill-users' ),
					),
					'registration_source' => array(
						'type'        => 'string',
						'description' => __( 'Source label (e.g. web, extrachill-app).', 'extrachill-users' ),
					),
					'registration_method' => array(
						'type'        => 'string',
						'description' => __( 'Method label (e.g. standard, google).', 'extrachill-users' ),
					),
				),
				'required'   => array( 'email', 'password', 'username' ),
			),
			'output_schema'       => array(
				'type'        => 'integer',
				'description' => __( 'The created user ID.', 'extrachill-users' ),
			),
			'execute_callback'    => 'extrachill_users_ability_create_user',
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
 * Create a community user account.
 *
 * Switches to the community blog, creates the WordPress user, sets onboarding
 * meta, fires the registration hook, and tracks analytics.
 *
 * @param array $input {email, password, username, from_join, registration_page, registration_source, registration_method}.
 * @return int|WP_Error User ID on success, WP_Error on failure.
 */
function extrachill_users_ability_create_user( $input ) {
	$username            = isset( $input['username'] ) ? $input['username'] : '';
	$password            = isset( $input['password'] ) ? $input['password'] : '';
	$email               = isset( $input['email'] ) ? $input['email'] : '';
	$from_join           = isset( $input['from_join'] ) ? (bool) $input['from_join'] : false;
	$registration_page   = isset( $input['registration_page'] ) ? $input['registration_page'] : '';
	$registration_source = isset( $input['registration_source'] ) ? $input['registration_source'] : '';
	$registration_method = isset( $input['registration_method'] ) ? $input['registration_method'] : '';

	if ( empty( $username ) || empty( $password ) || empty( $email ) ) {
		return new WP_Error( 'missing_fields', 'Username, password, and email are required.' );
	}

	// Switch to community blog if needed.
	$current_blog_id   = get_current_blog_id();
	$switched          = false;
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;

	if ( $community_blog_id && $current_blog_id !== $community_blog_id ) {
		switch_to_blog( $community_blog_id );
		$switched = true;
	}

	$user_id = wp_create_user( $username, $password, $email );

	if ( ! is_wp_error( $user_id ) ) {
		if ( ! empty( $registration_page ) ) {
			update_user_meta( $user_id, 'registration_page', esc_url_raw( (string) $registration_page ) );
		}

		if ( ! empty( $registration_source ) ) {
			update_user_meta( $user_id, 'registration_source', sanitize_text_field( (string) $registration_source ) );
		}

		if ( ! empty( $registration_method ) ) {
			update_user_meta( $user_id, 'registration_method', sanitize_text_field( (string) $registration_method ) );
		}

		if ( function_exists( 'ec_mark_user_for_onboarding' ) ) {
			ec_mark_user_for_onboarding( $user_id, $from_join );
		} else {
			update_user_meta( $user_id, 'onboarding_completed', '0' );
			update_user_meta( $user_id, 'onboarding_from_join', $from_join ? '1' : '0' );
		}

		update_user_meta( $user_id, 'welcome_email_sent', '0' );
	}

	if ( $switched ) {
		restore_current_blog();
	}

	if ( ! is_wp_error( $user_id ) ) {
		/**
		 * Fires after a new user is registered via Extra Chill.
		 *
		 * @param int    $user_id              User ID.
		 * @param string $registration_page    URL where registration occurred.
		 * @param string $registration_source  Source label (e.g. web, extrachill-app).
		 * @param string $registration_method  Method label (e.g. standard, google).
		 */
		do_action( 'extrachill_new_user_registered', $user_id, $registration_page, $registration_source, $registration_method );

		// Track analytics via ability if available.
		$analytics_ability = wp_get_ability( 'extrachill/track-analytics-event' );
		if ( $analytics_ability ) {
			$analytics_ability->execute(
				array(
					'event_type' => 'user_registration',
					'event_data' => array(
						'user_id' => $user_id,
						'source'  => $registration_source,
						'method'  => $registration_method,
					),
					'source_url' => $registration_page,
				)
			);
		}
	}

	return $user_id;
}
