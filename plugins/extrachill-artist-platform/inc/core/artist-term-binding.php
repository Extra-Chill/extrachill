<?php
/**
 * Artist Profile <-> Artist Term Binding ("the hub join").
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the blogs that own each side of the binding.
 *
 * @return array{artist:int,main:int}|array{}
 */
function ec_artist_binding_blog_ids() {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return array();
	}

	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
	$main_blog_id   = (int) ec_get_blog_id( 'main' );
	if ( $artist_blog_id <= 0 || $main_blog_id <= 0 ) {
		return array();
	}

	return array(
		'artist' => $artist_blog_id,
		'main'   => $main_blog_id,
	);
}

/**
 * Read and validate an artist profile from its owning blog.
 *
 * @param int $profile_id    Artist profile post ID.
 * @param int $artist_blog_id Artist blog ID.
 * @return array{id:int,term_id:int,slug:string,title:string}|array{}
 */
function ec_artist_binding_read_profile( $profile_id, $artist_blog_id ) {
	$profile = array();
	switch_to_blog( $artist_blog_id );
	try {
		$post = get_post( $profile_id );
		if ( $post && 'artist_profile' === $post->post_type ) {
			$profile = array(
				'id'      => (int) $post->ID,
				'term_id' => (int) get_post_meta( $profile_id, '_artist_term_id', true ),
				'slug'    => (string) $post->post_name,
				'title'   => (string) $post->post_title,
			);
		}
	} finally {
		restore_current_blog();
	}

	return $profile;
}

/**
 * Read and validate an artist term from the main blog.
 *
 * @param int $term_id      Artist term ID.
 * @param int $main_blog_id Main blog ID.
 * @return array{id:int,profile_id:int,slug:string,count:int}|array{}
 */
function ec_artist_binding_read_term( $term_id, $main_blog_id ) {
	$artist_term = array();
	switch_to_blog( $main_blog_id );
	try {
		$term = get_term( $term_id, 'artist' );
		if ( $term && ! is_wp_error( $term ) && 'artist' === $term->taxonomy ) {
			$artist_term = array(
				'id'         => (int) $term->term_id,
				'profile_id' => (int) get_term_meta( $term_id, '_artist_profile_id', true ),
				'slug'       => (string) $term->slug,
				'count'      => (int) ( $term->count ?? 0 ),
			);
		}
	} finally {
		restore_current_blog();
	}

	return $artist_term;
}

/**
 * Delete profile-side binding metadata only when it still has the expected value.
 *
 * @param int $profile_id    Artist profile post ID.
 * @param int $term_id       Expected term ID.
 * @param int $artist_blog_id Artist blog ID.
 * @return void
 */
function ec_artist_binding_delete_profile_meta( $profile_id, $term_id, $artist_blog_id ) {
	switch_to_blog( $artist_blog_id );
	try {
		delete_post_meta( $profile_id, '_artist_term_id', $term_id );
	} finally {
		restore_current_blog();
	}
}

/**
 * Delete term-side binding metadata only when it still has the expected value.
 *
 * @param int $term_id      Artist term ID.
 * @param int $profile_id   Expected profile ID.
 * @param int $main_blog_id Main blog ID.
 * @return void
 */
function ec_artist_binding_delete_term_meta( $term_id, $profile_id, $main_blog_id ) {
	switch_to_blog( $main_blog_id );
	try {
		delete_term_meta( $term_id, '_artist_profile_id', $profile_id );
	} finally {
		restore_current_blog();
	}
}

/**
 * Reconcile a resolver-discovered pair without changing an existing binding.
 *
 * Unlike the explicit binder, resolvers may only complete an unambiguous
 * one-sided pair. They must never rebind either entity as a side effect of a
 * lookup or slug fallback.
 *
 * @param int      $profile_id   Artist profile post ID.
 * @param int      $term_id      Main-blog artist term ID.
 * @param int|null $main_blog_id Optional resolved main blog ID.
 * @return bool Whether the pair is reciprocal or was safely completed.
 */
