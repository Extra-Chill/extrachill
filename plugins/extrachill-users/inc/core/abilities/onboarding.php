<?php
/**
 * Onboarding Abilities
 *
 * Core primitives for the onboarding lifecycle:
 * - extrachill/complete-onboarding   Finalize username, flags, send email
 * - extrachill/get-onboarding-status Read onboarding state
 * - extrachill/validate-username     Check username validity and availability
 *
 * @package ExtraChill\Users
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

const EC_USERS_ONBOARDING_ARTIST_GRANT_META = '_extrachill_onboarding_artist_grant';

add_action( 'wp_abilities_api_init', 'extrachill_users_register_onboarding_abilities' );

/**
 * Register onboarding abilities.
 */
function extrachill_users_register_onboarding_abilities() {

	// ── Complete Onboarding ─────────────────────────────────────────────
	wp_register_ability(
		'extrachill/complete-onboarding',
		array(
			'label'               => __( 'Complete Onboarding', 'extrachill-users' ),
			'description'         => __( 'Finalize user onboarding: validate and set username, set artist/professional flags, mark complete, and send welcome email.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'                => array(
						'type'        => 'integer',
						'description' => __( 'User ID to complete onboarding for.', 'extrachill-users' ),
					),
					'username'               => array(
						'type'        => 'string',
						'description' => __( 'Chosen username.', 'extrachill-users' ),
					),
					'user_is_artist'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether user is a musician.', 'extrachill-users' ),
					),
					'user_is_professional'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether user works in the music industry.', 'extrachill-users' ),
					),
					'local_scene'            => array(
						'type'        => 'string',
						'description' => __( 'Optional canonical Local Scene slug.', 'extrachill-users' ),
					),
					'local_scene_visibility' => array(
						'type'    => 'string',
						'enum'    => array( 'public', 'private' ),
						'default' => 'public',
					),
				),
				'required'   => array( 'user_id', 'username' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'user'         => array( 'type' => 'object' ),
					'redirect_url' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_complete_onboarding',
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

	// ── Get Onboarding Status ───────────────────────────────────────────
	wp_register_ability(
		'extrachill/get-onboarding-status',
		array(
			'label'               => __( 'Get Onboarding Status', 'extrachill-users' ),
			'description'         => __( 'Get onboarding completion state, flags, and current username for a user.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'User ID.', 'extrachill-users' ),
					),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'completed' => array( 'type' => 'boolean' ),
					'from_join' => array( 'type' => 'boolean' ),
					'fields'    => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_onboarding_status',
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

	// ── Validate Username ───────────────────────────────────────────────
	wp_register_ability(
		'extrachill/validate-username',
		array(
			'label'               => __( 'Validate Username', 'extrachill-users' ),
			'description'         => __( 'Check if a username is valid (length, characters, reserved words) and available.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'username' => array(
						'type'        => 'string',
						'description' => __( 'Username to validate.', 'extrachill-users' ),
					),
					'user_id'  => array(
						'type'        => 'integer',
						'description' => __( 'Current user ID (allows keeping own username).', 'extrachill-users' ),
					),
				),
				'required'   => array( 'username' ),
			),
			'output_schema'       => array(
				'type'        => 'boolean',
				'description' => __( 'True if username is valid and available.', 'extrachill-users' ),
			),
			'execute_callback'    => 'extrachill_users_ability_validate_username',
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
}

// ─── Execute Callbacks ──────────────────────────────────────────────────────

/**
 * Return the request-local onboarding lock registry.
 *
 * @return array<int,bool> Locked user IDs.
 */
function &ec_users_onboarding_lock_registry() {
	static $locks = array();
	return $locks;
}

/**
 * Acquire the per-user onboarding transition lock.
 *
 * The request-local guard rejects same-connection reentrancy, while MySQL's
 * advisory lock serializes separate PHP workers.
 *
 * @param int $user_id User ID.
 * @return string|WP_Error Lock name or error.
 */
function ec_users_acquire_onboarding_lock( $user_id ) {
	global $wpdb;

	$locks = &ec_users_onboarding_lock_registry();
	if ( isset( $locks[ $user_id ] ) ) {
		return new WP_Error(
			'onboarding_transition_locked',
			__( 'Another onboarding update is in progress. Please retry.', 'extrachill-users' ),
			array(
				'status'         => 409,
				'classification' => 'retry',
				'retryable'      => true,
			)
		);
	}

	$locks[ $user_id ] = true;
	$lock_name         = 'ec_onboarding_' . md5( (string) $user_id );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Existing per-transition advisory lock pattern.
	$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	if ( null === $acquired && '' !== $wpdb->last_error ) {
		unset( $locks[ $user_id ] );
		return new WP_Error(
			'onboarding_lock_database_error',
			__( 'Onboarding could not be updated. Please retry.', 'extrachill-users' ),
			array(
				'status'         => 500,
				'classification' => 'retry',
				'retryable'      => true,
			)
		);
	}
	if ( 1 !== (int) $acquired ) {
		unset( $locks[ $user_id ] );
		return new WP_Error(
			'onboarding_transition_locked',
			__( 'Another onboarding update is in progress. Please retry.', 'extrachill-users' ),
			array(
				'status'         => 409,
				'classification' => 'retry',
				'retryable'      => true,
			)
		);
	}

	return $lock_name;
}

/**
 * Release an owned onboarding transition lock.
 *
 * @param int    $user_id   User ID.
 * @param string $lock_name Advisory lock name.
 */
function ec_users_release_onboarding_lock( $user_id, $lock_name ) {
	global $wpdb;

	$locks = &ec_users_onboarding_lock_registry();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Existing per-transition advisory lock pattern.
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	unset( $locks[ $user_id ] );
}

/**
 * Compare-and-swap the onboarding grant delivery state.
 *
 * @param int   $user_id User ID.
 * @param array $next    Replacement state.
 * @param array $current State read while holding the transition lock.
 * @return bool Whether the exact transition persisted.
 */
function ec_users_write_onboarding_grant_state( $user_id, array $next, array $current ) {
	if ( empty( $current ) ) {
		return false !== add_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, $next, true );
	}

	return false !== update_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, $next, $current );
}

/**
 * Reconcile an incomplete pending grant against the current bounded intent.
 *
 * When no access persisted, the retry may safely replace or remove stale
 * intent. Existing access makes a changed intent ambiguous and must fail
 * closed rather than relabel the transition.
 *
 * @param int   $user_id             User ID.
 * @param array $state               Current grant receipt.
 * @param bool  $is_artist           Current artist intent.
 * @param bool  $is_professional     Current professional intent.
 * @return array|WP_Error Reconciled receipt or error.
 */
function ec_users_reconcile_pending_onboarding_grant( $user_id, array $state, $is_artist, $is_professional ) {
	if ( 'pending' !== ( $state['status'] ?? '' ) ) {
		return $state;
	}

	$has_artist       = '1' === get_user_meta( $user_id, 'user_is_artist', true );
	$has_professional = '1' === get_user_meta( $user_id, 'user_is_professional', true );
	$wants_access     = $is_artist || $is_professional;
	$current_method   = $wants_access ? ec_users_get_onboarding_artist_grant_method( $is_artist, $is_professional ) : '';
	$receipt_method   = (string) ( $state['method'] ?? '' );

	if ( $has_artist || $has_professional ) {
		$stored_method = ec_users_get_onboarding_artist_grant_method( $has_artist, $has_professional );
		if ( $stored_method !== $receipt_method || $current_method !== $stored_method ) {
			return new WP_Error(
				'onboarding_grant_intent_repair_required',
				__( 'Stored onboarding access no longer matches the pending grant and requires manual reconciliation.', 'extrachill-users' ),
				array(
					'status'         => 500,
					'classification' => 'manual_repair',
					'retryable'      => false,
				)
			);
		}
		return $state;
	}

	if ( ! $wants_access ) {
		$deleted = delete_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, $state );
		if ( $deleted && ! metadata_exists( 'user', $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META ) ) {
			return array();
		}
		return new WP_Error(
			'onboarding_grant_intent_update_failed',
			__( 'The stale onboarding grant could not be cleared. Please retry.', 'extrachill-users' ),
			array(
				'status'         => 500,
				'classification' => 'retry',
				'retryable'      => true,
			)
		);
	}

	if ( $current_method === $receipt_method ) {
		return $state;
	}
	$next           = $state;
	$next['method'] = $current_method;
	if ( ec_users_write_onboarding_grant_state( $user_id, $next, $state ) ) {
		return $next;
	}

	return new WP_Error(
		'onboarding_grant_intent_update_failed',
		__( 'The onboarding grant intent could not be updated. Please retry.', 'extrachill-users' ),
		array(
			'status'         => 500,
			'classification' => 'retry',
			'retryable'      => true,
		)
	);
}

