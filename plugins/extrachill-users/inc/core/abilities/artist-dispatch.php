<?php
/**
 * Artist Dispatch access abilities.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_artist_dispatch_abilities' );

/**
 * Administrative permission shared by registration and execution checks.
 *
 * @return bool
 */
function ec_users_artist_dispatch_admin_permission() {
	return current_user_can( 'manage_network_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
}

/**
 * Register the publication-specific ability surface.
 */
function extrachill_users_register_artist_dispatch_abilities() {
	$self_permission = static function () {
		return is_user_logged_in();
	};
	$definitions     = array(
		'extrachill/get-artist-dispatch-access'           => array(
			'label'       => __( 'Get Artist Dispatch Access', 'extrachill-users' ),
			'description' => __( 'Get the current user\'s safe Artist Dispatch eligibility and access state.', 'extrachill-users' ),
			'input'       => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'callback'    => 'extrachill_users_ability_get_artist_dispatch_access',
			'permission'  => $self_permission,
			'rest'        => true,
			'readonly'    => true,
		),
		'extrachill/request-artist-dispatch-access'       => array(
			'label'       => __( 'Request Artist Dispatch Access', 'extrachill-users' ),
			'description' => __( 'Submit the current user\'s eligible Artist Dispatch access request.', 'extrachill-users' ),
			'input'       => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id'       => array( 'type' => 'integer' ),
					'description'     => array(
						'type'      => 'string',
						'minLength' => 50,
						'maxLength' => 2000,
					),
					'sample_url'      => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'acknowledgement' => array(
						'type' => 'boolean',
						'enum' => array( true ),
					),
					'terms_version'   => array(
						'type' => 'string',
						'enum' => array( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION ),
					),
				),
				'required'   => array( 'acknowledgement', 'terms_version' ),
			),
			'callback'    => 'extrachill_users_ability_request_artist_dispatch_access',
			'permission'  => $self_permission,
			'rest'        => true,
			'readonly'    => false,
		),
		'extrachill/list-artist-dispatch-access-requests' => array(
			'label'       => __( 'List Artist Dispatch Access Requests', 'extrachill-users' ),
			'description' => __( 'List Artist Dispatch applications and grants for network administration.', 'extrachill-users' ),
			'input'       => array(
				'type'       => 'object',
				'properties' => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'pending', 'approved', 'rejected', 'revoked' ),
					),
				),
			),
			'callback'    => 'extrachill_users_ability_list_artist_dispatch_access_requests',
			'permission'  => 'ec_users_artist_dispatch_admin_permission',
			'rest'        => false,
			'readonly'    => true,
		),
		'extrachill/approve-artist-dispatch-access'       => array(
			'label'       => __( 'Approve Artist Dispatch Access', 'extrachill-users' ),
			'description' => __( 'Approve a matching pending Artist Dispatch request.', 'extrachill-users' ),
			'input'       => ec_users_artist_dispatch_decision_input_schema( false ),
			'callback'    => 'extrachill_users_ability_approve_artist_dispatch_access',
			'permission'  => 'ec_users_artist_dispatch_admin_permission',
			'rest'        => false,
			'readonly'    => false,
		),
		'extrachill/reject-artist-dispatch-access'        => array(
			'label'       => __( 'Reject Artist Dispatch Access', 'extrachill-users' ),
			'description' => __( 'Reject a matching pending Artist Dispatch request.', 'extrachill-users' ),
			'input'       => ec_users_artist_dispatch_decision_input_schema( true ),
			'callback'    => 'extrachill_users_ability_reject_artist_dispatch_access',
			'permission'  => 'ec_users_artist_dispatch_admin_permission',
			'rest'        => false,
			'readonly'    => false,
		),
		'extrachill/revoke-artist-dispatch-access'        => array(
			'label'       => __( 'Revoke Artist Dispatch Access', 'extrachill-users' ),
			'description' => __( 'Revoke an approved Artist Dispatch grant.', 'extrachill-users' ),
			'input'       => ec_users_artist_dispatch_decision_input_schema( true ),
			'callback'    => 'extrachill_users_ability_revoke_artist_dispatch_access',
			'permission'  => 'ec_users_artist_dispatch_admin_permission',
			'rest'        => false,
			'readonly'    => false,
		),
	);

	foreach ( $definitions as $name => $definition ) {
		wp_register_ability(
			$name,
			array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => 'extrachill-users',
				'input_schema'        => $definition['input'],
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => $definition['callback'],
				'permission_callback' => $definition['permission'],
				'meta'                => array(
					'show_in_rest' => $definition['rest'],
					'annotations'  => array(
						'readonly'   => $definition['readonly'],
						'idempotent' => true,
					),
				),
			)
		);
	}
}

/**
 * Build the common administrative transition schema.
 *
 * @param bool $reason_required Whether reason is mandatory.
 * @return array<string,mixed>
 */