function ec_reconcile_artist_profile_term_pair( $profile_id, $term_id, $main_blog_id = null ) {
	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $blog_ids ) ) {
		return false;
	}

	$main_blog_id = null === $main_blog_id ? $blog_ids['main'] : (int) $main_blog_id;
	if ( $main_blog_id !== $blog_ids['main'] ) {
		return false;
	}

	$profile = ec_artist_binding_read_profile( (int) $profile_id, $blog_ids['artist'] );
	$term    = ec_artist_binding_read_term( (int) $term_id, $main_blog_id );
	if ( empty( $profile ) || empty( $term ) ) {
		return false;
	}

	if ( $profile['term_id'] > 0 && $profile['term_id'] !== (int) $term_id ) {
		return false;
	}
	if ( $term['profile_id'] > 0 && $term['profile_id'] !== (int) $profile_id ) {
		return false;
	}

	return ec_bind_artist_profile_to_term( (int) $profile_id, (int) $term_id, $main_blog_id );
}

/**
 * Store or clear the current request's canonical binding failure.
 *
 * @param WP_Error|null $failure Failure to store, or null to clear.
 * @return void
 */
function ec_set_artist_binding_failure( $failure = null ) {
	$GLOBALS['ec_artist_binding_failure'] = $failure instanceof WP_Error ? $failure : null;
}

/**
 * Return the current request's canonical binding failure.
 *
 * @return WP_Error|null
 */
function ec_get_artist_binding_failure() {
	$failure = $GLOBALS['ec_artist_binding_failure'] ?? null;
	return $failure instanceof WP_Error ? $failure : null;
}

/**
 * Persist a validated one-to-one profile <-> term binding.
 *
 * One-sided stale references are replaced. A reciprocal binding owned by a
 * different live entity is a collision and is left unchanged.
 *
 * @param int      $profile_id   Artist profile post ID.
 * @param int      $term_id      Main-blog artist term ID.
 * @param int|null $main_blog_id Optional resolved main blog ID.
 * @return bool Whether the requested binding is valid and persisted.
 */