/**
 * Restore transition metadata and verify every resulting value.
 *
 * @param int   $user_id      User ID.
 * @param array $previous_meta Previous value/existence records by key.
 * @return bool Whether every key was restored exactly.
 */
function ec_users_restore_onboarding_transition_meta( $user_id, array $previous_meta ) {
	$restored = true;
	foreach ( $previous_meta as $meta_key => $previous ) {
		if ( $previous['exists'] ) {
			update_user_meta( $user_id, $meta_key, $previous['value'] );
			$restored_value = get_user_meta( $user_id, $meta_key, true );
			$key_restored   = $restored_value === $previous['value'];
		} else {
			delete_user_meta( $user_id, $meta_key );
			$key_restored = ! metadata_exists( 'user', $user_id, $meta_key );
		}
		$restored = $restored && $key_restored;
	}

	return $restored;
}

/**
 * Deliver one reserved onboarding grant event with durable outcome markers.
 *
 * @param int   $user_id User ID.
 * @param array $state   Pending delivery state held under the onboarding lock.
 * @return true|WP_Error True when delivered, or a classified recovery error.
 */
function ec_users_deliver_onboarding_artist_grant( $user_id, array $state ) {
	if ( 'delivered' === ( $state['status'] ?? '' ) ) {
		return true;
	}
	if ( 'pending' !== ( $state['status'] ?? '' ) ) {
		return new WP_Error(
			'onboarding_grant_repair_required',
			__( 'The onboarding access grant requires manual reconciliation.', 'extrachill-users' ),
			array(
				'status'         => 500,
				'classification' => 'manual_repair',
				'retryable'      => false,
			)
		);
	}

	$reserved           = $state;
	$reserved['status'] = 'reserved';
	if ( ! ec_users_write_onboarding_grant_state( $user_id, $reserved, $state ) ) {
		return new WP_Error(
			'onboarding_grant_reservation_failed',
			__( 'The onboarding access event could not be reserved. Please retry.', 'extrachill-users' ),
			array(
				'status'         => 500,
				'classification' => 'retry',
				'retryable'      => true,
			)
		);
	}

	$method       = (string) $state['method'];
	$is_artist    = in_array( $method, array( 'artist', 'artist_and_professional' ), true );
	$professional = in_array( $method, array( 'professional', 'artist_and_professional' ), true );
	if ( 0 >= ec_users_emit_onboarding_artist_access_granted( $user_id, $is_artist, $professional ) ) {
		if ( ! ec_users_write_onboarding_grant_state( $user_id, $state, $reserved ) ) {
			return new WP_Error(
				'onboarding_grant_repair_required',
				__( 'Analytics delivery failed and its reservation requires manual reconciliation.', 'extrachill-users' ),
				array(
					'status'         => 500,
					'classification' => 'manual_repair',
					'retryable'      => false,
				)
			);
		}
		return new WP_Error(
			'onboarding_grant_delivery_failed',
			__( 'The onboarding access event could not be recorded. Please retry.', 'extrachill-users' ),
			array(
				'status'         => 500,
				'classification' => 'retry',
				'retryable'      => true,
			)
		);
	}

	$delivered                 = $reserved;
	$delivered['status']       = 'delivered';
	$delivered['delivered_at'] = time();
	if ( ! ec_users_write_onboarding_grant_state( $user_id, $delivered, $reserved ) ) {
		return new WP_Error(
			'onboarding_grant_repair_required',
			__( 'The onboarding access event was recorded but its receipt requires manual reconciliation.', 'extrachill-users' ),
			array(
				'status'         => 500,
				'classification' => 'manual_repair',
				'retryable'      => false,
			)
		);
	}

	return true;
}

