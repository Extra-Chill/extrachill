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
 * Register the Artist feature's email-sharing purpose identity.
 *
 * @param array $entities Entity identity definitions.
 * @return array
 */
function extrachill_users_register_artist_email_sharing_identity( array $entities ): array {
	$entities['artist-email-sharing'] = array(
		'taxonomy'                           => 'artist',
		'uses_notification_email_preference' => false,
	);

	return $entities;
}
add_filter( 'extrachill_users_entity_subscription_entities', 'extrachill_users_register_artist_email_sharing_identity' );

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

	$subscriptions = extrachill_users_get_artist_email_sharing_subscriptions( $user_id );
	if ( is_wp_error( $subscriptions ) ) {
		return $subscriptions;
	}
	$artist_email_consents = array_values(
		array_filter(
			array_map( 'extrachill_users_artist_email_sharing_presentation', $subscriptions )
		)
	);

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

	if ( ! empty( $consented_artists ) && empty( sanitize_email( $user->user_email ) ) ) {
		return new WP_Error(
			'user_email_missing',
			'An account email is required to share email access with artists.',
			array( 'status' => 400 )
		);
	}

	$desired = array();
	foreach ( $consented_artists as $artist_id ) {
		$identity = extrachill_users_artist_email_sharing_identity( $artist_id );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$desired[ $identity['slug'] ] = $identity;
	}

	$existing = extrachill_users_get_artist_email_sharing_subscriptions( $user_id );
	if ( is_wp_error( $existing ) ) {
		return $existing;
	}
	foreach ( $desired as $slug => $identity ) {
		if ( isset( $existing[ $slug ] ) ) {
			continue;
		}
		$result = extrachill_users_subscribe_to_entity( $user_id, $identity['entity_type'], $identity['taxonomy'], $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	foreach ( $existing as $slug => $identity ) {
		if ( isset( $desired[ $slug ] ) ) {
			continue;
		}
		$result = extrachill_users_unsubscribe_from_entity( $user_id, $identity['entity_type'], $identity['taxonomy'], $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return array(
		'success' => true,
		'message' => 'Subscription preferences updated.',
		'user_id' => $user_id,
	);
}

/**
 * Resolve an artist profile to its canonical email-sharing identity.
 *
 * @param int  $artist_id Artist profile ID.
 * @param bool $allow_binding_fallback Whether the feature resolver may self-heal a missing stored binding.
 * @return array|WP_Error
 */
function extrachill_users_artist_email_sharing_identity( $artist_id, $allow_binding_fallback = true ) {
	$artist_id      = absint( $artist_id );
	$artist_blog_id = (int) apply_filters( 'extrachill_users_artist_consent_blog_id', function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : 0 );
	$main_blog_id   = (int) apply_filters( 'extrachill_users_artist_consent_main_blog_id', function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : 0 );
	if ( ! $artist_id || ! $artist_blog_id || ! $main_blog_id ) {
		return new WP_Error( 'artist_email_consent_identity_unavailable', __( 'The canonical artist identity is unavailable.', 'extrachill-users' ), array( 'status' => 503 ) );
	}

	switch_to_blog( $artist_blog_id );
	try {
		$term_id = absint( get_post_meta( $artist_id, '_artist_term_id', true ) );
		if ( ! $term_id && $allow_binding_fallback && function_exists( 'ec_get_artist_term_id' ) ) {
			$term_id = absint( ec_get_artist_term_id( $artist_id ) );
		}
	} finally {
		restore_current_blog();
	}
	if ( ! $term_id ) {
		return new WP_Error( 'artist_email_consent_binding_missing', __( 'The artist profile has no canonical artist term binding.', 'extrachill-users' ), array( 'status' => 409 ) );
	}

	switch_to_blog( $main_blog_id );
	try {
		$term = get_term( $term_id, 'artist' );
	} finally {
		restore_current_blog();
	}
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'artist_email_consent_term_missing', __( 'The bound canonical artist term is unavailable.', 'extrachill-users' ), array( 'status' => 409 ) );
	}

	return array(
		'entity_type' => 'artist-email-sharing',
		'taxonomy'    => 'artist',
		'slug'        => $term->slug,
	);
}

/**
 * Return all artist email-sharing identities for one user, keyed by slug.
 *
 * @param int $user_id User ID.
 * @return array|WP_Error
 */
function extrachill_users_get_artist_email_sharing_subscriptions( $user_id ) {
	$subscriptions = array();
	$page          = 1;
	do {
		$result = extrachill_users_list_entity_subscriptions( $user_id, $page, 100 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( $result['subscriptions'] as $identity ) {
			if ( 'artist-email-sharing' === $identity['entity_type'] ) {
				$subscriptions[ $identity['slug'] ] = $identity;
			}
		}
		++$page;
	} while ( $page <= $result['total_pages'] );

	return $subscriptions;
}

/**
 * Resolve legacy artist presentation fields outside the generic service.
 *
 * @param array $identity Canonical artist email-sharing identity.
 * @return array|null
 */
function extrachill_users_artist_email_sharing_presentation( array $identity ) {
	$main_blog_id   = (int) apply_filters( 'extrachill_users_artist_consent_main_blog_id', function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : 0 );
	$artist_blog_id = (int) apply_filters( 'extrachill_users_artist_consent_blog_id', function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : 0 );
	if ( ! $main_blog_id || ! $artist_blog_id ) {
		return null;
	}

	switch_to_blog( $main_blog_id );
	try {
		$term      = get_term_by( 'slug', $identity['slug'], 'artist' );
		$artist_id = $term instanceof WP_Term ? absint( get_term_meta( $term->term_id, '_artist_profile_id', true ) ) : 0;
	} finally {
		restore_current_blog();
	}
	if ( ! $artist_id ) {
		return null;
	}

	switch_to_blog( $artist_blog_id );
	try {
		return array(
			'artist_id'     => $artist_id,
			'name'          => get_the_title( $artist_id ),
			'url'           => get_permalink( $artist_id ),
			'email_consent' => true,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Dry-run or migrate authenticated legacy artist consent rows.
 *
 * @param bool $apply Whether to write canonical rows and remove verified legacy rows.
 * @return array|WP_Error
 */
function extrachill_users_migrate_artist_email_sharing_consent( bool $apply = false ) {
	$artist_blog_id = (int) apply_filters( 'extrachill_users_artist_consent_blog_id', function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : 0 );
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'artist_email_consent_migration_unavailable', __( 'The artist site is unavailable.', 'extrachill-users' ) );
	}

	switch_to_blog( $artist_blog_id );
	try {
		global $wpdb;
		$table = $wpdb->prefix . 'artist_subscribers';
		$rows  = $wpdb->get_results( "SELECT subscriber_id, user_id, artist_profile_id FROM {$table} WHERE user_id > 0 AND source = 'platform_follow_consent' ORDER BY subscriber_id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted current-site prefix and fixed legacy source.
	} finally {
		restore_current_blog();
	}
	if ( ! is_array( $rows ) ) {
		return new WP_Error( 'artist_email_consent_migration_read_failed', __( 'Legacy artist consent rows could not be loaded.', 'extrachill-users' ) );
	}

	$report = array();
	$totals = array_fill_keys( array( 'candidates', 'ready', 'migrated', 'already_canonical', 'unresolved', 'failed' ), 0 );
	foreach ( $rows as $row ) {
		++$totals['candidates'];
		$user_id       = absint( $row['user_id'] );
		$subscriber_id = absint( $row['subscriber_id'] );
		$artist_id     = absint( $row['artist_profile_id'] );
		$identity      = extrachill_users_artist_email_sharing_identity( $artist_id, false );
		$status        = 'ready';
		if ( ! get_userdata( $user_id ) || is_wp_error( $identity ) ) {
			$status = 'unresolved';
			++$totals['unresolved'];
		} else {
			$canonical = extrachill_users_entity_subscription_status( $user_id, $identity['entity_type'], $identity['taxonomy'], $identity['slug'] );
			if ( is_wp_error( $canonical ) ) {
				$status = 'failed';
				++$totals['failed'];
			} elseif ( ! $apply ) {
				$status = $canonical['subscribed'] ? 'already-canonical' : 'ready';
				++$totals[ $canonical['subscribed'] ? 'already_canonical' : 'ready' ];
			} else {
				if ( ! $canonical['subscribed'] ) {
					$canonical = extrachill_users_subscribe_to_entity( $user_id, $identity['entity_type'], $identity['taxonomy'], $identity['slug'] );
				}
				$verification = is_wp_error( $canonical ) ? $canonical : extrachill_users_entity_subscription_status( $user_id, $identity['entity_type'], $identity['taxonomy'], $identity['slug'] );
				$verified     = ! is_wp_error( $verification ) && $verification['subscribed'];
				if ( $verified ) {
					switch_to_blog( $artist_blog_id );
					try {
						global $wpdb;
						$deleted = $wpdb->delete(
							$wpdb->prefix . 'artist_subscribers',
							array(
								'subscriber_id' => $subscriber_id,
								'user_id'       => $user_id,
								'source'        => 'platform_follow_consent',
							),
							array( '%d', '%d', '%s' )
						);
					} finally {
						restore_current_blog();
					}
					$status = 1 === $deleted ? 'migrated' : 'failed';
				} else {
					$status = 'failed';
				}
				++$totals[ 'migrated' === $status ? 'migrated' : 'failed' ];
			}
		}

		$report[] = array(
			'subscriber_id'     => $subscriber_id,
			'user_id'           => $user_id,
			'artist_profile_id' => $artist_id,
			'status'            => $status,
			'canonical_slug'    => is_wp_error( $identity ) ? '' : $identity['slug'],
		);
	}

	return array(
		'applied' => $apply,
		'rows'    => $report,
		'totals'  => $totals,
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'extrachill-users migrate-artist-email-consent',
		static function ( $args, $assoc_args ) {
			$result = extrachill_users_migrate_artist_email_sharing_consent( isset( $assoc_args['apply'] ) );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI\Utils\format_items( 'table', $result['rows'], array( 'subscriber_id', 'user_id', 'artist_profile_id', 'status', 'canonical_slug' ) );
			WP_CLI::success( wp_json_encode( $result['totals'] ) );
		},
		array(
			'shortdesc' => 'Dry-run or apply authenticated artist email-consent migration.',
			'synopsis'  => array(
				array(
					'type'        => 'flag',
					'name'        => 'apply',
					'description' => 'Write and verify canonical consent, then remove each legacy row.',
					'optional'    => true,
				),
			),
		)
	);
}