function ec_bind_artist_profile_to_term( $profile_id, $term_id, $main_blog_id = null ) {
	ec_set_artist_binding_failure();
	$profile_id = (int) $profile_id;
	$term_id    = (int) $term_id;
	$blog_ids   = ec_artist_binding_blog_ids();
	if ( $profile_id <= 0 || $term_id <= 0 || empty( $blog_ids ) ) {
		return false;
	}

	$artist_blog_id = $blog_ids['artist'];
	$main_blog_id   = null === $main_blog_id ? $blog_ids['main'] : (int) $main_blog_id;
	if ( $main_blog_id !== $blog_ids['main'] ) {
		return false;
	}

	$profile = ec_artist_binding_read_profile( $profile_id, $artist_blog_id );
	$term    = ec_artist_binding_read_term( $term_id, $main_blog_id );
	if ( empty( $profile ) || empty( $term ) ) {
		return false;
	}

	if ( $term['profile_id'] > 0 && $term['profile_id'] !== $profile_id ) {
		$other_profile = ec_artist_binding_read_profile( $term['profile_id'], $artist_blog_id );
		if ( ! empty( $other_profile ) && $other_profile['term_id'] === $term_id ) {
			return false;
		}

		// The inverse points to a missing profile or one that does not point back.
		ec_artist_binding_delete_term_meta( $term_id, $term['profile_id'], $main_blog_id );
	}

	$old_reciprocal_term_id = 0;
	if ( $profile['term_id'] > 0 && $profile['term_id'] !== $term_id ) {
		$old_term = ec_artist_binding_read_term( $profile['term_id'], $main_blog_id );
		if ( ! empty( $old_term ) && $old_term['profile_id'] === $profile_id ) {
			$old_reciprocal_term_id = (int) $profile['term_id'];
			ec_artist_binding_delete_term_meta( $profile['term_id'], $profile_id, $main_blog_id );
			$old_term = ec_artist_binding_read_term( $profile['term_id'], $main_blog_id );
			if ( ! empty( $old_term ) && $old_term['profile_id'] === $profile_id ) {
				return false;
			}
		}
	}
	$profile = ec_artist_binding_read_profile( $profile_id, $artist_blog_id );
	$term    = ec_artist_binding_read_term( $term_id, $main_blog_id );
	if ( empty( $profile ) || empty( $term ) ) {
		if ( $old_reciprocal_term_id > 0 ) {
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				switch_to_blog( $main_blog_id );
				try {
					update_term_meta( $old_reciprocal_term_id, '_artist_profile_id', $profile_id );
				} finally {
					restore_current_blog();
				}
				$old_term = ec_artist_binding_read_term( $old_reciprocal_term_id, $main_blog_id );
				if ( ! empty( $old_term ) && $old_term['profile_id'] === $profile_id ) {
					break;
				}
			}
			$restored_old_term = ec_artist_binding_read_term( $old_reciprocal_term_id, $main_blog_id );
			if ( empty( $restored_old_term ) || $restored_old_term['profile_id'] !== $profile_id ) {
				ec_set_artist_binding_failure(
					new WP_Error(
						'artist_binding_compensation_failed',
						__( 'Canonical artist binding compensation failed. Manual reconciliation is required.', 'extrachill-artist-platform' ),
						array( 'profile_id' => $profile_id, 'term_id' => $term_id, 'previous_term_id' => $old_reciprocal_term_id, 'retryable' => false )
					)
				);
			}
		}
		return false;
	}
	$previous_term_id    = (int) $profile['term_id'];
	$previous_profile_id = (int) $term['profile_id'];

	switch_to_blog( $artist_blog_id );
	try {
		update_post_meta( $profile_id, '_artist_term_id', $term_id );
	} finally {
		restore_current_blog();
	}

	switch_to_blog( $main_blog_id );
	try {
		update_term_meta( $term_id, '_artist_profile_id', $profile_id );
	} finally {
		restore_current_blog();
	}

	$bound_profile = ec_artist_binding_read_profile( $profile_id, $artist_blog_id );
	$bound_term    = ec_artist_binding_read_term( $term_id, $main_blog_id );
	if ( ! empty( $bound_profile ) && ! empty( $bound_term ) && $bound_profile['term_id'] === $term_id && $bound_term['profile_id'] === $profile_id ) {
		switch_to_blog( $main_blog_id );
		try {
			delete_term_meta( $term_id, '_ec_artist_binding_recoverable' );
		} finally {
			restore_current_blog();
		}
		ec_set_artist_binding_failure();
		return true;
	}

	if ( ! empty( $bound_profile ) && $bound_profile['term_id'] === $term_id ) {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			switch_to_blog( $artist_blog_id );
			try {
				if ( $previous_term_id > 0 ) {
					update_post_meta( $profile_id, '_artist_term_id', $previous_term_id );
				} else {
					delete_post_meta( $profile_id, '_artist_term_id', $term_id );
				}
			} finally {
				restore_current_blog();
			}
			$rolled_profile = ec_artist_binding_read_profile( $profile_id, $artist_blog_id );
			if ( ! empty( $rolled_profile ) && $rolled_profile['term_id'] === $previous_term_id ) {
				break;
			}
		}
	}
	if ( ! empty( $bound_term ) && $bound_term['profile_id'] === $profile_id ) {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			switch_to_blog( $main_blog_id );
			try {
				if ( $previous_profile_id > 0 ) {
					update_term_meta( $term_id, '_artist_profile_id', $previous_profile_id );
				} else {
					delete_term_meta( $term_id, '_artist_profile_id', $profile_id );
				}
			} finally {
				restore_current_blog();
			}
			$rolled_term = ec_artist_binding_read_term( $term_id, $main_blog_id );
			if ( ! empty( $rolled_term ) && $rolled_term['profile_id'] === $previous_profile_id ) {
				break;
			}
		}
	}
	$rolled_profile = ec_artist_binding_read_profile( $profile_id, $artist_blog_id );
	if ( $old_reciprocal_term_id > 0 && ! empty( $rolled_profile ) && $rolled_profile['term_id'] === $old_reciprocal_term_id ) {
		switch_to_blog( $main_blog_id );
		try {
			update_term_meta( $old_reciprocal_term_id, '_artist_profile_id', $profile_id );
		} finally {
			restore_current_blog();
		}
	}

	$final_profile = ec_artist_binding_read_profile( $profile_id, $artist_blog_id );
	$final_term    = ec_artist_binding_read_term( $term_id, $main_blog_id );
	$final_old_term = $old_reciprocal_term_id > 0 ? ec_artist_binding_read_term( $old_reciprocal_term_id, $main_blog_id ) : array();
	$profile_restored = ! empty( $final_profile ) && $final_profile['term_id'] === $previous_term_id;
	$term_restored    = ! empty( $final_term ) && $final_term['profile_id'] === $previous_profile_id;
	$old_term_restored = 0 === $old_reciprocal_term_id || ( ! empty( $final_old_term ) && $final_old_term['profile_id'] === $profile_id );
	if ( ! $profile_restored || ! $term_restored || ! $old_term_restored ) {
		ec_set_artist_binding_failure(
			new WP_Error(
				'artist_binding_compensation_failed',
				__( 'Canonical artist binding compensation failed. Manual reconciliation is required.', 'extrachill-artist-platform' ),
				array(
					'profile_id'       => $profile_id,
					'term_id'          => $term_id,
					'previous_term_id' => $previous_term_id,
					'retryable'        => false,
				)
			)
		);
	}

	return false;
}