/**
 * Complete onboarding for a user.
 *
 * Validates username, updates the user record, sets artist/professional flags,
 * marks onboarding complete, refreshes auth, and sends the welcome email.
 *
 * @param array $input {user_id, username, user_is_artist, user_is_professional}.
 * @return array|WP_Error Success array with user data and redirect URL, or error.
 */
function extrachill_users_ability_complete_onboarding( $input ) {
	$user_id              = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$username             = isset( $input['username'] ) ? sanitize_user( $input['username'], true ) : '';
	$user_is_artist       = ! empty( $input['user_is_artist'] );
	$user_is_professional = ! empty( $input['user_is_professional'] );
	$local_scene          = isset( $input['local_scene'] ) ? sanitize_title( (string) $input['local_scene'] ) : '';
	$visibility           = isset( $input['local_scene_visibility'] ) ? sanitize_key( (string) $input['local_scene_visibility'] ) : 'public';

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return ec_users_onboarding_error( 'invalid_user', __( 'Invalid user.', 'extrachill-users' ), $user_id );
	}

	// Validate username via ability.
	$validate_ability = wp_get_ability( 'extrachill/validate-username' );
	if ( $validate_ability ) {
		$valid = $validate_ability->execute(
			array(
				'username' => $username,
				'user_id'  => $user_id,
			)
		);

		if ( is_wp_error( $valid ) ) {
			return ec_users_onboarding_error( $valid->get_error_code(), $valid->get_error_message(), $user_id );
		}
	}

	// Join flow requires artist or professional flag.
	$from_join = function_exists( 'ec_is_onboarding_from_join' ) && ec_is_onboarding_from_join( $user_id );
	if ( $from_join && ! $user_is_artist && ! $user_is_professional ) {
		return ec_users_onboarding_error(
			'artist_or_professional_required',
			__( 'Please select "I am a musician" or "I work in the music industry" to continue.', 'extrachill-users' ),
			$user_id
		);
	}

	if ( '' !== $local_scene ) {
		$scene_result = extrachill_users_resolve_local_scene( $local_scene );
		if ( is_wp_error( $scene_result ) ) {
			return ec_users_onboarding_error( $scene_result->get_error_code(), $scene_result->get_error_message(), $user_id );
		}
		$local_scene = $scene_result['slug'];
	}

	if ( ! in_array( $visibility, array( 'public', 'private' ), true ) ) {
		return ec_users_onboarding_error( 'invalid_local_scene_visibility', __( 'Local Scene visibility must be public or private.', 'extrachill-users' ), $user_id );
	}

	$lock = ec_users_acquire_onboarding_lock( $user_id );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		$grant_state = get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true );
		$grant_state = is_array( $grant_state ) ? $grant_state : array();
		if ( function_exists( 'ec_is_onboarding_complete' ) && ec_is_onboarding_complete( $user_id ) ) {
			if ( ! empty( $grant_state ) && 'delivered' !== ( $grant_state['status'] ?? '' ) ) {
				$delivery = ec_users_deliver_onboarding_artist_grant( $user_id, $grant_state );
				if ( is_wp_error( $delivery ) ) {
					return $delivery;
				}
			}
			return ec_users_onboarding_error( 'already_completed', __( 'Onboarding already completed.', 'extrachill-users' ), $user_id );
		}
		if ( ! empty( $grant_state ) ) {
			$grant_state = ec_users_reconcile_pending_onboarding_grant( $user_id, $grant_state, $user_is_artist, $user_is_professional );
			if ( is_wp_error( $grant_state ) ) {
				return $grant_state;
			}
			if ( ! empty( $grant_state ) && 'pending' !== ( $grant_state['status'] ?? '' ) ) {
				return new WP_Error(
					'onboarding_grant_repair_required',
					__( 'The onboarding access grant requires manual reconciliation.', 'extrachill-users' ),
					array(
						'status'         => 500,
						'classification' => 'manual_repair',
						'retryable'      => false,
					)
				);
			}
		}

		// Update username in database.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Updating the core user row is the onboarding operation.
		$result = $wpdb->update(
			$wpdb->users,
			array(
				'user_login'    => $username,
				'user_nicename' => sanitize_title( $username ),
				'display_name'  => $username,
			),
			array( 'ID' => $user_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return ec_users_onboarding_error( 'update_failed', __( 'Failed to update username.', 'extrachill-users' ), $user_id );
		}

		if ( '' !== $local_scene ) {
			extrachill_users_set_local_scene( $user_id, $local_scene );
		}
		extrachill_users_set_local_scene_visibility( $user_id, $visibility );

		$had_artist_access = '1' === get_user_meta( $user_id, 'user_is_artist', true )
			|| '1' === get_user_meta( $user_id, 'user_is_professional', true );
		$new_grant_state   = false;
		if ( empty( $grant_state ) && ! $had_artist_access && ( $user_is_artist || $user_is_professional ) ) {
			$grant_state = array(
				'status'     => 'pending',
				'method'     => ec_users_get_onboarding_artist_grant_method( $user_is_artist, $user_is_professional ),
				'created_at' => time(),
			);
			if ( ! ec_users_write_onboarding_grant_state( $user_id, $grant_state, array() ) ) {
				return new WP_Error(
					'onboarding_grant_state_failed',
					__( 'The onboarding access transition could not be reserved. Please retry.', 'extrachill-users' ),
					array(
						'status'         => 500,
						'classification' => 'retry',
						'retryable'      => true,
					)
				);
			}
			$new_grant_state = true;
		}

		// Persist access and completion as one verified transition. Restoring these
		// values on failure prevents a partial grant from becoming an unmeasurable
		// retry that appears already granted.
		$transition_keys = array(
			'user_is_artist',
			'user_is_professional',
			'onboarding_completed',
			'onboarding_completed_at',
		);
		$previous_meta   = array();
		foreach ( $transition_keys as $meta_key ) {
			$previous_meta[ $meta_key ] = array(
				'exists' => metadata_exists( 'user', $user_id, $meta_key ),
				'value'  => get_user_meta( $user_id, $meta_key, true ),
			);
		}
		$completed_at = time();

		update_user_meta( $user_id, 'user_is_artist', $user_is_artist ? '1' : '0' );
		update_user_meta( $user_id, 'user_is_professional', $user_is_professional ? '1' : '0' );
		update_user_meta( $user_id, 'onboarding_completed', '1' );
		update_user_meta( $user_id, 'onboarding_completed_at', $completed_at );

		$stored_completed_at  = (int) get_user_meta( $user_id, 'onboarding_completed_at', true );
		$transition_persisted = ( $user_is_artist ? '1' : '0' ) === get_user_meta( $user_id, 'user_is_artist', true )
			&& ( $user_is_professional ? '1' : '0' ) === get_user_meta( $user_id, 'user_is_professional', true )
			&& '1' === get_user_meta( $user_id, 'onboarding_completed', true )
			&& $stored_completed_at === $completed_at;

		if ( ! $transition_persisted ) {
			$rollback_succeeded = ec_users_restore_onboarding_transition_meta( $user_id, $previous_meta );
			if ( ! $rollback_succeeded ) {
				$repair_recorded = false;
				if ( ! empty( $grant_state ) && 'pending' === ( $grant_state['status'] ?? '' ) ) {
					$repair_state           = $grant_state;
					$repair_state['status'] = 'repair_required';
					$repair_recorded        = ec_users_write_onboarding_grant_state( $user_id, $repair_state, $grant_state );
				}
				if ( ! empty( $grant_state ) && ! $repair_recorded ) {
					$current_grant_state = get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true );
					if ( $grant_state === $current_grant_state ) {
						return new WP_Error(
							'onboarding_state_rollback_retry_required',
							__( 'Onboarding state was only partially restored. Please retry.', 'extrachill-users' ),
							array(
								'status'         => 500,
								'classification' => 'retry',
								'retryable'      => true,
							)
						);
					}
				}
				return new WP_Error(
					'onboarding_state_rollback_failed',
					__( 'Onboarding state could not be restored and requires manual repair.', 'extrachill-users' ),
					array(
						'status'         => 500,
						'classification' => 'manual_repair',
						'retryable'      => false,
					)
				);
			}

			if ( $new_grant_state ) {
				$deleted = delete_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, $grant_state );
				if ( ! $deleted || metadata_exists( 'user', $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META ) ) {
					return new WP_Error(
						'onboarding_grant_repair_required',
						__( 'Onboarding was restored but its grant reservation requires manual repair.', 'extrachill-users' ),
						array(
							'status'         => 500,
							'classification' => 'manual_repair',
							'retryable'      => false,
						)
					);
				}
			}

			return new WP_Error(
				'onboarding_state_update_failed',
				__( 'Failed to save onboarding state. Please retry.', 'extrachill-users' ),
				array(
					'status'         => 500,
					'classification' => 'retry',
					'retryable'      => true,
				)
			);
		}

		if ( ! empty( $grant_state ) && 'pending' === ( $grant_state['status'] ?? '' ) ) {
			$delivery = ec_users_deliver_onboarding_artist_grant( $user_id, $grant_state );
			if ( is_wp_error( $delivery ) ) {
				return $delivery;
			}
		}
	} finally {
		ec_users_release_onboarding_lock( $user_id, $lock );
	}

	clean_user_cache( $user_id );

	// Refresh auth session.
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );

	/**
	 * Fires after onboarding is completed.
	 *
	 * @param int   $user_id User ID.
	 * @param array $input   Onboarding data.
	 */
	do_action( 'ec_onboarding_completed', $user_id, $input );

	$event_data = array(
		'has_local_scene' => '' !== $local_scene,
		'is_artist'       => $user_is_artist,
		'is_professional' => $user_is_professional,
		'from_join'       => $from_join,
	);
	ec_users_emit_onboarding_event( EC_ANALYTICS_EVENT_ONBOARDING_COMPLETED, $user_id, $event_data );
	if ( get_user_meta( $user_id, 'onboarding_reminder_sent_at', true ) ) {
		ec_users_emit_onboarding_event( EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_RECOVERED, $user_id, $event_data );
	}

	// Send welcome email via ability.
	$email_ability = wp_get_ability( 'extrachill/send-welcome-email' );
	if ( $email_ability ) {
		$email_ability->execute(
			array(
				'user_id'    => $user_id,
				'email_type' => 'onboarding_complete',
			)
		);
	}

	// Determine redirect URL.
	$redirect_url = get_user_meta( $user_id, 'onboarding_redirect_url', true );
	if ( empty( $redirect_url ) ) {
		$redirect_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url();
	}

	if ( $from_join && function_exists( 'ec_get_site_url' ) ) {
		$redirect_url = ec_get_site_url( 'artist' ) . '/create-artist/';
	}

	return array(
		'success'      => true,
		'user'         => array(
			'id'                   => $user_id,
			'username'             => $username,
			'user_is_artist'       => $user_is_artist,
			'user_is_professional' => $user_is_professional,
		),
		'redirect_url' => $redirect_url,
	);
}

