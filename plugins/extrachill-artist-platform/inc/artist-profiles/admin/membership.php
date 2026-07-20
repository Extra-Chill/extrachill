<?php
/**
 * Canonical artist membership relationship primitives.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds an artist profile ID to both sides of the membership relationship.
 *
 * A false result may leave one side written. Reciprocal readers reject that
 * partial state, and retrying this idempotent operation reconciles it.
 *
 * @param int $user_id   The ID of the user.
 * @param int $artist_id The ID of the artist_profile post.
 * @return bool True when both sides contain the relationship, false on failure.
 */
function ec_add_artist_membership( $user_id, $artist_id ) {
	ec_set_artist_membership_failure();
	$user_id   = absint( $user_id );
	$artist_id = absint( $artist_id );

	if ( ! $user_id || ! $artist_id || ! get_userdata( $user_id ) ) {
		ec_set_artist_membership_failure(
			'invalid_artist_membership',
			__( 'The artist membership target is invalid.', 'extrachill-artist-platform' ),
			array(
				'status'                => 400,
				'retryable'             => false,
				'partial_state_created' => false,
			)
		);
		return false;
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		ec_set_artist_membership_failure(
			'artist_site_unavailable',
			__( 'The artist site is unavailable.', 'extrachill-artist-platform' ),
			array(
				'status'                => 503,
				'retryable'             => true,
				'partial_state_created' => false,
			)
		);
		return false;
	}

	if ( ! ec_acquire_artist_membership_lock( $user_id, $artist_id ) ) {
		ec_set_artist_membership_failure(
			'artist_membership_busy',
			__( 'The artist membership is being updated. Retry the operation.', 'extrachill-artist-platform' ),
			array(
				'status'                => 409,
				'retryable'             => true,
				'partial_state_created' => false,
			)
		);
		return false;
	}

	try {
		switch_to_blog( $artist_blog_id );
		try {
			if ( 'artist_profile' !== get_post_type( $artist_id ) || 'publish' !== get_post_status( $artist_id ) ) {
				ec_set_artist_membership_failure(
					'invalid_artist_profile',
					__( 'The artist profile is unavailable.', 'extrachill-artist-platform' ),
					array(
						'status'                => 404,
						'retryable'             => false,
						'partial_state_created' => false,
					)
				);
				return false;
			}

			$artist_had_membership = in_array( $user_id, ec_normalize_artist_relationship_ids( get_post_meta( $artist_id, '_artist_member_ids', true ) ), true );
			if ( ! ec_update_artist_relationship_ids( 'post', $artist_id, '_artist_member_ids', $user_id, true ) ) {
				ec_set_artist_membership_failure(
					'artist_roster_update_failed',
					__( 'The artist roster could not be updated.', 'extrachill-artist-platform' ),
					array(
						'status'                => 503,
						'retryable'             => true,
						'partial_state_created' => false,
					)
				);
				return false;
			}
		} finally {
			restore_current_blog();
		}

		if ( ! ec_update_artist_relationship_ids( 'user', $user_id, '_artist_profile_ids', $artist_id, true ) ) {
			if ( $artist_had_membership ) {
				ec_set_artist_membership_failure(
					'user_membership_update_failed',
					__( 'The user membership could not be updated; the pre-existing artist roster entry was preserved.', 'extrachill-artist-platform' ),
					array(
						'status'                => 503,
						'retryable'             => true,
						'partial_state_created' => false,
					)
				);
				return false;
			}

			switch_to_blog( $artist_blog_id );
			try {
				$rolled_back = ec_update_artist_relationship_ids( 'post', $artist_id, '_artist_member_ids', $user_id, false );
			} finally {
				restore_current_blog();
			}
			if ( ! $rolled_back ) {
				ec_set_artist_membership_failure(
					'artist_membership_rollback_failed',
					__( 'Artist membership rollback failed. Manual reconciliation is required.', 'extrachill-artist-platform' ),
					array(
						'status'                => 500,
						'retryable'             => false,
						'partial_state_created' => true,
					)
				);
			} else {
				ec_set_artist_membership_failure(
					'user_membership_update_failed',
					__( 'The user membership could not be updated; the artist roster was rolled back.', 'extrachill-artist-platform' ),
					array(
						'status'                => 503,
						'retryable'             => true,
						'partial_state_created' => false,
					)
				);
			}
			return false;
		}

		ec_set_artist_membership_failure();
		return true;
	} finally {
		ec_release_artist_membership_lock( $user_id, $artist_id );
	}
}