function ec_users_artist_dispatch_decision_input_schema( $reason_required ) {
	$schema = array(
		'type'       => 'object',
		'properties' => array(
			'user_id'    => array( 'type' => 'integer' ),
			'request_id' => array(
				'type'   => 'string',
				'format' => 'uuid',
			),
			'note'       => array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
			'reason'     => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 2000,
			),
		),
		'required'   => array( 'user_id', 'request_id' ),
	);
	if ( $reason_required ) {
		$schema['required'][] = 'reason';
	}
	return $schema;
}

/**
 * Resolve the authenticated self or return an authentication error.
 *
 * @return int|WP_Error
 */
function ec_users_artist_dispatch_self_user_id() {
	if ( function_exists( 'extrachill_users_resolve_self_user_id' ) ) {
		return extrachill_users_resolve_self_user_id();
	}

	$user_id = get_current_user_id();
	return $user_id ? $user_id : new WP_Error( 'not_authenticated', __( 'You must be logged in.', 'extrachill-users' ), array( 'status' => 401 ) );
}

/**
 * Get self-safe state.
 *
 * @return array|WP_Error
 */
function extrachill_users_ability_get_artist_dispatch_access() {
	$user_id = ec_users_artist_dispatch_self_user_id();
	return is_wp_error( $user_id ) ? $user_id : ec_users_get_artist_dispatch_safe_state( $user_id );
}

/**
 * Request access for the authenticated user only.
 *
 * @param array $input Request input.
 * @return array|WP_Error
 */
function extrachill_users_ability_request_artist_dispatch_access( $input ) {
	$user_id = ec_users_artist_dispatch_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$result = ec_users_request_artist_dispatch_access( $user_id, is_array( $input ) ? $input : array() );
	return is_wp_error( $result ) ? $result : ec_users_get_artist_dispatch_safe_state( $user_id );
}

/**
 * Require administrative execution authorization.
 *
 * @return true|WP_Error
 */
function ec_users_require_artist_dispatch_admin() {
	return ec_users_artist_dispatch_admin_permission()
		? true
		: new WP_Error( 'artist_dispatch_forbidden', __( 'You cannot manage Artist Dispatch access.', 'extrachill-users' ), array( 'status' => 403 ) );
}

/**
 * List protected application records.
 *
 * @param array $input List filters.
 * @return array|WP_Error
 */
function extrachill_users_ability_list_artist_dispatch_access_requests( $input ) {
	$allowed = ec_users_require_artist_dispatch_admin();
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : '';
	$users  = get_users(
		array(
			'blog_id'  => 0,
			'meta_key' => EC_USERS_ARTIST_DISPATCH_STATE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The dedicated network user-meta state is the required queue index.
			'fields'   => 'all',
		)
	);
	$items  = array();
	foreach ( $users as $user ) {
		$state = ec_users_get_artist_dispatch_state( $user->ID );
		if ( ! $state || ( $status && ( $state['status'] ?? '' ) !== $status ) ) {
			continue;
		}
		$items[] = array(
			'user_id'         => (int) $user->ID,
			'user_login'      => $user->user_login,
			'display_name'    => $user->display_name,
			'state'           => $state,
			'artist_label'    => ec_users_get_artist_dispatch_artist_label( $state['artist_id'] ?? 0 ),
			'eligibility'     => ec_users_get_artist_dispatch_eligibility( $user->ID ),
			'moderation'      => extrachill_users_get_moderation_status( (int) $user->ID ),
			'main_membership' => is_user_member_of_blog( (int) $user->ID, ec_users_get_artist_dispatch_blog_id() ),
		);
	}

	usort(
		$items,
		static function ( $a, $b ) {
			return (int) ( $b['state']['requested_at'] ?? 0 ) <=> (int) ( $a['state']['requested_at'] ?? 0 );
		}
	);
	return array( 'requests' => $items );
}

/**
 * Approve a request.
 *
 * @param array $input Decision input.
 * @return array|WP_Error
 */
function extrachill_users_ability_approve_artist_dispatch_access( $input ) {
	$allowed = ec_users_require_artist_dispatch_admin();
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	return ec_users_approve_artist_dispatch_access(
		$input['user_id'] ?? 0,
		$input['request_id'] ?? '',
		$input['note'] ?? '',
		get_current_user_id()
	);
}

/**
 * Reject a request.
 *
 * @param array $input Decision input.
 * @return array|WP_Error
 */
function extrachill_users_ability_reject_artist_dispatch_access( $input ) {
	$allowed = ec_users_require_artist_dispatch_admin();
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	return ec_users_reject_artist_dispatch_access(
		$input['user_id'] ?? 0,
		$input['request_id'] ?? '',
		$input['reason'] ?? '',
		get_current_user_id()
	);
}

/**
 * Revoke a grant.
 *
 * @param array $input Decision input.
 * @return array|WP_Error
 */
function extrachill_users_ability_revoke_artist_dispatch_access( $input ) {
	$allowed = ec_users_require_artist_dispatch_admin();
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	return ec_users_revoke_artist_dispatch_access(
		$input['user_id'] ?? 0,
		$input['request_id'] ?? '',
		$input['reason'] ?? '',
		get_current_user_id()
	);
}