/**
 * Get onboarding status for a user.
 *
 * @param array $input {user_id}.
 * @return array {completed, from_join, fields}.
 */
function extrachill_users_ability_get_onboarding_status( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( function_exists( 'ec_get_onboarding_status' ) ) {
		return ec_get_onboarding_status( $user_id );
	}

	// Fallback if utility not loaded.
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return array(
			'completed' => true,
			'from_join' => false,
			'fields'    => array(),
		);
	}

	$meta_value = get_user_meta( $user_id, 'onboarding_completed', true );
	$completed  = '' === $meta_value || '1' === $meta_value;

	return array(
		'completed' => $completed,
		'from_join' => '1' === get_user_meta( $user_id, 'onboarding_from_join', true ),
		'fields'    => array(
			'username'               => $user->user_login,
			'user_is_artist'         => '1' === get_user_meta( $user_id, 'user_is_artist', true ),
			'user_is_professional'   => '1' === get_user_meta( $user_id, 'user_is_professional', true ),
			'local_scene'            => extrachill_users_get_local_scene( $user_id ),
			'local_scene_visibility' => extrachill_users_get_local_scene_visibility( $user_id ),
		),
	);
}

/**
 * Validate a username for onboarding.
 *
 * Checks length, allowed characters, reserved words, and availability.
 *
 * @param array $input {username, user_id}.
 * @return true|WP_Error True if valid, WP_Error with specific code otherwise.
 */
