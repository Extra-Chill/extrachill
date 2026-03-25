<?php
/**
 * Subscription Abilities
 *
 * Email consent management for followed artists.
 * Controls whether artists can see a user's email / include in exports.
 *
 * @package ExtraChill\Users
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_subscription_abilities' );

/**
 * Register subscription abilities.
 */
function extrachill_users_register_subscription_abilities() {

	// --- Get Subscriptions ---
	wp_register_ability(
		'extrachill/get-subscriptions',
		array(
			'label'               => __( 'Get Subscriptions', 'extrachill-users' ),
			'description'         => __( 'Get followed artists and email consent preferences for a user.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_subscriptions',
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

	// --- Update Subscriptions ---
	wp_register_ability(
		'extrachill/update-subscriptions',
		array(
			'label'               => __( 'Update Subscriptions', 'extrachill-users' ),
			'description'         => __( 'Update email consent preferences for followed artists.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'            => array( 'type' => 'integer' ),
					'consented_artists'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'required'   => array( 'user_id', 'consented_artists' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_update_subscriptions',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Get followed artists and email consent status.
 *
 * @param array $input Input with 'user_id'.
 * @return array|WP_Error Subscriptions data or error.
 */
function extrachill_users_ability_get_subscriptions( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	// Get followed artists.
	$followed_artists = array();
	if ( function_exists( 'bp_get_user_followed_bands' ) ) {
		$followed_posts = bp_get_user_followed_bands( $user_id, array( 'posts_per_page' => -1 ) );
		if ( ! empty( $followed_posts ) ) {
			foreach ( $followed_posts as $artist_post ) {
				$followed_artists[] = array(
					'artist_id' => $artist_post->ID,
					'name'      => get_the_title( $artist_post->ID ),
					'url'       => get_permalink( $artist_post->ID ),
				);
			}
		}
	}

	// Get consented artist IDs.
	$consented_ids = array();
	if ( ! empty( $followed_artists ) ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'artist_subscribers';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT artist_profile_id FROM {$table_name} WHERE user_id = %d AND source = 'platform_follow_consent'",
				$user_id
			),
			ARRAY_A
		);

		if ( ! empty( $results ) ) {
			$consented_ids = array_map( 'intval', wp_list_pluck( $results, 'artist_profile_id' ) );
		}
	}

	// Merge consent status into followed artists.
	foreach ( $followed_artists as &$artist ) {
		$artist['email_consent'] = in_array( $artist['artist_id'], $consented_ids, true );
	}
	unset( $artist );

	return array(
		'user_id'          => $user_id,
		'followed_artists' => $followed_artists,
	);
}

/**
 * Update email consent preferences for followed artists.
 *
 * @param array $input Input with 'user_id' and 'consented_artists' (array of artist IDs to consent to).
 * @return array|WP_Error Result or error.
 */
function extrachill_users_ability_update_subscriptions( $input ) {
	$user_id           = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$consented_artists = isset( $input['consented_artists'] ) ? array_map( 'intval', (array) $input['consented_artists'] ) : array();

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	// Get all followed artists to determine scope.
	$followed_artist_ids = array();
	if ( function_exists( 'bp_get_user_followed_bands' ) ) {
		$followed_posts = bp_get_user_followed_bands( $user_id, array( 'posts_per_page' => -1 ) );
		if ( ! empty( $followed_posts ) ) {
			$followed_artist_ids = wp_list_pluck( $followed_posts, 'ID' );
		}
	}

	if ( empty( $followed_artist_ids ) ) {
		return array(
			'success' => true,
			'message' => 'No followed artists to update.',
			'user_id' => $user_id,
		);
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'artist_subscribers';

	foreach ( $followed_artist_ids as $artist_id ) {
		$artist_id = (int) $artist_id;

		if ( in_array( $artist_id, $consented_artists, true ) ) {
			// Add consent if not exists.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND artist_profile_id = %d AND source = 'platform_follow_consent'",
					$user_id,
					$artist_id
				)
			);

			if ( ! $exists ) {
				$wpdb->insert(
					$table_name,
					array(
						'user_id'            => $user_id,
						'artist_profile_id'  => $artist_id,
						'source'             => 'platform_follow_consent',
						'subscribed_at'      => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}
		} else {
			// Remove consent.
			$wpdb->delete(
				$table_name,
				array(
					'user_id'           => $user_id,
					'artist_profile_id' => $artist_id,
					'source'            => 'platform_follow_consent',
				),
				array( '%d', '%d', '%s' )
			);
		}
	}

	return array(
		'success' => true,
		'message' => 'Subscription preferences updated.',
		'user_id' => $user_id,
	);
}