/**
 * Resolve the main-blog artist term bound to an artist profile.
 *
 * @param int $profile_id Artist profile post ID.
 * @return int Main-blog artist term ID, or 0.
 */
function ec_get_artist_term_id( $profile_id ) {
	ec_set_artist_binding_failure();
	$profile_id = (int) $profile_id;
	$blog_ids   = ec_artist_binding_blog_ids();
	if ( $profile_id <= 0 || empty( $blog_ids ) ) {
		return 0;
	}

	$profile = ec_artist_binding_read_profile( $profile_id, $blog_ids['artist'] );
	if ( empty( $profile ) ) {
		return 0;
	}

	if ( $profile['term_id'] > 0 ) {
		if ( ec_reconcile_artist_profile_term_pair( $profile_id, $profile['term_id'], $blog_ids['main'] ) ) {
			return $profile['term_id'];
		}
		if ( ec_get_artist_binding_failure() ) {
			return 0;
		}
		ec_artist_binding_delete_profile_meta( $profile_id, $profile['term_id'], $blog_ids['artist'] );
	}

	if ( '' === $profile['slug'] ) {
		return 0;
	}

	$term_id = 0;
	switch_to_blog( $blog_ids['main'] );
	try {
		$term = get_term_by( 'slug', $profile['slug'], 'artist' );
		if ( $term && ! is_wp_error( $term ) ) {
			$term_id = (int) $term->term_id;
		}
	} finally {
		restore_current_blog();
	}

	if ( $term_id > 0 && ec_reconcile_artist_profile_term_pair( $profile_id, $term_id, $blog_ids['main'] ) ) {
		return $term_id;
	}
	return 0;
}

/**
 * Resolve the artist-blog profile bound to a main-blog artist term.
 *
 * @param int $term_id Main-blog artist term ID.
 * @return int Artist profile post ID, or 0.
 */
