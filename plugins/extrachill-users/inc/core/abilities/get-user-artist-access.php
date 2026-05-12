<?php
/**
 * Get User Artist Access Ability
 *
 * Returns the current user's artist-access request status.
 * Canonical implementation — REST and CLI are thin wrappers.
 *
 * @package ExtraChill\Users
 * @since   0.8.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_get_user_artist_access_ability' );

/**
 * Register the extrachill/get-user-artist-access ability.
 */
function extrachill_users_register_get_user_artist_access_ability(): void {
	wp_register_ability(
		'extrachill/get-user-artist-access',
		array(
			'label'               => __( 'Get user artist access status', 'extrachill-users' ),
			'description'         => __( 'Returns the current user\'s artist-access request status (none, pending, or approved).', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'      => array( 'type' => 'integer' ),
					'status'       => array(
						'type' => 'string',
						'enum' => array( 'none', 'pending', 'approved' ),
					),
					'type'         => array( 'type' => 'string' ),
					'request_type' => array( 'type' => 'string' ),
					'requested_at' => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static function (): bool {
				return is_user_logged_in();
			},
			'execute_callback'    => 'extrachill_users_ability_get_user_artist_access',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}

/**
 * Get the current user's artist-access request status.
 *
 * Returns whether the user has no request, a pending request, or approved
 * artist/professional access.
 *
 * @param array $input Unused — operates on the current user.
 * @return array|WP_Error Artist access status or error.
 */
function extrachill_users_ability_get_user_artist_access( array $input ): array|WP_Error {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}

	$has_artist       = get_user_meta( $user_id, 'user_is_artist', true ) === '1';
	$has_professional = get_user_meta( $user_id, 'user_is_professional', true ) === '1';
	$pending_request  = get_user_meta( $user_id, 'artist_access_request', true );

	$status = 'none';
	if ( $has_artist || $has_professional ) {
		$status = 'approved';
	} elseif ( ! empty( $pending_request ) && is_array( $pending_request ) ) {
		$status = 'pending';
	}

	$response = array(
		'user_id' => $user_id,
		'status'  => $status,
		'type'    => $has_artist ? 'artist' : ( $has_professional ? 'professional' : '' ),
	);

	if ( 'pending' === $status ) {
		$response['request_type'] = isset( $pending_request['type'] ) ? $pending_request['type'] : '';
		$response['requested_at'] = isset( $pending_request['requested_at'] ) ? (int) $pending_request['requested_at'] : 0;
	}

	return $response;
}
