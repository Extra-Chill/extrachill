<?php
/**
 * Artist Profile <-> Artist Term Binding ("the hub join").
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Acquire the network-wide canonical binding lock.
 *
 * The lock covers every profile/term pair, including absent metadata rows.
 * This lock is always first. Transactions, domain locks, profile/term locks,
 * and the distinct artist membership lock are subordinate and are released
 * before this lock. Consumers acquire outside a transaction, start and finish
 * subordinate transaction/domain/member work, then release this lock.
 * REPEATABLE READ is the intentional isolation level for that subordinate
 * transaction, not merely a transaction-state probe.
 *
 * @return string|WP_Error Opaque lock token or a stable failure.
 */
function ec_acquire_artist_binding_lock() {
	global $wpdb;
	$lock_name = 'ec_artist_binding_v1';
	if ( ! empty( $GLOBALS['ec_artist_binding_lock'] ) || ! empty( $GLOBALS['ec_artist_binding_lock_pending'] ) || preg_match( '/sqlite|pgsql|postgres/', strtolower( get_class( $wpdb ) ) ) ) {
		return new WP_Error( 'artist_binding_lock_unsupported', __( 'Canonical artist binding serialization is unavailable.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
	}
	$GLOBALS['ec_artist_binding_lock_pending'] = true;
	try {
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One network-wide lock covers both multisite metadata tables and absent rows.
	} finally {
		unset( $GLOBALS['ec_artist_binding_lock_pending'] );
	}
	if ( '1' !== (string) $result ) {
		$code = '0' === (string) $result ? 'artist_binding_busy' : 'artist_binding_lock_failed';
		return new WP_Error( $code, __( 'Canonical artist binding is temporarily unavailable.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
	}
	$GLOBALS['ec_artist_binding_lock'] = $lock_name;
	$handed_off                        = false;
	try {
		$boundary = ec_artist_binding_prepare_mutation_boundary();
		if ( is_wp_error( $boundary ) ) {
			ec_artist_binding_defer_lock_release( 'boundary', $lock_name );
			$handed_off = true;
			return $boundary;
		}
		$handed_off = true;
		return $lock_name;
	} finally {
		if ( ! $handed_off ) {
			$release = ec_release_artist_binding_lock( $lock_name );
			if ( is_wp_error( $release ) ) {
				ec_set_artist_binding_release_failure( $release );
			}
		}
	}
}

/**
 * Release the canonical binding lock.
 *
 * Failed releases remain tracked so the connection is never treated as clean.
 *
 * @param string $lock_token Opaque lock token.
 * @return true|WP_Error True on release or a stable failure.
 */
function ec_release_artist_binding_lock( $lock_token ) {
	global $wpdb;
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) || $GLOBALS['ec_artist_binding_lock'] !== $lock_token ) {
		return new WP_Error( 'artist_binding_lock_invalid', __( 'Canonical artist binding lock ownership is invalid.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
	}
	$boundary = ec_artist_binding_prepare_mutation_boundary();
	if ( is_wp_error( $boundary ) ) {
		return new WP_Error( 'artist_binding_release_transaction_active', __( 'Canonical artist binding cannot be released before subordinate transaction work finishes.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
	}
	$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the exact connection-owned lock acquired above.
	if ( '1' !== (string) $released ) {
		return new WP_Error( 'artist_binding_release_failed', __( 'Canonical artist binding lock cleanup failed.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
	}
	unset( $GLOBALS['ec_artist_binding_lock'] );
	foreach ( (array) ( $GLOBALS['ec_artist_binding_deferred_locks'] ?? array() ) as $key => $deferred_lock ) {
		if ( $deferred_lock === $lock_token ) {
			unset( $GLOBALS['ec_artist_binding_deferred_locks'][ $key ] );
		}
	}
	return true;
}

/**
 * Set the intentional isolation level and prove the connection boundary.
 *
 * MySQL 8.4 and MariaDB 10.11 both reject this statement inside an active
 * transaction. On success it configures the next transaction as REPEATABLE
 * READ. Error display is restored exactly to its previous state.
 *
 * @return true|WP_Error True outside a transaction or a stable failure.
 */
function ec_artist_binding_prepare_mutation_boundary() {
	global $wpdb;
	if ( preg_match( '/sqlite|pgsql|postgres/', strtolower( get_class( $wpdb ) ) ) ) {
		return new WP_Error( 'artist_binding_transaction_state_unsupported', __( 'Canonical artist binding transaction state is unavailable.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
	}
	$previous_suppression = $wpdb->suppress_errors( true );
	try {
		$accepted = $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Portable MySQL/MariaDB transaction-boundary probe.
	} finally {
		$wpdb->suppress_errors( $previous_suppression );
	}
	return false === $accepted
		? new WP_Error( 'artist_binding_nested_transaction', __( 'Canonical artist binding cannot inherit a caller-owned transaction.', 'extrachill-artist-platform' ), array( 'retryable' => false ) )
		: true;
}

/**
 * Retain a lock until shutdown when transaction ownership prevents release.
 *
 * @param string $key        Deferred lock context key.
 * @param string $lock_token Opaque lock token.
 * @return void
 */
function ec_artist_binding_defer_lock_release( $key, $lock_token ) {
	$GLOBALS['ec_artist_binding_deferred_locks'][ $key ] = $lock_token;
	if ( empty( $GLOBALS['ec_artist_binding_shutdown_registered'] ) ) {
		register_shutdown_function( 'ec_artist_binding_release_deferred_locks' );
		$GLOBALS['ec_artist_binding_shutdown_registered'] = true;
	}
}

/** Release locks whose caller-owned transaction has ended by shutdown. */
function ec_artist_binding_release_deferred_locks() {
	foreach ( (array) ( $GLOBALS['ec_artist_binding_deferred_locks'] ?? array() ) as $key => $lock ) {
		if ( ec_finish_artist_binding_lock( $lock ) ) {
			unset( $GLOBALS['ec_artist_binding_deferred_locks'][ $key ] );
		}
	}
}

/**
 * Store a release failure without replacing the primary failure.
 *
 * @param WP_Error|null $failure Release failure or null to clear.
 * @return void
 */
function ec_set_artist_binding_release_failure( $failure = null ) {
	$GLOBALS['ec_artist_binding_release_failure'] = $failure instanceof WP_Error ? $failure : null;
}

/**
 * Return the last canonical binding release failure.
 *
 * @return WP_Error|null Last canonical binding release failure.
 */
function ec_get_artist_binding_release_failure() {
	$failure = $GLOBALS['ec_artist_binding_release_failure'] ?? null;
	return $failure instanceof WP_Error ? $failure : null;
}

/**
 * Record release outcome while retaining any primary operation failure.
 *
 * @param string $lock_token Opaque lock token.
 * @return bool Whether release succeeded.
 */
function ec_finish_artist_binding_lock( $lock_token ) {
	$release = ec_release_artist_binding_lock( $lock_token );
	if ( ! is_wp_error( $release ) ) {
		return true;
	}
	ec_set_artist_binding_release_failure( $release );
	if ( ! ec_get_artist_binding_failure() ) {
		ec_set_artist_binding_failure( $release );
	}
	return false;
}

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
 * Invalidate profile caches before an authoritative locked read.
 *
 * @param int $profile_id    Artist profile post ID.
 * @param int $artist_blog_id Artist blog ID.
 * @return void
 */
function ec_artist_binding_invalidate_profile_cache( $profile_id, $artist_blog_id ) {
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) ) {
		return;
	}
	switch_to_blog( $artist_blog_id );
	try {
		clean_post_cache( (int) $profile_id );
		wp_cache_delete( (int) $profile_id, 'post_meta' );
	} finally {
		restore_current_blog();
	}
}

/**
 * Invalidate term caches before an authoritative locked read.
 *
 * @param int $term_id      Artist term ID.
 * @param int $main_blog_id Main blog ID.
 * @return void
 */
function ec_artist_binding_invalidate_term_cache( $term_id, $main_blog_id ) {
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) ) {
		return;
	}
	switch_to_blog( $main_blog_id );
	try {
		clean_term_cache( (int) $term_id, 'artist' );
		wp_cache_delete( (int) $term_id, 'term_meta' );
	} finally {
		restore_current_blog();
	}
}

/** Invalidate slug lookup caches while the global binding lock is held. */
function ec_artist_binding_invalidate_slug_caches() {
	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) || empty( $blog_ids ) ) {
		return;
	}
	switch_to_blog( $blog_ids['main'] );
	try {
		clean_taxonomy_cache( 'artist' );
	} finally {
		restore_current_blog();
	}
	switch_to_blog( $blog_ids['artist'] );
	try {
		wp_cache_delete( 'last_changed', 'posts' );
	} finally {
		restore_current_blog();
	}
}

/**
 * Read and validate an artist profile from its owning blog.
 *
 * @param int $profile_id    Artist profile post ID.
 * @param int $artist_blog_id Artist blog ID.
 * @return array{id:int,term_id:int,slug:string,title:string,status:string}|array{}
 */
function ec_artist_binding_read_profile( $profile_id, $artist_blog_id ) {
	$profile = array();
	ec_artist_binding_invalidate_profile_cache( $profile_id, $artist_blog_id );
	switch_to_blog( $artist_blog_id );
	try {
		$post = get_post( $profile_id );
		if ( $post && 'artist_profile' === $post->post_type ) {
			$profile = array(
				'id'      => (int) $post->ID,
				'term_id' => (int) get_post_meta( $profile_id, '_artist_term_id', true ),
				'slug'    => (string) $post->post_name,
				'title'   => (string) $post->post_title,
				'status'  => (string) $post->post_status,
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
	ec_artist_binding_invalidate_term_cache( $term_id, $main_blog_id );
	switch_to_blog( $main_blog_id );
	try {
		$term = get_term( $term_id, 'artist' );
		if ( $term && ! is_wp_error( $term ) && 'artist' === $term->taxonomy ) {
			$artist_term = array(
				'id'         => (int) $term->term_id,
				'profile_id' => (int) get_term_meta( $term_id, '_artist_profile_id', true ),
				'slug'       => (string) $term->slug,
				'count'      => ec_artist_binding_term_count( $term ),
			);
		}
	} finally {
		restore_current_blog();
	}

	return $artist_term;
}

/**
 * Check whether a defensive binding re-read still resolved an entity.
 *
 * @param mixed $entity Profile or term data.
 * @return bool
 */
function ec_artist_binding_entity_exists( $entity ) {
	return is_array( $entity ) && ! empty( $entity );
}

/**
 * Read a term count while tolerating partial term objects.
 *
 * @param mixed $term Term object.
 * @return int
 */
function ec_artist_binding_term_count( $term ) {
	return is_object( $term ) && isset( $term->count ) ? (int) $term->count : 0;
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
	ec_set_artist_binding_failure();
	ec_set_artist_binding_release_failure();
	$lock = ec_acquire_artist_binding_lock();
	if ( is_wp_error( $lock ) ) {
		ec_set_artist_binding_failure( $lock );
		return false;
	}
	$result = false;
	try {
		$result = ec_reconcile_artist_profile_term_pair_locked( $profile_id, $term_id, $main_blog_id );
	} finally {
		$released = ec_finish_artist_binding_lock( $lock );
	}
	return $result && $released;
}

/**
 * Fill only a missing side of the current pair while holding the binding lock.
 *
 * @param int      $profile_id   Artist profile post ID.
 * @param int      $term_id      Main-blog artist term ID.
 * @param int|null $main_blog_id Optional resolved main blog ID.
 * @return bool Whether the pair is reciprocal or was safely completed.
 */
function ec_reconcile_artist_profile_term_pair_locked( $profile_id, $term_id, $main_blog_id = null ) {
	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) || empty( $blog_ids ) ) {
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

	$wrote_profile = false;
	$wrote_term    = false;
	if ( 0 === $profile['term_id'] ) {
		switch_to_blog( $blog_ids['artist'] );
		try {
			update_post_meta( (int) $profile_id, '_artist_term_id', (int) $term_id );
		} finally {
			restore_current_blog();
		}
		$current_profile = ec_artist_binding_read_profile( (int) $profile_id, $blog_ids['artist'] );
		$wrote_profile   = ! empty( $current_profile ) && (int) $current_profile['term_id'] === (int) $term_id;
	}
	if ( 0 === $term['profile_id'] ) {
		switch_to_blog( $main_blog_id );
		try {
			update_term_meta( (int) $term_id, '_artist_profile_id', (int) $profile_id );
		} finally {
			restore_current_blog();
		}
		$current_term = ec_artist_binding_read_term( (int) $term_id, $main_blog_id );
		$wrote_term   = ! empty( $current_term ) && (int) $current_term['profile_id'] === (int) $profile_id;
	}
	$profile = ec_artist_binding_read_profile( (int) $profile_id, $blog_ids['artist'] );
	$term    = ec_artist_binding_read_term( (int) $term_id, $main_blog_id );
	if ( ! empty( $profile ) && ! empty( $term ) && (int) $profile['term_id'] === (int) $term_id && (int) $term['profile_id'] === (int) $profile_id ) {
		switch_to_blog( $main_blog_id );
		try {
			delete_term_meta( (int) $term_id, '_ec_artist_binding_recoverable' );
		} finally {
			restore_current_blog();
		}
		return true;
	}
	if ( $wrote_profile ) {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			ec_artist_binding_delete_profile_meta( (int) $profile_id, (int) $term_id, $blog_ids['artist'] );
			$current_profile = ec_artist_binding_read_profile( (int) $profile_id, $blog_ids['artist'] );
			if ( empty( $current_profile ) || (int) $current_profile['term_id'] !== (int) $term_id ) {
				break;
			}
		}
	}
	if ( $wrote_term ) {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			ec_artist_binding_delete_term_meta( (int) $term_id, (int) $profile_id, $main_blog_id );
			$current_term = ec_artist_binding_read_term( (int) $term_id, $main_blog_id );
			if ( empty( $current_term ) || (int) $current_term['profile_id'] !== (int) $profile_id ) {
				break;
			}
		}
	}
	$current_profile = ec_artist_binding_read_profile( (int) $profile_id, $blog_ids['artist'] );
	$current_term    = ec_artist_binding_read_term( (int) $term_id, $main_blog_id );
	if ( ( $wrote_profile && ! empty( $current_profile ) && (int) $current_profile['term_id'] === (int) $term_id ) || ( $wrote_term && ! empty( $current_term ) && (int) $current_term['profile_id'] === (int) $profile_id ) ) {
		ec_set_artist_binding_failure(
			new WP_Error(
				'artist_binding_compensation_failed',
				__( 'Canonical artist binding compensation failed. Manual reconciliation is required.', 'extrachill-artist-platform' ),
				array(
					'profile_id' => (int) $profile_id,
					'term_id'    => (int) $term_id,
					'retryable'  => false,
				)
			)
		);
	}
	return false;
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
 * Read one reciprocal pair while the caller owns the canonical binding lock.
 *
 * This is the downstream consumer contract. It does not repair state and is
 * safe to call inside a consumer-owned transaction after acquiring the lock.
 *
 * @param int $profile_id Artist profile post ID.
 * @param int $term_id    Main-blog artist term ID.
 * @return array{profile_id:int,term_id:int}|WP_Error Valid reciprocal pair.
 */
function ec_read_locked_artist_binding( $profile_id, $term_id ) {
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) ) {
		return new WP_Error( 'artist_binding_lock_required', __( 'Canonical artist binding must be locked before it is read.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
	}
	$blog_ids = ec_artist_binding_blog_ids();
	$profile  = empty( $blog_ids ) ? array() : ec_artist_binding_read_profile( (int) $profile_id, $blog_ids['artist'] );
	$term     = empty( $blog_ids ) ? array() : ec_artist_binding_read_term( (int) $term_id, $blog_ids['main'] );
	if ( empty( $profile ) || empty( $term ) || (int) $profile['term_id'] !== (int) $term_id || (int) $term['profile_id'] !== (int) $profile_id ) {
		return new WP_Error( 'artist_binding_invalid', __( 'The canonical artist binding is missing or invalid.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
	}
	return array(
		'profile_id' => (int) $profile_id,
		'term_id'    => (int) $term_id,
	);
}

/**
 * Remove one stale reciprocal reference under canonical serialization.
 *
 * @param string $side        Metadata side: profile or term.
 * @param int    $object_id   Profile or term ID.
 * @param int    $expected_id Expected reciprocal ID.
 * @return bool Whether the stale value is absent afterward.
 */
function ec_remove_stale_artist_binding_reference( $side, $object_id, $expected_id ) {
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) || ( 'profile' !== $side && 'term' !== $side ) ) {
		return false;
	}
	$blog_ids = ec_artist_binding_blog_ids();
	if ( 'profile' === $side ) {
		$current = ec_artist_binding_read_profile( $object_id, $blog_ids['artist'] );
		if ( ! empty( $current ) && (int) $current['term_id'] === (int) $expected_id ) {
			ec_artist_binding_delete_profile_meta( $object_id, $expected_id, $blog_ids['artist'] );
		}
		$current = ec_artist_binding_read_profile( $object_id, $blog_ids['artist'] );
		$clean   = empty( $current ) || (int) $current['term_id'] !== (int) $expected_id;
	} else {
		$current = ec_artist_binding_read_term( $object_id, $blog_ids['main'] );
		if ( ! empty( $current ) && (int) $current['profile_id'] === (int) $expected_id ) {
			ec_artist_binding_delete_term_meta( $object_id, $expected_id, $blog_ids['main'] );
		}
		$current = ec_artist_binding_read_term( $object_id, $blog_ids['main'] );
		$clean   = empty( $current ) || (int) $current['profile_id'] !== (int) $expected_id;
	}
	return $clean;
}

/**
 * Run the canonical binding writer outside caller-owned transactions.
 *
 * @param int      $profile_id   Artist profile post ID.
 * @param int      $term_id      Main-blog artist term ID.
 * @param int|null $main_blog_id Optional resolved main blog ID.
 * @return bool Whether the requested binding was persisted and unlocked.
 */
function ec_bind_artist_profile_to_term( $profile_id, $term_id, $main_blog_id = null ) {
	ec_set_artist_binding_failure();
	ec_set_artist_binding_release_failure();
	$lock = ec_acquire_artist_binding_lock();
	if ( is_wp_error( $lock ) ) {
		ec_set_artist_binding_failure( $lock );
		return false;
	}

	$result = false;
	try {
		$result = ec_bind_artist_profile_to_term_locked( $profile_id, $term_id, $main_blog_id );
	} finally {
		$released = ec_finish_artist_binding_lock( $lock );
	}
	return $result && $released;
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
function ec_bind_artist_profile_to_term_locked( $profile_id, $term_id, $main_blog_id = null ) {
	ec_set_artist_binding_failure();
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) ) {
		ec_set_artist_binding_failure( new WP_Error( 'artist_binding_lock_required', __( 'Canonical artist binding must be locked before it is changed.', 'extrachill-artist-platform' ), array( 'retryable' => false ) ) );
		return false;
	}
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
			if ( ec_artist_binding_entity_exists( $old_term ) && $old_term['profile_id'] === $profile_id ) {
				return false;
			}
		}
	}
	$profile = ec_artist_binding_read_profile( $profile_id, $artist_blog_id );
	$term    = ec_artist_binding_read_term( $term_id, $main_blog_id );
	if ( ! ec_artist_binding_entity_exists( $profile ) || ! ec_artist_binding_entity_exists( $term ) ) {
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
						array(
							'profile_id'       => $profile_id,
							'term_id'          => $term_id,
							'previous_term_id' => $old_reciprocal_term_id,
							'retryable'        => false,
						)
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
	if ( ec_artist_binding_entity_exists( $bound_profile ) && ec_artist_binding_entity_exists( $bound_term ) && $bound_profile['term_id'] === $term_id && $bound_term['profile_id'] === $profile_id ) {
		switch_to_blog( $main_blog_id );
		try {
			delete_term_meta( $term_id, '_ec_artist_binding_recoverable' );
		} finally {
			restore_current_blog();
		}
		ec_set_artist_binding_failure();
		return true;
	}

	// Compensation preserves the mutation order: old term, profile, new term.
	if ( $old_reciprocal_term_id > 0 ) {
		switch_to_blog( $main_blog_id );
		try {
			update_term_meta( $old_reciprocal_term_id, '_artist_profile_id', $profile_id );
		} finally {
			restore_current_blog();
		}
	}
	if ( ec_artist_binding_entity_exists( $bound_profile ) && $bound_profile['term_id'] === $term_id ) {
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
			if ( ec_artist_binding_entity_exists( $rolled_profile ) && $rolled_profile['term_id'] === $previous_term_id ) {
				break;
			}
		}
	}
	if ( ec_artist_binding_entity_exists( $bound_term ) && $bound_term['profile_id'] === $profile_id ) {
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
			if ( ec_artist_binding_entity_exists( $rolled_term ) && $rolled_term['profile_id'] === $previous_profile_id ) {
				break;
			}
		}
	}
	$final_profile     = ec_artist_binding_read_profile( $profile_id, $artist_blog_id );
	$final_term        = ec_artist_binding_read_term( $term_id, $main_blog_id );
	$final_old_term    = $old_reciprocal_term_id > 0 ? ec_artist_binding_read_term( $old_reciprocal_term_id, $main_blog_id ) : array();
	$profile_restored  = ec_artist_binding_entity_exists( $final_profile ) && $final_profile['term_id'] === $previous_term_id;
	$term_restored     = ec_artist_binding_entity_exists( $final_term ) && $final_term['profile_id'] === $previous_profile_id;
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
	ec_set_artist_binding_release_failure();
	$lock = ec_acquire_artist_binding_lock();
	if ( is_wp_error( $lock ) ) {
		ec_set_artist_binding_failure( $lock );
		return 0;
	}
	$result = 0;
	try {
		$result = ec_get_artist_term_id_locked( $profile_id );
	} finally {
		$released = ec_finish_artist_binding_lock( $lock );
	}
	return $released ? $result : 0;
}

/**
 * Resolve or fill a profile binding while the canonical lock is held.
 *
 * @param int $profile_id Artist profile post ID.
 * @return int Canonical artist term ID or 0.
 */
function ec_get_artist_term_id_locked( $profile_id ) {
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
		if ( ec_reconcile_artist_profile_term_pair_locked( $profile_id, $profile['term_id'], $blog_ids['main'] ) ) {
			return $profile['term_id'];
		}
		if ( ec_get_artist_binding_failure() ) {
			return 0;
		}
		ec_remove_stale_artist_binding_reference( 'profile', $profile_id, $profile['term_id'] );
	}

	if ( '' === $profile['slug'] ) {
		return 0;
	}

	$term_id = 0;
	ec_artist_binding_invalidate_slug_caches();
	switch_to_blog( $blog_ids['main'] );
	try {
		$term = get_term_by( 'slug', $profile['slug'], 'artist' );
		if ( $term ) {
			$term_id = (int) $term->term_id;
		}
	} finally {
		restore_current_blog();
	}

	if ( $term_id > 0 && ec_reconcile_artist_profile_term_pair_locked( $profile_id, $term_id, $blog_ids['main'] ) ) {
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
	ec_set_artist_binding_release_failure();
	$lock = ec_acquire_artist_binding_lock();
	if ( is_wp_error( $lock ) ) {
		ec_set_artist_binding_failure( $lock );
		return 0;
	}
	$result = 0;
	try {
		$result = ec_get_artist_profile_id_locked( $term_id );
	} finally {
		$released = ec_finish_artist_binding_lock( $lock );
	}
	return $released ? $result : 0;
}

/**
 * Resolve or fill a term binding while the canonical lock is held.
 *
 * @param int $term_id Canonical artist term ID.
 * @return int Artist profile post ID or 0.
 */
function ec_get_artist_profile_id_locked( $term_id ) {
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
		if ( ec_reconcile_artist_profile_term_pair_locked( $term['profile_id'], $term_id, $blog_ids['main'] ) ) {
			return $term['profile_id'];
		}
		if ( ec_get_artist_binding_failure() ) {
			return 0;
		}
		ec_remove_stale_artist_binding_reference( 'term', $term_id, $term['profile_id'] );
	}

	if ( '' === $term['slug'] ) {
		return 0;
	}

	$profile_id = 0;
	ec_artist_binding_invalidate_slug_caches();
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

	if ( $profile_id > 0 && ec_reconcile_artist_profile_term_pair_locked( $profile_id, $term_id, $blog_ids['main'] ) ) {
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
	ec_set_artist_binding_failure();
	ec_set_artist_binding_release_failure();
	$lock = ec_acquire_artist_binding_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	$result = new WP_Error( 'artist_term_binding_failed', __( 'The artist profile could not be bound to its canonical artist term.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
	try {
		$result = ec_sync_artist_profile_term_binding_locked( $profile_id );
	} finally {
		$released = ec_finish_artist_binding_lock( $lock );
	}
	if ( $released || is_wp_error( $result ) ) {
		return $result;
	}
	return ec_get_artist_binding_release_failure();
}

/**
 * Ensure a profile binding while holding one lock through term lifecycle.
 *
 * @param int $profile_id Artist profile post ID.
 * @return int|WP_Error Bound artist term ID or failure.
 */
function ec_sync_artist_profile_term_binding_locked( $profile_id ) {
	$profile_id = (int) $profile_id;
	$blog_ids   = ec_artist_binding_blog_ids();
	if ( $profile_id <= 0 || empty( $blog_ids ) ) {
		return new WP_Error( 'artist_identity_unavailable', __( 'Artist identity binding is unavailable.', 'extrachill-artist-platform' ) );
	}

	$profile = ec_artist_binding_read_profile( $profile_id, $blog_ids['artist'] );
	if ( empty( $profile ) ) {
		return new WP_Error( 'invalid_artist_profile', __( 'The artist profile is unavailable.', 'extrachill-artist-platform' ) );
	}
	$existing_term_id = ec_get_artist_term_id_locked( $profile_id );
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

	$new_term_id  = 0;
	$term_created = false;
	ec_artist_binding_invalidate_slug_caches();
	switch_to_blog( $blog_ids['main'] );
	try {
		$existing = get_term_by( 'slug', $profile['slug'], 'artist' );
		if ( $existing ) {
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
	if ( ec_bind_artist_profile_to_term_locked( $profile_id, $new_term_id, $blog_ids['main'] ) ) {
		return $new_term_id;
	}
	/* @var WP_Error|null $binding_failure A failed metadata write can require manual compensation. */
	$binding_failure = ec_get_artist_binding_failure();
	if ( $binding_failure instanceof WP_Error ) {
		return $binding_failure;
	}

	if ( $term_created ) {
		$deleted               = false;
		$recoverable           = false;
		$delete_state_mismatch = false;
		switch_to_blog( $blog_ids['main'] );
		try {
			ec_artist_binding_invalidate_term_cache( $new_term_id, $blog_ids['main'] );
			$created_term     = get_term( $new_term_id, 'artist' );
			$bound_profile_id = (int) get_term_meta( $new_term_id, '_artist_profile_id', true );
			if ( $created_term && ! is_wp_error( $created_term ) && 0 === ec_artist_binding_term_count( $created_term ) && 0 === $bound_profile_id ) {
				$delete_result   = wp_delete_term( $new_term_id, 'artist' );
				$delete_reported = ! is_wp_error( $delete_result ) && (bool) $delete_result;
				/* @var mixed $deleted_term The term may disappear after deletion. */
				ec_artist_binding_invalidate_term_cache( $new_term_id, $blog_ids['main'] );
				$deleted_term          = get_term( $new_term_id, 'artist' );
				$deleted               = $delete_reported && ( ! $deleted_term || is_wp_error( $deleted_term ) );
				$delete_state_mismatch = $delete_reported && ! $deleted;
				if ( ! $deleted && ! $delete_state_mismatch ) {
					/* @var mixed $created_term Re-read because deletion may have changed term state. */
					ec_artist_binding_invalidate_term_cache( $new_term_id, $blog_ids['main'] );
					$created_term     = get_term( $new_term_id, 'artist' );
					$bound_profile_id = (int) get_term_meta( $new_term_id, '_artist_profile_id', true );
					if ( $created_term && ! is_wp_error( $created_term ) && 0 === ec_artist_binding_term_count( $created_term ) && 0 === $bound_profile_id ) {
						update_term_meta( $new_term_id, '_ec_artist_binding_recoverable', $profile['slug'] );
						$recoverable = (string) get_term_meta( $new_term_id, '_ec_artist_binding_recoverable', true ) === $profile['slug'];
					}
				}
			}
		} finally {
			restore_current_blog();
		}
		if ( $delete_state_mismatch ) {
			return new WP_Error(
				'artist_term_binding_rollback_failed',
				__( 'Canonical term deletion reported success without removing the term. Manual reconciliation is required.', 'extrachill-artist-platform' ),
				array(
					'term_id'   => $new_term_id,
					'retryable' => false,
				)
			);
		}
		if ( ! $deleted && ! $recoverable ) {
			return new WP_Error(
				'artist_term_binding_rollback_failed',
				__( 'Canonical artist binding failed and its new empty term could not be removed.', 'extrachill-artist-platform' ),
				array(
					'term_id'   => $new_term_id,
					'retryable' => false,
				)
			);
		}
	}

	return new WP_Error( 'artist_term_binding_failed', __( 'The artist profile could not be bound to its canonical artist term.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
}
add_action( 'ec_artist_profile_save', 'ec_sync_artist_profile_term_binding', 5, 1 );

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the WordPress filter contract.
/**
 * Acquire and retain canonical serialization through core post deletion.
 *
 * @param mixed   $check        Earlier deletion veto value.
 * @param WP_Post $post         Post being deleted.
 * @param bool    $force_delete Whether deletion bypasses trash.
 * @return mixed Null to continue or false to veto safely.
 */
function ec_artist_binding_pre_delete_post( $check, $post, $force_delete ) {
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	ec_set_artist_binding_failure();
	ec_set_artist_binding_release_failure();
	if ( null !== $check || ! is_object( $post ) || 'artist_profile' !== $post->post_type ) {
		return $check;
	}
	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $blog_ids ) || get_current_blog_id() !== $blog_ids['artist'] ) {
		return $check;
	}
	$lock = ec_acquire_artist_binding_lock();
	if ( is_wp_error( $lock ) ) {
		ec_set_artist_binding_failure( $lock );
		return false;
	}
	$retained = false;
	try {
		$cleanup = ec_delete_artist_profile_term_binding_locked( (int) $post->ID );
		if ( is_wp_error( $cleanup ) ) {
			ec_set_artist_binding_failure( $cleanup );
			return false;
		}
		$key = get_current_blog_id() . ':' . (int) $post->ID;
		$GLOBALS['ec_artist_binding_delete_locks'][ $key ] = $lock;
		if ( empty( $GLOBALS['ec_artist_binding_delete_shutdown_registered'] ) ) {
			register_shutdown_function( 'ec_artist_binding_release_delete_locks' );
			$GLOBALS['ec_artist_binding_delete_shutdown_registered'] = true;
		}
		$retained = true;
		return null;
	} finally {
		if ( ! $retained ) {
			ec_finish_artist_binding_lock( $lock );
		}
	}
}

/**
 * Release a retained delete lock after core deletion has completed.
 *
 * @param int     $profile_id Deleted artist profile ID.
 * @param WP_Post $post       Deleted artist profile.
 * @return void
 */
function ec_artist_binding_after_delete_post( $profile_id, $post ) {
	if ( ! is_object( $post ) || 'artist_profile' !== $post->post_type ) {
		return;
	}
	$key = get_current_blog_id() . ':' . (int) $profile_id;
	if ( empty( $GLOBALS['ec_artist_binding_delete_locks'][ $key ] ) ) {
		return;
	}
	$lock = $GLOBALS['ec_artist_binding_delete_locks'][ $key ];
	if ( ec_finish_artist_binding_lock( $lock ) ) {
		unset( $GLOBALS['ec_artist_binding_delete_locks'][ $key ] );
	}
}

/** Release delete locks retained by vetoes, failed deletes, or throwables. */
function ec_artist_binding_release_delete_locks() {
	foreach ( (array) ( $GLOBALS['ec_artist_binding_delete_locks'] ?? array() ) as $key => $lock ) {
		if ( ec_finish_artist_binding_lock( $lock ) ) {
			unset( $GLOBALS['ec_artist_binding_delete_locks'][ $key ] );
		}
	}
}

/**
 * Compare metadata value collections without coercing scalar types or order.
 *
 * @param array $left  First metadata value collection.
 * @param array $right Second metadata value collection.
 * @return bool Whether both collections contain the same exact values.
 */
function ec_artist_binding_meta_values_match( $left, $right ) {
	$normalize = static function ( $value ) {
		return gettype( $value ) . ':' . serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact scalar type comparison prevents unsafe compensation.
	};
	$left      = array_map( $normalize, (array) $left );
	$right     = array_map( $normalize, (array) $right );
	sort( $left, SORT_STRING );
	sort( $right, SORT_STRING );
	return $left === $right;
}

/**
 * Restore inverse values removed before a later deletion cleanup failure.
 *
 * @param array    $journal      Successful inverse removals in mutation order.
 * @param int      $main_blog_id Main blog ID.
 * @param WP_Error $primary      Primary cleanup failure.
 * @return WP_Error Primary failure, or non-retryable compensation failure.
 */
function ec_compensate_artist_binding_delete_cleanup( $journal, $main_blog_id, $primary ) {
	$failed_term_ids = array();
	$failure_stage   = '';
	switch_to_blog( $main_blog_id );
	try {
		foreach ( array_reverse( $journal ) as $entry ) {
			$target   = array_merge( $entry['expected_after'], $entry['values'] );
			$restored = false;
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				ec_artist_binding_invalidate_term_cache( $entry['term_id'], $main_blog_id );
				$current = get_term_meta( $entry['term_id'], '_artist_profile_id', false );
				if ( ec_artist_binding_meta_values_match( $current, $target ) ) {
					$restored = true;
					break;
				}
				if ( ! ec_artist_binding_meta_values_match( $current, $entry['expected_after'] ) ) {
					$failure_stage = 'state_changed';
					break;
				}

				$writes_succeeded = true;
				foreach ( $entry['values'] as $value ) {
					$writes_succeeded = add_term_meta( $entry['term_id'], '_artist_profile_id', $value, false ) && $writes_succeeded;
				}
				ec_artist_binding_invalidate_term_cache( $entry['term_id'], $main_blog_id );
				$verified = get_term_meta( $entry['term_id'], '_artist_profile_id', false );
				if ( ec_artist_binding_meta_values_match( $verified, $target ) ) {
					$restored = true;
					break;
				}
				$failure_stage = $writes_succeeded ? 'verification' : 'write';
			}
			if ( ! $restored ) {
				$failed_term_ids[] = (int) $entry['term_id'];
			}
		}
	} finally {
		restore_current_blog();
	}

	if ( empty( $failed_term_ids ) ) {
		return $primary;
	}
	return new WP_Error(
		'artist_binding_delete_compensation_failed',
		__( 'Canonical artist inverse cleanup compensation failed. Manual reconciliation is required.', 'extrachill-artist-platform' ),
		array(
			'primary_code'      => $primary->get_error_code(),
			'affected_term_ids' => array_values( array_unique( $failed_term_ids ) ),
			'failure_stage'     => $failure_stage,
			'retryable'         => false,
		)
	);
}

/**
 * Remove all term references while the canonical binding lock is held.
 *
 * @param int $profile_id Post ID being deleted.
 * @return true|WP_Error True when every valid inverse claim is absent.
 */
function ec_delete_artist_profile_term_binding_locked( $profile_id ) {
	if ( empty( $GLOBALS['ec_artist_binding_lock'] ) ) {
		return new WP_Error( 'artist_binding_lock_required', __( 'Canonical artist binding must be locked before it is changed.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
	}
	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $blog_ids ) || get_current_blog_id() !== $blog_ids['artist'] ) {
		return new WP_Error( 'artist_binding_delete_context_invalid', __( 'Canonical artist binding deletion is unavailable in this site context.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
	}

	$profile = ec_artist_binding_read_profile( (int) $profile_id, $blog_ids['artist'] );
	if ( empty( $profile ) ) {
		return new WP_Error(
			'artist_binding_delete_profile_unavailable',
			__( 'The artist profile could not be read before deletion.', 'extrachill-artist-platform' ),
			array(
				'profile_id' => (int) $profile_id,
				'retryable'  => true,
			)
		);
	}

	$failure = null;
	$journal = array();
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
			if ( is_wp_error( $term_ids ) ) {
				$failure = new WP_Error(
					'artist_binding_delete_query_failed',
					__( 'Canonical artist inverse references could not be read before deletion.', 'extrachill-artist-platform' ),
					array(
						'profile_id' => (int) $profile_id,
						'retryable'  => true,
					)
				);
				break;
			}
			if ( empty( $term_ids ) ) {
				break;
			}

			$deleted = false;
			foreach ( $term_ids as $term_id ) {
				ec_artist_binding_invalidate_term_cache( (int) $term_id, $blog_ids['main'] );
				$stored_values    = get_term_meta( (int) $term_id, '_artist_profile_id', false );
				$processed_values = array();
				foreach ( $stored_values as $stored_value ) {
					if ( ! is_int( $stored_value ) && ! is_string( $stored_value ) ) {
						continue;
					}
					$value_key = gettype( $stored_value ) . ':' . serialize( $stored_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact scalar type identity avoids duplicate compensation records.
					if ( isset( $processed_values[ $value_key ] ) ) {
						continue;
					}
					$processed_values[ $value_key ] = true;

					$stored_value_string = (string) $stored_value;
					if ( 1 !== preg_match( '/^\+?\d+$/D', $stored_value_string ) ) {
						continue;
					}
					$normalized_value = ltrim( ltrim( $stored_value_string, '+' ), '0' );
					$normalized_value = '' === $normalized_value ? '0' : $normalized_value;
					if ( $normalized_value === $profile_id_string ) {
						$before_count  = count(
							array_filter(
								$stored_values,
								static function ( $value ) use ( $stored_value ) {
									return $value === $stored_value;
								}
							)
						);
						$delete_result = delete_term_meta( (int) $term_id, '_artist_profile_id', $stored_value );
						ec_artist_binding_invalidate_term_cache( (int) $term_id, $blog_ids['main'] );
						$remaining     = get_term_meta( (int) $term_id, '_artist_profile_id', false );
						$after_count   = count(
							array_filter(
								$remaining,
								static function ( $value ) use ( $stored_value ) {
									return $value === $stored_value;
								}
							)
						);
						$removed_count = max( 0, $before_count - $after_count );
						if ( $removed_count > 0 ) {
							$journal[] = array(
								'term_id'        => (int) $term_id,
								'values'         => array_fill( 0, $removed_count, $stored_value ),
								'expected_after' => $remaining,
							);
						}
						if ( ! $delete_result && in_array( $stored_value, $remaining, true ) ) {
							$failure = new WP_Error(
								'artist_binding_delete_inverse_failed',
								__( 'A canonical artist inverse reference could not be removed.', 'extrachill-artist-platform' ),
								array(
									'profile_id' => (int) $profile_id,
									'term_id'    => (int) $term_id,
									'retryable'  => false,
								)
							);
							break 2;
						}
						foreach ( $remaining as $remaining_value ) {
							if ( ( is_int( $remaining_value ) || is_string( $remaining_value ) ) && (string) $remaining_value === $stored_value_string ) {
								$failure = new WP_Error(
									'artist_binding_delete_inverse_remaining',
									__( 'A canonical artist inverse reference remained after deletion.', 'extrachill-artist-platform' ),
									array(
										'profile_id' => (int) $profile_id,
										'term_id'    => (int) $term_id,
										'retryable'  => false,
									)
								);
								break 3;
							}
						}
						$deleted = $removed_count > 0 || $deleted;
					}
				}
			}
			if ( $failure instanceof WP_Error ) {
				break;
			}

			if ( ! $deleted ) {
				$offset += count( $term_ids );
			}
		} while ( true );
	} finally {
		restore_current_blog();
	}
	if ( $failure instanceof WP_Error && ! empty( $journal ) ) {
		return ec_compensate_artist_binding_delete_cleanup( $journal, $blog_ids['main'], $failure );
	}
	return $failure instanceof WP_Error ? $failure : true;
}
add_filter( 'pre_delete_post', 'ec_artist_binding_pre_delete_post', PHP_INT_MAX, 3 );
add_action( 'after_delete_post', 'ec_artist_binding_after_delete_post', PHP_INT_MAX, 2 );

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
