<?php
/**
 * Users Search Ability
 *
 * Search users by term with context-aware results (mentions, admin, artist-capable).
 * Canonical implementation — REST and CLI are thin wrappers.
 *
 * @package ExtraChill\Users
 * @since   0.8.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_search_ability' );

/**
 * Register the extrachill/users-search ability.
 */
function extrachill_users_register_search_ability(): void {
	wp_register_ability(
		'extrachill/users-search',
		array(
			'label'               => __( 'Search users', 'extrachill-users' ),
			'description'         => __( 'Search users by term. Supports mentions, admin, and artist-capable contexts.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'term'              => array( 'type' => 'string' ),
					'context'           => array(
						'type'    => 'string',
						'enum'    => array( 'mentions', 'admin', 'artist-capable' ),
						'default' => 'mentions',
					),
					'exclude_artist_id' => array( 'type' => 'integer' ),
				),
				'required'             => array( 'term' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'display_name' => array( 'type' => 'string' ),
						'username'     => array( 'type' => 'string' ),
						'slug'         => array( 'type' => 'string' ),
						'email'        => array( 'type' => 'string' ),
						'avatar_url'   => array( 'type' => 'string' ),
						'profile_url'  => array( 'type' => 'string' ),
					),
				),
			),
			// Gate at logged-in users; execute_callback enforces context-specific
			// permissions (admin requires manage_options, artist-capable requires
			// ec_can_create_artist_profiles) once it can read the input.
			'permission_callback' => 'is_user_logged_in',
			'execute_callback'    => 'extrachill_users_ability_search',
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
 * Search users by term.
 *
 * @param array $input Input with 'term', optional 'context', optional 'exclude_artist_id'.
 * @return array|WP_Error Search results or error.
 */
function extrachill_users_ability_search( array $input ): array|WP_Error {
	$term    = isset( $input['term'] ) ? sanitize_text_field( $input['term'] ) : '';
	$context = isset( $input['context'] ) ? sanitize_text_field( $input['context'] ) : 'mentions';

	if ( empty( $term ) ) {
		return new WP_Error( 'missing_search_term', 'Search term is required.' );
	}

	// Context-specific permission checks inside execute_callback.
	if ( $context === 'admin' && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'rest_forbidden', 'Admin access required.', array( 'status' => 403 ) );
	}

	if ( $context === 'artist-capable' ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Must be logged in.', array( 'status' => 401 ) );
		}
		if ( ! function_exists( 'ec_can_create_artist_profiles' ) || ! ec_can_create_artist_profiles( get_current_user_id() ) ) {
			return new WP_Error( 'rest_forbidden', 'Cannot manage artist profiles.', array( 'status' => 403 ) );
		}
	}

	// Require minimum 2 characters for admin and artist-capable contexts.
	if ( in_array( $context, array( 'admin', 'artist-capable' ), true ) && strlen( $term ) < 2 ) {
		return array();
	}

	// Handle artist-capable context separately.
	if ( $context === 'artist-capable' ) {
		return extrachill_users_ability_search_artist_capable( $input );
	}

	$search_columns = array( 'user_login', 'user_nicename' );
	$number         = 10;

	if ( $context === 'admin' ) {
		$search_columns = array( 'user_login', 'user_email', 'display_name' );
		$number         = 20;
	}

	$users_query = new WP_User_Query(
		array(
			'search'         => '*' . esc_attr( $term ) . '*',
			'search_columns' => $search_columns,
			'number'         => $number,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
		)
	);

	$users_data = array();

	foreach ( $users_query->get_results() as $user ) {
		if ( $context === 'admin' ) {
			$users_data[] = array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'username'     => $user->user_login,
				'email'        => $user->user_email,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			);
		} else {
			$profile_url = function_exists( 'extrachill_get_user_profile_url' )
				? extrachill_get_user_profile_url( $user->ID, $user->user_email )
				: '';

			$users_data[] = array(
				'id'          => $user->ID,
				'username'    => $user->user_login,
				'slug'        => $user->user_nicename,
				'avatar_url'  => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
				'profile_url' => $profile_url,
			);
		}
	}

	return $users_data;
}

/**
 * Search for users who can create artist profiles.
 *
 * Filters to users with user_is_artist, user_is_professional, or team member status.
 * Excludes users who are already roster members of the specified artist.
 *
 * @param array $input Input with 'term' and optional 'exclude_artist_id'.
 * @return array Search results.
 */
function extrachill_users_ability_search_artist_capable( array $input ): array {
	$term              = sanitize_text_field( $input['term'] );
	$exclude_artist_id = isset( $input['exclude_artist_id'] ) ? absint( $input['exclude_artist_id'] ) : 0;

	// Get existing roster member IDs to exclude.
	$exclude_user_ids = array();
	if ( $exclude_artist_id && function_exists( 'ec_get_linked_members' ) ) {
		$linked_members = ec_get_linked_members( $exclude_artist_id );
		if ( is_array( $linked_members ) ) {
			foreach ( $linked_members as $member ) {
				$exclude_user_ids[] = (int) $member->ID;
			}
		}
	}

	$users_query = new WP_User_Query(
		array(
			'search'         => '*' . esc_attr( $term ) . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			'number'         => 50,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
		)
	);

	$users_data = array();
	$count      = 0;
	$limit      = 10;

	foreach ( $users_query->get_results() as $user ) {
		if ( $count >= $limit ) {
			break;
		}

		if ( in_array( $user->ID, $exclude_user_ids, true ) ) {
			continue;
		}

		$is_artist       = get_user_meta( $user->ID, 'user_is_artist', true ) === '1';
		$is_professional = get_user_meta( $user->ID, 'user_is_professional', true ) === '1';
		$is_team_member  = function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user->ID );

		if ( ! $is_artist && ! $is_professional && ! $is_team_member ) {
			continue;
		}

		$profile_url = function_exists( 'extrachill_get_user_profile_url' )
			? extrachill_get_user_profile_url( $user->ID, $user->user_email )
			: '';

		$users_data[] = array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			'profile_url'  => $profile_url,
		);

		++$count;
	}

	return $users_data;
}
