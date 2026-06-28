<?php
/**
 * Get User By ID Ability
 *
 * Returns a single user payload by ID with context-aware field visibility.
 * Canonical implementation — REST and CLI are thin wrappers.
 *
 * @package ExtraChill\Users
 * @since   0.8.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_get_user_by_id_ability' );

/**
 * Register the extrachill/get-user-by-id ability.
 */
function extrachill_users_register_get_user_by_id_ability(): void {
	wp_register_ability(
		'extrachill/get-user-by-id',
		array(
			'label'               => __( 'Get user by ID', 'extrachill-users' ),
			'description'         => __( 'Returns a single user payload by ID. Own profile or admin sees extended fields.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array( 'type' => 'integer' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'                 => array( 'type' => 'integer' ),
					'display_name'       => array( 'type' => 'string' ),
					'username'           => array( 'type' => 'string' ),
					'slug'               => array( 'type' => 'string' ),
					'avatar_url'         => array( 'type' => 'string' ),
					'profile_url'        => array( 'type' => 'string' ),
					'is_team_member'     => array( 'type' => 'boolean' ),
					'last_active'        => array( 'type' => array( 'integer', 'null' ) ),
					'last_login'         => array( 'type' => array( 'integer', 'null' ) ),
					'email'              => array( 'type' => 'string' ),
					'is_lifetime_member' => array( 'type' => 'boolean' ),
					'is_artist'          => array( 'type' => 'boolean' ),
					'is_professional'    => array( 'type' => 'boolean' ),
					'can_create_artists' => array( 'type' => 'boolean' ),
					'artist_count'       => array( 'type' => 'integer' ),
					'registered'         => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function (): bool {
				return is_user_logged_in();
			},
			'execute_callback'    => 'extrachill_users_ability_get_user_by_id',
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
 * Get a single user payload by ID.
 *
 * Returns public fields to all logged-in callers. Extended fields (email,
 * membership, artist status) are included only for the user's own profile
 * or admin callers.
 *
 * @param array $input Input with 'id'.
 * @return array|WP_Error User data or error.
 */
function extrachill_users_ability_get_user_by_id( array $input ): array|WP_Error {
	$user_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
	}

	$current_user = get_current_user_id();
	$is_own       = $current_user === $user_id;
	$is_admin     = current_user_can( 'manage_options' );

	// Avatar URL.
	$avatar_url = get_avatar_url( $user_id, array( 'size' => 96 ) );

	// Profile URL.
	$profile_url = function_exists( 'extrachill_get_user_profile_url' )
		? extrachill_get_user_profile_url( $user_id, $user->user_email )
		: get_author_posts_url( $user_id );

	// Team member status.
	$is_team_member = function_exists( 'ec_is_team_member' )
		? ec_is_team_member( $user_id )
		: false;

	// Last active timestamp.
	$last_active = get_user_meta( $user_id, 'last_active', true );

	// Public fields (available to all logged-in users).
	$response = array(
		'id'             => $user_id,
		'display_name'   => $user->display_name,
		'username'       => $user->user_login,
		'slug'           => $user->user_nicename,
		'avatar_url'     => $avatar_url,
		'profile_url'    => $profile_url,
		'is_team_member' => $is_team_member,
		'last_active'    => $last_active ? (int) $last_active : null,
	);

	// Extended fields (own profile or admin only).
	if ( $is_own || $is_admin ) {
		$membership_data    = get_user_meta( $user_id, 'extrachill_lifetime_membership', true );
		$is_lifetime_member = ! empty( $membership_data );

		$is_artist       = get_user_meta( $user_id, 'user_is_artist', true ) === '1';
		$is_professional = get_user_meta( $user_id, 'user_is_professional', true ) === '1';

		$can_create_artists = function_exists( 'ec_can_create_artist_profiles' )
			? ec_can_create_artist_profiles( $user_id )
			: false;

		$artist_count = 0;
		if ( function_exists( 'ec_get_artists_for_user' ) ) {
			$artists      = ec_get_artists_for_user( $user_id );
			$artist_count = count( $artists );
		}

		$response['email']              = $user->user_email;
		$response['is_lifetime_member'] = $is_lifetime_member;
		$response['is_artist']          = $is_artist;
		$response['is_professional']    = $is_professional;
		$response['can_create_artists'] = $can_create_artists;
		$response['artist_count']       = $artist_count;
		$response['registered']         = mysql2date( 'c', $user->user_registered );

		// Last login (auth event) is more sensitive than last_active (page
		// activity), so it is own-profile/admin-only — unlike last_active,
		// which is a public field. See inc/core/last-login.php for the
		// last_login vs last_active distinction.
		$last_login             = get_user_meta( $user_id, 'last_login', true );
		$response['last_login'] = $last_login ? (int) $last_login : null;
	}

	return $response;
}