function ec_get_artist_profile_id( $term_id ) {
	ec_set_artist_binding_failure();
	$term_id  = (int) $term_id;
	$blog_ids = ec_artist_binding_blog_ids();
	if ( $term_id <= 0 || empty( $blog_ids ) ) {
		return 0;
	}

	$term = ec_artist_binding_read_term( $term_id, $blog_ids['main'] );
	if ( empty( $term ) ) {
		return 0;
	}

	if ( $term['profile_id'] > 0 ) {
		if ( ec_reconcile_artist_profile_term_pair( $term['profile_id'], $term_id, $blog_ids['main'] ) ) {
			return $term['profile_id'];
		}
		if ( ec_get_artist_binding_failure() ) {
			return 0;
		}
		ec_artist_binding_delete_term_meta( $term_id, $term['profile_id'], $blog_ids['main'] );
	}

	if ( '' === $term['slug'] ) {
		return 0;
	}

	$profile_id = 0;
	switch_to_blog( $blog_ids['artist'] );
	try {
		$found = get_posts(
			array(
				'post_type'        => 'artist_profile',
				'name'             => $term['slug'],
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		if ( ! empty( $found ) ) {
			$profile_id = (int) $found[0];
		}
	} finally {
		restore_current_blog();
	}

	if ( $profile_id > 0 && ec_reconcile_artist_profile_term_pair( $profile_id, $term_id, $blog_ids['main'] ) ) {
		return $profile_id;
	}
	return 0;
}

/**
 * Ensure an artist profile is bound to a matching main-blog artist term.
 *
 * @param int $profile_id Artist profile post ID.
 * @return int|WP_Error Bound artist term ID, or an actionable failure.
 */
function ec_sync_artist_profile_term_binding( $profile_id ) {
	$profile_id = (int) $profile_id;
	$blog_ids   = ec_artist_binding_blog_ids();
	if ( $profile_id <= 0 || empty( $blog_ids ) ) {
		return new WP_Error( 'artist_identity_unavailable', __( 'Artist identity binding is unavailable.', 'extrachill-artist-platform' ) );
	}

	$profile = ec_artist_binding_read_profile( $profile_id, $blog_ids['artist'] );
	if ( empty( $profile ) ) {
		return new WP_Error( 'invalid_artist_profile', __( 'The artist profile is unavailable.', 'extrachill-artist-platform' ) );
	}
	$existing_term_id = ec_get_artist_term_id( $profile_id );
	$binding_failure  = ec_get_artist_binding_failure();
	if ( $binding_failure ) {
		return $binding_failure;
	}
	if ( $existing_term_id > 0 ) {
		return $existing_term_id;
	}

	if ( '' === $profile['title'] || '' === $profile['slug'] ) {
		return new WP_Error( 'invalid_artist_identity', __( 'The artist profile needs a title and slug before binding.', 'extrachill-artist-platform' ) );
	}

	$new_term_id = 0;
	$term_created = false;
	switch_to_blog( $blog_ids['main'] );
	try {
		$existing = get_term_by( 'slug', $profile['slug'], 'artist' );
		if ( $existing && ! is_wp_error( $existing ) ) {
			$new_term_id = (int) $existing->term_id;
		} else {
			$inserted = wp_insert_term( $profile['title'], 'artist', array( 'slug' => $profile['slug'] ) );
			if ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) {
				$new_term_id  = (int) $inserted['term_id'];
				$term_created = true;
			}
		}
	} finally {
		restore_current_blog();
	}

	if ( $new_term_id <= 0 ) {
		return new WP_Error( 'artist_term_creation_failed', __( 'The canonical artist term could not be created.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
	}
	if ( ec_bind_artist_profile_to_term( $profile_id, $new_term_id, $blog_ids['main'] ) ) {
		return $new_term_id;
	}
	$binding_failure = ec_get_artist_binding_failure();
	if ( $binding_failure ) {
		return $binding_failure;
	}

	if ( $term_created ) {
		$deleted = false;
		$recoverable = false;
		$delete_state_mismatch = false;
		switch_to_blog( $blog_ids['main'] );
		try {
			$created_term = get_term( $new_term_id, 'artist' );
			$bound_profile_id = (int) get_term_meta( $new_term_id, '_artist_profile_id', true );
			if ( $created_term && ! is_wp_error( $created_term ) && 0 === (int) ( $created_term->count ?? 0 ) && 0 === $bound_profile_id ) {
				$delete_result = wp_delete_term( $new_term_id, 'artist' );
				$delete_reported = ! is_wp_error( $delete_result ) && (bool) $delete_result;
				$deleted_term    = get_term( $new_term_id, 'artist' );
				$deleted         = $delete_reported && ( ! $deleted_term || is_wp_error( $deleted_term ) );
				$delete_state_mismatch = $delete_reported && ! $deleted;
				if ( ! $deleted && ! $delete_state_mismatch ) {
					$created_term = get_term( $new_term_id, 'artist' );
					$bound_profile_id = (int) get_term_meta( $new_term_id, '_artist_profile_id', true );
					if ( $created_term && ! is_wp_error( $created_term ) && 0 === (int) ( $created_term->count ?? 0 ) && 0 === $bound_profile_id ) {
						update_term_meta( $new_term_id, '_ec_artist_binding_recoverable', $profile['slug'] );
						$recoverable = $profile['slug'] === (string) get_term_meta( $new_term_id, '_ec_artist_binding_recoverable', true );
					}
				}
			}
		} finally {
			restore_current_blog();
		}
		if ( $delete_state_mismatch ) {
			return new WP_Error( 'artist_term_binding_rollback_failed', __( 'Canonical term deletion reported success without removing the term. Manual reconciliation is required.', 'extrachill-artist-platform' ), array( 'term_id' => $new_term_id, 'retryable' => false ) );
		}
		if ( ! $deleted && ! $recoverable ) {
			return new WP_Error( 'artist_term_binding_rollback_failed', __( 'Canonical artist binding failed and its new empty term could not be removed.', 'extrachill-artist-platform' ), array( 'term_id' => $new_term_id, 'retryable' => false ) );
		}
	}

	return new WP_Error( 'artist_term_binding_failed', __( 'The artist profile could not be bound to its canonical artist term.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
}
add_action( 'ec_artist_profile_save', 'ec_sync_artist_profile_term_binding', 5, 1 );

/**
 * Remove all term references before an artist profile is deleted.
 *
 * @param int $profile_id Post ID being deleted.
 * @return void
 */
function ec_delete_artist_profile_term_binding( $profile_id ) {
	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $blog_ids ) || get_current_blog_id() !== $blog_ids['artist'] ) {
		return;
	}

	$profile = ec_artist_binding_read_profile( (int) $profile_id, $blog_ids['artist'] );
	if ( empty( $profile ) ) {
		return;
	}

	switch_to_blog( $blog_ids['main'] );
	try {
		$batch_size        = 100;
		$offset            = 0;
		$profile_id_string = (string) (int) $profile_id;
		do {
			$term_ids = get_terms(
				array(
					'taxonomy'               => 'artist',
					'hide_empty'             => false,
					'fields'                 => 'ids',
					'number'                 => $batch_size,
					'offset'                 => $offset,
					'orderby'                => 'term_id',
					'order'                  => 'ASC',
					'cache_results'          => false,
					'update_term_meta_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Deletion must find every stale inverse reference.
					'meta_query'             => array(
						array(
							'key'     => '_artist_profile_id',
							'value'   => (int) $profile_id,
							'compare' => '=',
							'type'    => 'NUMERIC',
						),
					),
				)
			);
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				break;
			}

			$deleted = false;
			foreach ( $term_ids as $term_id ) {
				$stored_values = get_term_meta( (int) $term_id, '_artist_profile_id', false );
				foreach ( $stored_values as $stored_value ) {
					if ( ! is_int( $stored_value ) && ! is_string( $stored_value ) ) {
						continue;
					}
					$stored_value_string = (string) $stored_value;
					if ( 1 !== preg_match( '/^\+?\d+$/D', $stored_value_string ) ) {
						continue;
					}
					$normalized_value = ltrim( ltrim( $stored_value_string, '+' ), '0' );
					$normalized_value = '' === $normalized_value ? '0' : $normalized_value;
					if ( $normalized_value === $profile_id_string ) {
						$deleted = delete_term_meta( (int) $term_id, '_artist_profile_id', $stored_value ) || $deleted;
					}
				}
			}

			if ( ! $deleted ) {
				$offset += count( $term_ids );
			}
		} while ( true );
	} finally {
		restore_current_blog();
	}
}
add_action( 'before_delete_post', 'ec_delete_artist_profile_term_binding', 10, 1 );

// Main-site term deletion is owned by the network-active runtime; see
// Extra-Chill/extrachill-network#143. Resolvers remain fail-closed meanwhile.

/**
 * Idempotent, run-once backfill of profile <-> term bindings.
 *
 * @return void
 */
function ec_backfill_artist_term_bindings() {
	$backfill_version = '2.0.0';
	$option_key       = 'extrachill_artist_platform_term_binding_integrity_backfill';
	$stored           = get_option( $option_key, '0' );
	if ( version_compare( $stored, $backfill_version, '>=' ) ) {
		return;
	}

	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $blog_ids ) ) {
		return;
	}

	switch_to_blog( $blog_ids['artist'] );
	try {
		$profile_ids = get_posts(
			array(
				'post_type'        => 'artist_profile',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
	} finally {
		restore_current_blog();
	}

	foreach ( (array) $profile_ids as $profile_id ) {
		ec_get_artist_term_id( (int) $profile_id );
	}

	update_option( $option_key, $backfill_version );
}
add_action( 'admin_init', 'ec_backfill_artist_term_bindings' );
