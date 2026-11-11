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
						// Only present for administrators (manage_options).
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
	if ( 'admin' === $context && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'rest_forbidden', 'Admin access required.', array( 'status' => 403 ) );
	}

	if ( 'artist-capable' === $context ) {
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
	if ( 'artist-capable' === $context ) {
		return extrachill_users_ability_search_artist_capable( $input );
	}

	$search_columns = array( 'user_login', 'user_nicename' );
	$number         = 10;

	if ( 'admin' === $context ) {
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
		if ( 'admin' === $context ) {
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
 * Whether the current principal may see and match on member email addresses.
 *
 * Email addresses are private account data. Only site administrators
 * (manage_options) may search by or read them; every other caller —
 * including artist/professional accounts using the artist-capable picker —
 * gets identity fields only.
 *
 * @return bool
 */
function extrachill_users_search_can_view_emails(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Search for users who can create artist profiles.
 *
 * Filters to users with user_is_artist, user_is_professional, or team member status.
 * Excludes users who are already roster members of the specified artist.
 *
 * Non-admin callers match on login, nicename and display name only and
 * receive picker fields (id, display name, username, avatar, profile URL).
 * Inviting someone by email goes through the roster invite flow, which
 * accepts either a typed email address or a picked user ID and resolves the
 * address server-side. Admins additionally match on and receive email.
 *
 * @param array $input Input with 'term' and optional 'exclude_artist_id'.
 * @return array Search results.
 */
function extrachill_users_ability_search_artist_capable( array $input ): array {
	$term              = sanitize_text_field( $input['term'] );
	$exclude_artist_id = isset( $input['exclude_artist_id'] ) ? absint( $input['exclude_artist_id'] ) : 0;
	$can_view_emails   = extrachill_users_search_can_view_emails();

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

	$search_columns = $can_view_emails
		? array( 'user_login', 'user_email', 'display_name' )
		: array( 'user_login', 'user_nicename', 'display_name' );

	$users_query = new WP_User_Query(
		array(
			'search'         => '*' . esc_attr( $term ) . '*',
			'search_columns' => $search_columns,
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

		if ( in_array( (int) $user->ID, $exclude_user_ids, true ) ) {
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

		$row = array(
			'id'           => (int) $user->ID,
			'display_name' => $user->display_name,
			'username'     => $user->user_login,
			'slug'         => $user->user_nicename,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			'profile_url'  => $profile_url,
		);

		if ( $can_view_emails ) {
			$row['email'] = $user->user_email;
		}

		$users_data[] = $row;

		++$count;
	}

	return $users_data;
}