/**
 * Removes an artist membership from both relationship records.
 *
 * Both writes are attempted. A false result is safe to retry and reciprocal
 * readers reject either possible one-sided state in the meantime.
 *
 * @param int $user_id   The ID of the user.
 * @param int $artist_id The ID of the artist_profile post.
 * @return bool True on success, false on failure.
 */
function ec_remove_artist_membership( $user_id, $artist_id ) {
	ec_set_artist_membership_failure();
	$user_id   = absint( $user_id );
	$artist_id = absint( $artist_id );

	if ( ! $user_id || ! $artist_id ) {
		ec_set_artist_membership_failure(
			'invalid_artist_membership',
			__( 'The artist membership target is invalid.', 'extrachill-artist-platform' ),
			array(
				'status'    => 400,
				'retryable' => false,
			)
		);
		return false;
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		ec_set_artist_membership_failure(
			'artist_site_unavailable',
			__( 'The artist site is unavailable; no membership data was changed.', 'extrachill-artist-platform' ),
			array(
				'status'    => 503,
				'retryable' => true,
			)
		);
		return false;
	}

	if ( ! ec_acquire_artist_membership_lock( $user_id, $artist_id ) ) {
		ec_set_artist_membership_failure(
			'artist_membership_busy',
			__( 'The artist membership is being updated. Retry the operation.', 'extrachill-artist-platform' ),
			array(
				'status'    => 409,
				'retryable' => true,
			)
		);
		return false;
	}

	try {
		$user_updated = ec_update_artist_relationship_ids( 'user', $user_id, '_artist_profile_ids', $artist_id, false );

		switch_to_blog( $artist_blog_id );
		try {
			$artist_updated = ec_update_artist_relationship_ids( 'post', $artist_id, '_artist_member_ids', $user_id, false );
		} finally {
			restore_current_blog();
		}

		if ( ! $user_updated || ! $artist_updated ) {
			ec_set_artist_membership_failure(
				$user_updated !== $artist_updated ? 'artist_membership_partial_remove' : 'artist_membership_remove_failed',
				$user_updated !== $artist_updated
					? __( 'Artist membership removal was partial. Retry reconciliation before continuing.', 'extrachill-artist-platform' )
					: __( 'Artist membership removal failed without changing the relationship.', 'extrachill-artist-platform' ),
				array(
					'status'    => 500,
					'retryable' => false,
				)
			);
			return false;
		}

		ec_set_artist_membership_failure();
		return true;
	} finally {
		ec_release_artist_membership_lock( $user_id, $artist_id );
	}
}

/**
 * Store or clear the last actionable membership failure for this request.
 *
 * @param string $code    Error code, or empty to clear.
 * @param string $message Error message.
 * @param array  $data    Optional error data.
 */
function ec_set_artist_membership_failure( $code = '', $message = '', $data = array() ) {
	$GLOBALS['ec_artist_membership_failure'] = $code ? new WP_Error( $code, $message, $data ) : null;
}

/**
 * Get the last actionable membership failure.
 *
 * @return WP_Error|null Last failure, if any.
 */
function ec_get_artist_membership_failure() {
	$failure = $GLOBALS['ec_artist_membership_failure'] ?? null;
	return $failure instanceof WP_Error ? $failure : null;
}

/**
 * Acquire the relationship-wide database advisory lock.
 *
 * @param int $user_id   User ID.
 * @param int $artist_id Artist ID.
 * @return bool Whether the lock was acquired.
 */
function ec_acquire_artist_membership_lock( $user_id, $artist_id ) {
	global $wpdb;
	$lock_name = sprintf( 'ec_artist_membership_%d_%d', $user_id, $artist_id );
	if ( ! empty( $GLOBALS['ec_artist_membership_locks'][ $lock_name ] ) ) {
		return false;
	}

	$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock serializes two-table relationship writes, including absent rows.
	if ( $acquired ) {
		$GLOBALS['ec_artist_membership_locks'][ $lock_name ] = true;
	}
	return $acquired;
}