function extrachill_users_ability_validate_username( $input ) {
	$username = isset( $input['username'] ) ? sanitize_user( $input['username'], true ) : '';
	$user_id  = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( strlen( $username ) < 3 ) {
		return new WP_Error(
			'username_too_short',
			__( 'Username must be at least 3 characters.', 'extrachill-users' )
		);
	}

	if ( strlen( $username ) > 60 ) {
		return new WP_Error(
			'username_too_long',
			__( 'Username must be 60 characters or less.', 'extrachill-users' )
		);
	}

	if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $username ) ) {
		return new WP_Error(
			'username_invalid_chars',
			__( 'Username can only contain letters, numbers, hyphens, and underscores.', 'extrachill-users' )
		);
	}

	$existing_user = get_user_by( 'login', $username );
	if ( $existing_user && $existing_user->ID !== $user_id ) {
		return new WP_Error(
			'username_exists',
			__( 'This username is already taken.', 'extrachill-users' )
		);
	}

	$reserved_usernames = array(
		'admin',
		'administrator',
		'extrachill',
		'support',
		'help',
		'info',
		'contact',
		'webmaster',
		'root',
		'system',
		'moderator',
		'mod',
	);

	if ( in_array( strtolower( $username ), $reserved_usernames, true ) ) {
		return new WP_Error(
			'username_reserved',
			__( 'This username is reserved.', 'extrachill-users' )
		);
	}

	return true;
}
