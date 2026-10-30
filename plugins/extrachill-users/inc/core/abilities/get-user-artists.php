<?php
/**
 * Get User Artists Ability
 *
 * Returns the artist memberships (managed artists) for a user.
 * Canonical implementation — REST and CLI are thin wrappers.
 *
 * @package ExtraChill\Users
 * @since   0.8.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_get_user_artists_ability' );

/**
 * Register the extrachill/get-user-artists ability.
 */
function extrachill_users_register_get_user_artists_ability(): void {
	wp_register_ability(
		'extrachill/get-user-artists',
		array(
			'label'               => __( 'Get user artists', 'extrachill-users' ),
			'description'         => __( 'Returns the artist memberships for a user.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'             => array( 'user_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'                => array( 'type' => 'integer' ),
						'name'              => array( 'type' => 'string' ),
						'slug'              => array( 'type' => 'string' ),
						'profile_image_url' => array( 'type' => array( 'string', 'null' ) ),
					),
				),
			),
			'permission_callback' => static function (): bool {
				return is_user_logged_in();
			},
			'execute_callback'    => 'extrachill_users_ability_get_user_artists',
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
 * Get the artist memberships for a user.
 *
 * Only the user themselves or an admin can view artist memberships.
 * Delegates to ec_get_artists_for_user() for the artist ID list,
 * then resolves post data from the artist blog.
 *
 * @param array $input Input with 'user_id'.
 * @return array|WP_Error Array of artist objects or error.
 */
function extrachill_users_ability_get_user_artists( array $input ): array|WP_Error {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
	}

	// Only own profile or admin.
	$current_user = get_current_user_id();
	if ( $current_user !== $user_id && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'rest_forbidden', 'Cannot view other users\' artists.', array( 'status' => 403 ) );
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return new WP_Error( 'dependency_missing', 'Users plugin not active.', array( 'status' => 500 ) );
	}

	$artist_ids = ec_get_artists_for_user( $user_id );
	$artists    = array();

	if ( ! empty( $artist_ids ) ) {
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
		if ( $artist_blog_id ) {
			switch_to_blog( $artist_blog_id );
		}
		try {
			foreach ( $artist_ids as $artist_id ) {
				$artist = get_post( $artist_id );
				if ( ! $artist instanceof WP_Post ) {
					continue;
				}

				$profile_image_id  = get_post_thumbnail_id( $artist_id );
				$profile_image_url = $profile_image_id
					? wp_get_attachment_image_url( $profile_image_id, 'thumbnail' )
					: null;

				$artists[] = array(
					'id'                => (int) $artist_id,
					'name'              => $artist->post_title,
					'slug'              => $artist->post_name,
					'profile_image_url' => $profile_image_url,
				);
			}
		} finally {
			if ( $artist_blog_id ) {
				restore_current_blog();
			}
		}
	}

	return $artists;
}