/**
 * Release the relationship-wide database advisory lock.
 *
 * @param int $user_id   User ID.
 * @param int $artist_id Artist ID.
 */
function ec_release_artist_membership_lock( $user_id, $artist_id ) {
	global $wpdb;
	$lock_name = sprintf( 'ec_artist_membership_%d_%d', $user_id, $artist_id );
	if ( empty( $GLOBALS['ec_artist_membership_locks'][ $lock_name ] ) ) {
		return;
	}
	$released = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the relationship-scoped advisory lock acquired above.
	if ( $released ) {
		unset( $GLOBALS['ec_artist_membership_locks'][ $lock_name ] );
	}
}

/**
 * Compare-and-swap one side of an artist relationship with conflict retries.
 *
 * @param string $object_type Object type: user or post.
 * @param int    $object_id   User or post ID.
 * @param string $meta_key    Relationship meta key.
 * @param int    $related_id  ID to add or remove.
 * @param bool   $add         Whether to add the relationship.
 * @return bool True when the requested state is stored.
 */
function ec_update_artist_relationship_ids( $object_type, $object_id, $meta_key, $related_id, $add ) {
	$get_meta    = 'user' === $object_type ? 'get_user_meta' : 'get_post_meta';
	$add_meta    = 'user' === $object_type ? 'add_user_meta' : 'add_post_meta';
	$update_meta = 'user' === $object_type ? 'update_user_meta' : 'update_post_meta';

	for ( $attempt = 0; $attempt < 5; ++$attempt ) {
		$current = $get_meta( $object_id, $meta_key, true );
		$ids     = ec_normalize_artist_relationship_ids( $current );
		$has_id  = in_array( $related_id, $ids, true );
		if ( $add === $has_id ) {
			return true;
		}

		$next = $add
			? array_values( array_unique( array_merge( $ids, array( $related_id ) ) ) )
			: array_values( array_diff( $ids, array( $related_id ) ) );

		if ( ! metadata_exists( $object_type, $object_id, $meta_key ) ) {
			if ( $add_meta( $object_id, $meta_key, $next, true ) ) {
				return true;
			}
			if ( metadata_exists( $object_type, $object_id, $meta_key ) ) {
				continue;
			}
			return false;
		}

		if ( $update_meta( $object_id, $meta_key, $next, $current ) ) {
			return true;
		}

		if ( maybe_serialize( $get_meta( $object_id, $meta_key, true ) ) === maybe_serialize( $current ) ) {
			return false;
		}
	}

	return false;
}

/**
 * Normalize IDs stored in either side of the artist membership relationship.
 *
 * @param mixed $ids Stored relationship value.
 * @return int[] Unique positive IDs.
 */
function ec_normalize_artist_relationship_ids( $ids ) {
	if ( ! is_array( $ids ) ) {
		return array();
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

/**
 * Gets reciprocally linked users from the maintained artist-side roster.
 *
 * @param int $artist_profile_id The ID of the artist profile CPT.
 * @return array Array of WP_User objects.
 */
function ec_get_linked_members( $artist_profile_id ) {
	$artist_profile_id = absint( $artist_profile_id );
	$artist_blog_id    = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_profile_id || ! $artist_blog_id ) {
		return array();
	}

	switch_to_blog( $artist_blog_id );
	try {
		if ( 'artist_profile' !== get_post_type( $artist_profile_id ) || 'publish' !== get_post_status( $artist_profile_id ) ) {
			return array();
		}
		$member_ids = ec_normalize_artist_relationship_ids( get_post_meta( $artist_profile_id, '_artist_member_ids', true ) );
	} finally {
		restore_current_blog();
	}

	$members = array();
	foreach ( $member_ids as $user_id ) {
		$user       = get_userdata( $user_id );
		$artist_ids = ec_normalize_artist_relationship_ids( get_user_meta( $user_id, '_artist_profile_ids', true ) );
		if ( $user && in_array( $artist_profile_id, $artist_ids, true ) ) {
			$members[] = $user;
		}
	}

	return $members;
}
