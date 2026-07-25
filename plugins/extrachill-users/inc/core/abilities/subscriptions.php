<?php
/**
 * Subscription Abilities
 *
 * Artist email-sharing consent management.
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
			'description'         => __( 'Get the authenticated user’s artist email-sharing preferences.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_subscriptions',
			// Self-only: returns the authenticated user's artist email consent records.
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
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
			'description'         => __( 'Update which artists may access the authenticated user’s email.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'consented_artists' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'required'   => array( 'consented_artists' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_update_subscriptions',
			// Self-only: updates the authenticated user's consent preferences.
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Get artist email-sharing consent status.
 *
 * Self-only: resolves the authenticated user; takes no input.
 *
 * @return array|WP_Error Subscriptions data or error.
 */
function extrachill_users_ability_get_subscriptions() {
	// Self-only: always operate on the authenticated user, ignoring any client-supplied user_id.
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	// Resolve artist email consent directly from artist_subscribers rows.
	// The artist_subscribers table lives on the artist site (blog 4), so we
	// switch_to_blog to get the correct table prefix. Each consent row is an
	// artist the user has subscribed to with email consent granted.
	$artist_email_consents = array();
	$artist_blog_id        = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	$artist_blog_id        = (int) apply_filters( 'extrachill_users_artist_consent_blog_id', $artist_blog_id );

	if ( $artist_blog_id ) {
		switch_to_blog( $artist_blog_id );

		global $wpdb;
		$table_name = $wpdb->prefix . 'artist_subscribers';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT artist_profile_id FROM {$table_name} WHERE user_id = %d AND source = 'platform_follow_consent'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix.
				$user_id
			),
			ARRAY_A
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$artist_id               = (int) $row['artist_profile_id'];
				$artist_email_consents[] = array(
					'artist_id'     => $artist_id,
					'name'          => get_the_title( $artist_id ),
					'url'           => get_permalink( $artist_id ),
					'email_consent' => true,
				);
			}
		}

		restore_current_blog();
	}

	return array(
		'user_id'               => $user_id,
		'artist_email_consents' => $artist_email_consents,
		// Deprecated compatibility field for shipped REST and typed-client consumers.
		'followed_artists'      => $artist_email_consents,
	);
}

/**
 * Update which artists may access the authenticated user’s email.
 *
 * @param array $input Input with 'consented_artists' (array of artist IDs to consent to).
 * @return array|WP_Error Result or error.
 */
function extrachill_users_ability_update_subscriptions( $input ) {
	// Self-only: always operate on the authenticated user, ignoring any client-supplied user_id.
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$consented_artists = isset( $input['consented_artists'] ) ? array_map( 'intval', (array) $input['consented_artists'] ) : array();
	$consented_artists = array_values(
		array_unique(
			array_filter(
				$consented_artists,
				static function ( $artist_id ) {
					return $artist_id > 0;
				}
			)
		)
	);

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	$subscriber_email = sanitize_email( $user->user_email );
	if ( empty( $subscriber_email ) ) {
		return new WP_Error(
			'user_email_missing',
			'An account email is required to share email access with artists.',
			array( 'status' => 400 )
		);
	}

	// The artist_subscribers table lives on the artist site (blog 4).
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	$artist_blog_id = (int) apply_filters( 'extrachill_users_artist_consent_blog_id', $artist_blog_id );

	if ( ! $artist_blog_id ) {
		return new WP_Error( 'service_unavailable', 'Artist platform not configured.' );
	}

	switch_to_blog( $artist_blog_id );

	try {
		global $wpdb;
		$table_name = $wpdb->prefix . 'artist_subscribers';

		// Existing consent rows for this user define the current scope. We add
		// consent for any newly-supplied artist and remove consent rows the user
		// no longer includes in the consented set.
		$existing_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT artist_profile_id FROM {$table_name} WHERE user_id = %d AND source = 'platform_follow_consent'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix.
				$user_id
			)
		);
		if ( null === $existing_ids && '' !== $wpdb->last_error ) {
			return new WP_Error(
				'artist_email_consent_read_failed',
				'Artist email preferences could not be loaded.',
				array( 'status' => 500 )
			);
		}
		$existing_ids = array_map( 'intval', (array) $existing_ids );

		// Add consent for newly-supplied artists with the identity required by
		// the artist-platform export contract and unique email/artist index.
		foreach ( $consented_artists as $artist_id ) {
			if ( in_array( $artist_id, $existing_ids, true ) ) {
				continue;
			}

			$inserted = $wpdb->insert(
				$table_name,
				array(
					'user_id'           => $user_id,
					'artist_profile_id' => $artist_id,
					'subscriber_email'  => $subscriber_email,
					'username'          => $user->user_login,
					'source'            => 'platform_follow_consent',
					'subscribed_at'     => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational context for a failed consent write.
				error_log( 'Artist email consent insert failed: ' . $wpdb->last_error );
				return new WP_Error(
					'artist_email_consent_insert_failed',
					'Artist email preferences could not be updated.',
					array( 'status' => 500 )
				);
			}
		}

		// Remove consent rows no longer in the consented set.
		foreach ( $existing_ids as $artist_id ) {
			if ( in_array( $artist_id, $consented_artists, true ) ) {
				continue;
			}

			$deleted = $wpdb->delete(
				$table_name,
				array(
					'user_id'           => $user_id,
					'artist_profile_id' => $artist_id,
					'source'            => 'platform_follow_consent',
				),
				array( '%d', '%d', '%s' )
			);
			if ( false === $deleted ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational context for a failed consent write.
				error_log( 'Artist email consent delete failed: ' . $wpdb->last_error );
				return new WP_Error(
					'artist_email_consent_delete_failed',
					'Artist email preferences could not be updated.',
					array( 'status' => 500 )
				);
			}
		}

		return array(
			'success' => true,
			'message' => 'Subscription preferences updated.',
			'user_id' => $user_id,
		);
	} finally {
		restore_current_blog();
	}
}
