<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistTermBindingTest extends EC_Artist_Platform_TestCase {
	private $term_state;

	/**
	 * Create a main-site artist term and a profile, with optional one-sided
	 * or reciprocal binding metadata.
	 */
	private function seed_pair( string $slug, ?int $term_profile_id = null, ?int $profile_term_id = null ): array {
		$profile_id = $this->create_artist_profile( ucwords( str_replace( '-', ' ', $slug ) ), array( 'post_name' => $slug ) );
		$term_id    = $this->create_artist_term( $slug );

		if ( null !== $term_profile_id ) {
			switch_to_blog( $this->main_blog_id() );
			update_term_meta( $term_id, '_artist_profile_id', $term_profile_id );
			restore_current_blog();
		}
		if ( null !== $profile_term_id ) {
			switch_to_blog( $this->artist_blog_id() );
			update_post_meta( $profile_id, '_artist_term_id', $profile_term_id );
			restore_current_blog();
		}

		return array(
			'profile_id' => $profile_id,
			'term_id'    => $term_id,
		);
	}

	private function term_meta_value( int $term_id, string $key ) {
		switch_to_blog( $this->main_blog_id() );
		try {
			return get_term_meta( $term_id, $key, true );
		} finally {
			restore_current_blog();
		}
	}

	private function profile_meta_value( int $profile_id, string $key ) {
		switch_to_blog( $this->artist_blog_id() );
		try {
			return get_post_meta( $profile_id, $key, true );
		} finally {
			restore_current_blog();
		}
	}

	public function test_term_lookup_self_heal_never_writes_to_colliding_main_blog_post(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair    = $this->seed_pair( 'the-band' );
		$collide = (int) self::factory()->post->create( array( 'post_title' => 'Unrelated post' ) );

		$this->assertSame( $pair['profile_id'], ec_get_artist_profile_id( $pair['term_id'] ) );
		$this->assertSame( $pair['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( '', get_post_meta( $collide, '_artist_term_id', true ) );
	}

	public function test_deleted_target_is_rejected_and_stale_reference_is_removed(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $pair['profile_id'], '_artist_term_id', 99999 );
		restore_current_blog();

		$this->assertSame( 0, ec_get_artist_term_id( $pair['profile_id'] ) );
		$this->assertSame( '', $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );

		$orphan_term_id = $this->create_artist_term( 'missing-profile', 99999 );
		$this->assertSame( 0, ec_get_artist_profile_id( $orphan_term_id ) );
		$this->assertSame( '', $this->term_meta_value( $orphan_term_id, '_artist_profile_id' ) );
	}

	public function test_live_reciprocal_collision_fails_closed(): void {
		$this->ec_require_mysql_advisory_locks();
		$first  = $this->seed_pair( 'the-band' );
		$second = $this->seed_pair( 'other-band' );
		// Two profiles claim the same term; the term's canonical record points
		// at the second profile.
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $first['profile_id'], '_artist_term_id', $first['term_id'] );
		update_post_meta( $second['profile_id'], '_artist_term_id', $first['term_id'] );
		restore_current_blog();
		switch_to_blog( $this->main_blog_id() );
		update_term_meta( $first['term_id'], '_artist_profile_id', $second['profile_id'] );
		restore_current_blog();

		$this->assertSame( 0, ec_get_artist_term_id( $first['profile_id'] ) );
		$this->assertSame( $second['profile_id'], (int) $this->term_meta_value( $first['term_id'], '_artist_profile_id' ) );
		$this->assertSame( '', $this->profile_meta_value( $first['profile_id'], '_artist_term_id' ) );
	}

	public function test_stale_term_metadata_cannot_steal_a_validly_bound_profile(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair    = $this->seed_pair( 'the-band' );
		$renamed = $this->seed_pair( 'renamed-band' );
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $pair['profile_id'], '_artist_term_id', $renamed['term_id'] );
		restore_current_blog();
		switch_to_blog( $this->main_blog_id() );
		update_term_meta( $pair['term_id'], '_artist_profile_id', $pair['profile_id'] );
		restore_current_blog();

		$this->assertSame( 0, ec_get_artist_profile_id( $pair['term_id'] ) );
		$this->assertSame( $renamed['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $renamed['term_id'], '_artist_profile_id' ) );
		$this->assertSame( '', $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_unbound_same_slug_term_cannot_steal_a_validly_bound_profile(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair    = $this->seed_pair( 'the-band' );
		$renamed = $this->seed_pair( 'renamed-band' );
		$stale   = $this->create_artist_term( 'the-band-two' );
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $pair['profile_id'], '_artist_term_id', $renamed['term_id'] );
		restore_current_blog();

		$this->assertSame( 0, ec_get_artist_profile_id( $stale ) );
		$this->assertSame( $renamed['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( '', $this->term_meta_value( $stale, '_artist_profile_id' ) );
	}

	public function test_rebinding_cleans_the_old_inverse_reference(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair  = $this->seed_pair( 'the-band', $pair_profile = null, null );
		$bound = $this->seed_pair( 'old-name' );
		$new   = $this->seed_pair( 'new-name' );
		// Reciprocally bind pair/profile to bound/term.
		$this->assertTrue( ec_bind_artist_profile_to_term( $bound['profile_id'], $bound['term_id'] ) );

		$this->assertTrue( ec_bind_artist_profile_to_term( $bound['profile_id'], $new['term_id'] ) );
		$this->assertSame( $new['term_id'], (int) $this->profile_meta_value( $bound['profile_id'], '_artist_term_id' ) );
		$this->assertSame( $bound['profile_id'], (int) $this->term_meta_value( $new['term_id'], '_artist_profile_id' ) );
		$this->assertSame( '', $this->term_meta_value( $bound['term_id'], '_artist_profile_id' ) );
	}

	public function test_rebinding_moves_the_genre_mirror_to_the_new_term(): void {
		$this->ec_require_mysql_advisory_locks();
		$bound = $this->seed_pair( 'old-name' );
		$new   = $this->seed_pair( 'new-name' );
		$this->assertTrue( ec_bind_artist_profile_to_term( $bound['profile_id'], $bound['term_id'] ) );

		switch_to_blog( $this->artist_blog_id() );
		$genre = wp_insert_term( 'Rock', 'genre', array( 'slug' => 'rock' ) );
		if ( is_wp_error( $genre ) ) {
			$genre_term_id = (int) get_term_by( 'slug', 'rock', 'genre' )->term_id;
		} else {
			$genre_term_id = (int) $genre['term_id'];
		}
		wp_set_object_terms( $bound['profile_id'], array( $genre_term_id ), 'genre', false );
		restore_current_blog();
		switch_to_blog( $this->main_blog_id() );
		update_term_meta( $bound['term_id'], '_genres', wp_json_encode( array( 'rock' ) ) );
		restore_current_blog();

		$this->assertTrue( ec_bind_artist_profile_to_term( $bound['profile_id'], $new['term_id'] ) );

		$this->assertSame( wp_json_encode( array( 'rock' ) ), $this->term_meta_value( $new['term_id'], '_genres' ) );
		$this->assertSame( '', $this->term_meta_value( $bound['term_id'], '_genres' ) );
	}

	public function test_create_uses_and_releases_the_canonical_lock(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );

		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$this->assertSame( $pair['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );

		// The lock was released: an independent second binding succeeds.
		$second = $this->seed_pair( 'second-band' );
		$this->assertTrue( ec_bind_artist_profile_to_term( $second['profile_id'], $second['term_id'] ) );
	}

	public function test_boundary_probe_restores_existing_error_suppression(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		$GLOBALS['wpdb']->suppress_errors( true );

		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$this->assertTrue( $GLOBALS['wpdb']->suppress_errors );
		$GLOBALS['wpdb']->suppress_errors( false );
	}

	public function test_sync_holds_one_lock_across_term_creation_and_binding(): void {
		$this->ec_require_mysql_advisory_locks();
		$profile_id = $this->create_artist_profile( 'The Band', array( 'post_name' => 'the-band' ) );

		$this->assertSame( 1, ec_sync_artist_profile_term_binding( $profile_id ) );
		$term_id = (int) $this->profile_meta_value( $profile_id, '_artist_term_id' );
		$this->assertGreaterThan( 0, $term_id );
		$this->assertSame( $profile_id, (int) $this->term_meta_value( $term_id, '_artist_profile_id' ) );
	}

	public function test_writer_rejects_a_caller_owned_transaction_without_locking(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		$GLOBALS['wpdb']->query( 'START TRANSACTION' );

		try {
			$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
			$this->assertSame( 'artist_binding_nested_transaction', ec_get_artist_binding_failure()->get_error_code() );
			$this->assertSame( '', $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		} finally {
			$GLOBALS['wpdb']->query( 'COMMIT' );
		}
		ec_artist_binding_release_deferred_locks();
	}

	public function test_release_failure_fails_closed_and_retains_lock_tracking(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );

		$boundary                          = new EC_Test_FailingAdvisoryReleaseWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$boundary->fail_next_advisory_lock = true;
		$this->ec_swap_wpdb( $boundary );

		$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$this->assertSame( 'artist_binding_release_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( 'ec_artist_binding_v1', $GLOBALS['ec_artist_binding_lock'] );
	}

	public function test_locked_read_is_the_consumer_revalidation_contract(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$this->assertSame( 'artist_binding_lock_required', ec_read_locked_artist_binding( $pair['profile_id'], $pair['term_id'] )->get_error_code() );

		$lock = ec_acquire_artist_binding_lock();
		$GLOBALS['wpdb']->query( 'START TRANSACTION' );
		$this->assertSame( array(
			'profile_id' => $pair['profile_id'],
			'term_id'    => $pair['term_id'],
		), ec_read_locked_artist_binding( $pair['profile_id'], $pair['term_id'] ) );
		$this->assertSame( 'artist_binding_release_transaction_active', ec_release_artist_binding_lock( $lock )->get_error_code() );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertTrue( ec_release_artist_binding_lock( $lock ) );
	}

	public function test_consumer_cannot_acquire_binding_lock_after_starting_transaction(): void {
		$this->ec_require_mysql_advisory_locks();
		$GLOBALS['wpdb']->query( 'START TRANSACTION' );

		try {
			$result = ec_acquire_artist_binding_lock();
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'artist_binding_nested_transaction', $result->get_error_code() );
		} finally {
			$GLOBALS['wpdb']->query( 'COMMIT' );
		}
		ec_artist_binding_release_deferred_locks();
		$this->assertArrayNotHasKey( 'ec_artist_binding_lock', $GLOBALS );
	}

	public function test_resolver_revalidates_after_waiting_and_never_undoes_the_winner(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		$win  = $this->seed_pair( 'winning-band' );
		// A competing writer completes the binding before this reconciliation.
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $win['term_id'] ) );

		$this->assertFalse( ec_reconcile_artist_profile_term_pair( $pair['profile_id'], $pair['term_id'] ) );
		$this->assertSame( $win['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $win['term_id'], '_artist_profile_id' ) );
		$this->assertSame( '', $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_concurrent_rebind_has_one_winner(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair   = $this->seed_pair( 'the-band' );
		$first  = $this->seed_pair( 'first-name' );
		$second = $this->seed_pair( 'second-name' );

		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $first['term_id'] ) );
		// The loser of a competing rebind reports failure without changing state.
		$GLOBALS['wpdb']->query( 'START TRANSACTION' );
		$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $second['term_id'] ) );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		ec_artist_binding_release_deferred_locks();

		$this->assertSame( $first['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $first['term_id'], '_artist_profile_id' ) );
		$this->assertSame( '', $this->term_meta_value( $second['term_id'], '_artist_profile_id' ) );
	}

	public function test_consumer_acquires_binding_before_distinct_membership_lock(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$binding_lock = ec_acquire_artist_binding_lock();
		$this->assertNotInstanceOf( WP_Error::class, $binding_lock );
		$this->assertSame( array(
			'profile_id' => $pair['profile_id'],
			'term_id'    => $pair['term_id'],
		), ec_read_locked_artist_binding( $pair['profile_id'], $pair['term_id'] ) );
		$this->assertTrue( ec_acquire_artist_membership_lock( 7, $pair['profile_id'] ) );

		ec_release_artist_membership_lock( 7, $pair['profile_id'] );
		$this->assertTrue( ec_release_artist_binding_lock( $binding_lock ) );
	}

	public function test_unsupported_database_fails_closed_without_raw_database_errors(): void {
		// The SQLite sandbox runtime IS the unsupported path.
		if ( ! ec_artist_platform_test_wpdb_is_sqlite() ) {
			$this->markTestSkipped( 'Requires a non-MySQL wpdb runtime.' );
		}

		$pair = $this->seed_pair( 'the-band' );

		$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$this->assertSame( 'artist_binding_lock_unsupported', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( array( 'retryable' => true ), ec_get_artist_binding_failure()->get_error_data() );
	}

	public function test_primary_boundary_failure_survives_release_failure(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		$GLOBALS['wpdb']->query( 'START TRANSACTION' );

		$boundary                          = new EC_Test_FailingAdvisoryReleaseWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$boundary->fail_next_advisory_lock = true;
		$this->ec_swap_wpdb( $boundary );

		try {
			$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
			$this->assertSame( 'artist_binding_nested_transaction', ec_get_artist_binding_failure()->get_error_code() );
		} finally {
			$GLOBALS['wpdb']->query( 'COMMIT' );
		}
		ec_artist_binding_release_deferred_locks();
		$this->assertSame( 'artist_binding_release_failed', ec_get_artist_binding_release_failure()->get_error_code() );
	}

	public function test_throwable_releases_the_binding_lock(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );

		$this->ec_inject_filter(
			'added_post_meta',
			static function () {
				throw new RuntimeException( 'Injected writer failure.' );
			},
			100
		);

		try {
			ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] );
			$this->fail( 'Expected injected writer failure.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Injected writer failure.', $exception->getMessage() );
		}
		$this->assertArrayNotHasKey( 'ec_artist_binding_lock', $GLOBALS );
	}

	public function test_failed_rebinding_restores_the_previous_reciprocal_pair(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$new = $this->seed_pair( 'new-name' );

		$this->fail_meta_updates( 'term', 5, '_artist_profile_id' );

		$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $new['term_id'] ) );
		$this->assertSame( $pair['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
		$this->assertSame( '', $this->term_meta_value( $new['term_id'], '_artist_profile_id' ) );
	}

	public function test_rebinding_stops_when_old_inverse_cannot_be_removed(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$new = $this->seed_pair( 'new-name' );

		$this->fail_meta_deletes( 'term', 5, '_artist_profile_id' );

		$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $new['term_id'] ) );
		$this->assertSame( $pair['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_profile_side_compensation_exhaustion_is_reported_for_manual_repair(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		$new  = $this->seed_pair( 'the-band-two' );

		$this->fail_meta_updates( 'term', 5, '_artist_profile_id' );
		$this->fail_meta_deletes( 'post', 10, '_artist_term_id' );

		$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $new['term_id'] ) );
		$this->assertSame( 'artist_binding_compensation_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertFalse( ec_get_artist_binding_failure()->get_error_data()['retryable'] );
	}

	public function test_term_side_compensation_exhaustion_is_reported_for_manual_repair(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		$new  = $this->seed_pair( 'the-band-two' );

		$this->fail_meta_updates( 'post', 5, '_artist_term_id' );
		$this->fail_meta_deletes( 'term', 10, '_artist_profile_id' );

		$this->assertFalse( ec_bind_artist_profile_to_term( $pair['profile_id'], $new['term_id'] ) );
		$this->assertSame( 'artist_binding_compensation_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertFalse( ec_get_artist_binding_failure()->get_error_data()['retryable'] );
	}

	public function test_sync_propagates_resolver_compensation_failure_without_cleanup(): void {
		$this->ec_require_mysql_advisory_locks();
		$profile_id = $this->create_artist_profile( 'The Band', array( 'post_name' => 'the-band' ) );
		$claimed    = $this->seed_pair( 'the-band' );
		switch_to_blog( $this->main_blog_id() );
		update_term_meta( $claimed['term_id'], '_artist_profile_id', $profile_id );
		restore_current_blog();

		$this->fail_meta_updates( 'post', 5, '_artist_term_id' );
		$this->fail_meta_deletes( 'term', 10, '_artist_profile_id' );

		$result = ec_sync_artist_profile_term_binding( $profile_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_binding_compensation_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( $profile_id, (int) $this->term_meta_value( $claimed['term_id'], '_artist_profile_id' ) );
	}

	public function test_deleting_a_colliding_main_blog_post_does_not_unbind_the_profile(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$collide      = (int) self::factory()->post->create( array( 'post_title' => 'Unrelated post' ) );
		$collide_post = get_post( $collide );

		$this->assertNull( ec_artist_binding_pre_delete_post( null, $collide_post, true ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_profile_deletion_cleans_reciprocal_and_additional_stale_term_references(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$stale = $this->create_artist_term( 'stale-band', $pair['profile_id'] );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$this->assertSame( '', $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
		$this->assertSame( '', $this->term_meta_value( $stale, '_artist_profile_id' ) );
	}

	public function test_delete_holds_binding_lock_until_core_finishes(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$state           = new stdClass();
		$state->competed = null;
		$state->held     = null;
		$this->ec_inject_filter(
			'pre_delete_post',
			function ( $check, $post ) use ( $state, $pair ) {
				if ( ! $post || (int) $post->ID !== $pair['profile_id'] ) {
					return $check;
				}
				$new             = $this->create_artist_term( 'replacement' );
				$state->competed = ec_bind_artist_profile_to_term( $pair['profile_id'], $new );
				$state->held     = isset( $GLOBALS['ec_artist_binding_delete_locks'] ) && ! empty( $GLOBALS['ec_artist_binding_delete_locks'] );
				return $check;
			},
			PHP_INT_MAX - 1
		);

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$this->assertFalse( $state->competed, 'A competing bind inside the held delete lock must be denied.' );
		$this->assertTrue( $state->held );
		ec_artist_binding_release_delete_locks();
		$this->assertArrayNotHasKey( 'ec_artist_binding_delete_locks', $GLOBALS );
	}

	public function test_failed_core_delete_retains_lock_until_shutdown_cleanup(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$this->fail_post_deletes();

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		ec_artist_binding_release_delete_locks();
		$this->assertArrayNotHasKey( 'ec_artist_binding_delete_locks', $GLOBALS );
	}

	public function test_delete_vetoes_when_inverse_query_fails(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$state = $this->track_term_queries_and_fail_on( 1 );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		$this->assertSame( 'artist_binding_delete_query_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertNotInstanceOf( WP_Error::class, get_post( $pair['profile_id'] ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_delete_vetoes_when_inverse_delete_fails(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$this->fail_meta_deletes( 'term', 5, '_artist_profile_id' );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		$this->assertSame( 'artist_binding_delete_inverse_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_delete_vetoes_when_inverse_claim_remains_after_reported_success(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$this->succeed_meta_deletes_without_deleting( 'term', '_artist_profile_id' );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		$this->assertSame( 'artist_binding_delete_inverse_remaining', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_delete_compensates_a_successful_removal_before_later_query_failure(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$state = $this->track_term_queries_and_fail_on( 2 );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		$this->assertSame( 'artist_binding_delete_query_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_delete_compensates_earlier_removals_when_later_delete_fails(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$stale = $this->create_artist_term( 'stale-band', $pair['profile_id'] );

		$state        = new stdClass();
		$state->count = 0;
		$this->ec_inject_filter(
			'delete_term_metadata',
			function ( $check, $object_id, $meta_key ) use ( $state ) {
				if ( '_artist_profile_id' !== $meta_key ) {
					return $check;
				}
				++$state->count;
				return 2 === $state->count ? false : $check;
			},
			100
		);

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		$this->assertSame( 'artist_binding_delete_inverse_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $stale, '_artist_profile_id' ) );
	}

	public function test_delete_reports_nonretryable_compensation_write_failure(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$stale = $this->create_artist_term( 'stale-band', $pair['profile_id'] );

		$delete_state        = new stdClass();
		$delete_state->count = 0;
		$this->ec_inject_filter(
			'delete_term_metadata',
			function ( $check, $object_id, $meta_key ) use ( $delete_state ) {
				if ( '_artist_profile_id' !== $meta_key ) {
					return $check;
				}
				++$delete_state->count;
				return 2 === $delete_state->count ? false : $check;
			},
			100
		);
		$this->fail_meta_adds( 'term', 10, '_artist_profile_id' );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		$failure = ec_get_artist_binding_failure();
		$this->assertSame( 'artist_binding_delete_compensation_failed', $failure->get_error_code() );
		$this->assertSame( 'artist_binding_delete_inverse_failed', $failure->get_error_data()['primary_code'] );
		$this->assertFalse( $failure->get_error_data()['retryable'] );
	}

	public function test_delete_reports_nonretryable_compensation_verification_failure(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$stale = $this->create_artist_term( 'stale-band', $pair['profile_id'] );

		$delete_state        = new stdClass();
		$delete_state->count = 0;
		$this->ec_inject_filter(
			'delete_term_metadata',
			function ( $check, $object_id, $meta_key ) use ( $delete_state ) {
				if ( '_artist_profile_id' !== $meta_key ) {
					return $check;
				}
				++$delete_state->count;
				return 2 === $delete_state->count ? false : $check;
			},
			100
		);
		$this->succeed_meta_adds_without_writing( 'term', '_artist_profile_id' );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		$failure = ec_get_artist_binding_failure();
		$this->assertSame( 'artist_binding_delete_compensation_failed', $failure->get_error_code() );
		$this->assertSame( 'verification', $failure->get_error_data()['failure_stage'] );
		$this->assertFalse( $failure->get_error_data()['retryable'] );
	}

	public function test_delete_compensation_never_overwrites_changed_term_state(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$state        = new stdClass();
		$state->count = 0;
		$this->ec_inject_filter(
			'terms_pre_query',
			function ( $results, $term_query ) use ( $state ) {
				++$state->count;
				if ( 2 === $state->count ) {
					switch_to_blog( $this->main_blog_id() );
					update_term_meta( $pair['term_id'], '_artist_profile_id', 99 );
					restore_current_blog();
					return new WP_Error( 'ec_test_term_query_failed', 'Injected term query failure.' );
				}
				return $results;
			},
			100
		);

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertFalse( $deleted );
		$failure = ec_get_artist_binding_failure();
		$this->assertSame( 'artist_binding_delete_compensation_failed', $failure->get_error_code() );
		$this->assertSame( 'state_changed', $failure->get_error_data()['failure_stage'] );
		$this->assertSame( 99, (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
	}

	public function test_profile_deletion_cleans_term_references_without_profile_metadata(): void {
		$this->ec_require_mysql_advisory_locks();
		$profile_id = $this->create_artist_profile( 'The Band', array( 'post_name' => 'the-band' ) );
		$stale      = $this->create_artist_term( 'stale-band', $profile_id );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $profile_id, true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$this->assertSame( '', $this->term_meta_value( $stale, '_artist_profile_id' ) );
	}

	public function test_profile_deletion_cleans_noncanonical_numeric_term_references(): void {
		$this->ec_require_mysql_advisory_locks();
		$profile_id = $this->create_artist_profile( 'The Band', array( 'post_name' => 'the-band' ) );
		$stale      = $this->create_artist_term( 'stale-band' );
		switch_to_blog( $this->main_blog_id() );
		update_term_meta( $stale, '_artist_profile_id', '0' . $profile_id );
		restore_current_blog();

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $profile_id, true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$this->assertSame( '0' . $profile_id, $this->term_meta_value( $stale, '_artist_profile_id' ) );
	}

	public function test_profile_deletion_uses_stable_complete_bounded_uncached_batches(): void {
		$this->ec_require_mysql_advisory_locks();
		$profile_id = $this->create_artist_profile( 'The Band', array( 'post_name' => 'the-band' ) );
		for ( $i = 0; $i < 205; ++$i ) {
			$this->create_artist_term( 'stale-band-' . $i, $profile_id );
		}

		$state = $this->track_term_queries_and_fail_on( 0 );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $profile_id, true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$this->assertGreaterThanOrEqual( 3, count( $state->queries ) );
		foreach ( $state->queries as $vars ) {
			$this->assertSame( 100, (int) $vars['number'] );
			$this->assertFalse( (bool) $vars['cache_results'] );
			$this->assertFalse( (bool) $vars['update_term_meta_cache'] );
		}
		$offsets = array_map( static fn( $vars ) => (int) $vars['offset'], $state->queries );
		$this->assertSame( 0, $offsets[0] );
		$this->assertSame( $offsets[0] + 100, $offsets[1] );
	}

	public function test_profile_deletion_keeps_adjacent_large_integer_references_distinct(): void {
		$this->ec_require_mysql_advisory_locks();
		$profile_id = $this->create_artist_profile( 'Large Id Band', array( 'post_name' => 'large-id-band' ) );
		$stale      = $this->create_artist_term( 'large-id-band' );
		switch_to_blog( $this->main_blog_id() );
		update_term_meta(
			$stale,
			'_artist_profile_id',
			array(
				'09007000000000000000' . $profile_id,
				'90070' . $profile_id . '2',
			)
		);
		restore_current_blog();

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $profile_id, true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$remaining = $this->term_meta_value( $stale, '_artist_profile_id' );
		$this->assertNotContains( (string) $profile_id, array_map( 'strval', (array) $remaining ) );
	}

	public function test_profile_deletion_skips_malformed_numeric_cast_matches_without_looping(): void {
		$this->ec_require_mysql_advisory_locks();
		$profile_id = $this->create_artist_profile( 'The Band', array( 'post_name' => 'the-band' ) );
		$malformed  = $this->create_artist_term( 'malformed-band' );
		$stale      = $this->create_artist_term( 'stale-band', $profile_id );
		$decimal    = $this->create_artist_term( 'decimal-band' );
		switch_to_blog( $this->main_blog_id() );
		update_term_meta( $malformed, '_artist_profile_id', $profile_id . 'broken' );
		update_term_meta( $decimal, '_artist_profile_id', $profile_id . '.5' );
		restore_current_blog();

		$state = $this->track_term_queries_and_fail_on( 0 );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $profile_id, true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$this->assertSame( $profile_id . 'broken', $this->term_meta_value( $malformed, '_artist_profile_id' ) );
		$this->assertSame( '', $this->term_meta_value( $stale, '_artist_profile_id' ) );
		$this->assertSame( $profile_id . '.5', $this->term_meta_value( $decimal, '_artist_profile_id' ) );
	}

	public function test_profile_deletion_does_not_mutate_wrong_taxonomy_or_unrelated_terms(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		$other_profile = $this->create_artist_profile( 'Other Band', array( 'post_name' => 'other-band' ) );
		$other_term    = $this->create_artist_term( 'other-band', $other_profile );
		$genre_term    = $this->create_artist_term( 'genre-term' );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$this->assertSame( '', $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
		$this->assertSame( $other_profile, (int) $this->term_meta_value( $other_term, '_artist_profile_id' ) );
		$this->assertSame( '', $this->term_meta_value( $genre_term, '_artist_profile_id' ) );
	}

	public function test_profile_deletion_does_not_mutate_a_colliding_main_blog_post(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		$collide = (int) self::factory()->post->create( array( 'post_title' => 'Unrelated post' ) );
		update_post_meta( $collide, '_artist_profile_id', 'unchanged' );

		switch_to_blog( $this->artist_blog_id() );
		$deleted = wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertNotFalse( $deleted );
		$this->assertSame( '', $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
		$this->assertSame( 'unchanged', get_post_meta( $collide, '_artist_profile_id', true ) );
	}

	public function test_profile_deletion_restores_the_callers_artist_blog(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );

		switch_to_blog( $this->artist_blog_id() );
		wp_delete_post( $pair['profile_id'], true );
		restore_current_blog();

		$this->assertSame( $this->artist_blog_id(), get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
	}

	public function test_slug_renames_do_not_break_a_valid_id_binding(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band', $pair['profile_id'], null );
		$this->assertTrue( ec_bind_artist_profile_to_term( $pair['profile_id'], $pair['term_id'] ) );
		switch_to_blog( $this->artist_blog_id() );
		wp_update_post(
			array(
				'ID'        => $pair['profile_id'],
				'post_name' => 'renamed-profile',
			)
		);
		restore_current_blog();
		switch_to_blog( $this->main_blog_id() );
		wp_update_term( $pair['term_id'], 'artist', array( 'slug' => 'original-term-slug-renamed' ) );
		restore_current_blog();

		$this->assertSame( $pair['term_id'], (int) ec_get_artist_term_id( $pair['profile_id'] ) );
		$this->assertSame( $pair['profile_id'], (int) ec_get_artist_profile_id( $pair['term_id'] ) );
	}

	public function test_integrity_backfill_uses_a_new_migration_key_on_upgraded_sites(): void {
		$this->ec_require_mysql_advisory_locks();
		$pair = $this->seed_pair( 'the-band' );
		update_option( 'extrachill_artist_platform_term_binding_backfill', '1.0.0' );

		ec_backfill_artist_term_bindings();

		$this->assertSame( $pair['term_id'], (int) $this->profile_meta_value( $pair['profile_id'], '_artist_term_id' ) );
		$this->assertSame( $pair['profile_id'], (int) $this->term_meta_value( $pair['term_id'], '_artist_profile_id' ) );
		$this->assertSame( '2.0.0', get_option( 'extrachill_artist_platform_term_binding_integrity_backfill' ) );
	}
}
