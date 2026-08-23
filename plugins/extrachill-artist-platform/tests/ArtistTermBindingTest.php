<?php

use PHPUnit\Framework\TestCase;

final class ArtistTermBindingTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['ec_artist_binding_lock'], $GLOBALS['ec_artist_binding_lock_pending'], $GLOBALS['ec_artist_binding_delete_locks'], $GLOBALS['ec_artist_binding_deferred_locks'], $GLOBALS['ec_artist_binding_release_failure'] );
		$GLOBALS['wpdb'] = new EcTestWpdb();
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				1 => array(
					'posts'     => array(),
					'post_meta' => array(),
					'terms'     => array(),
					'term_meta' => array(),
				),
				4 => array(
					'posts'     => array(),
					'post_meta' => array(),
					'terms'     => array(),
					'term_meta' => array(),
				),
			),
		);
	}

	private function addProfile( $id, $slug, $term_id = 0 ) {
		$GLOBALS['ec_test']['blogs'][4]['posts'][ $id ] = (object) array(
			'ID'          => $id,
			'post_type'   => 'artist_profile',
			'post_status' => 'publish',
			'post_title'  => ucwords( str_replace( '-', ' ', $slug ) ),
			'post_name'   => $slug,
		);
		if ( $term_id > 0 ) {
			$GLOBALS['ec_test']['blogs'][4]['post_meta'][ $id ]['_artist_term_id'] = $term_id;
		}
	}

	private function addTerm( $id, $slug, $profile_id = 0, $taxonomy = 'artist' ) {
		$GLOBALS['ec_test']['blogs'][1]['terms'][ $id ] = (object) array(
			'term_id'  => $id,
			'taxonomy' => $taxonomy,
			'slug'     => $slug,
		);
		if ( $profile_id > 0 ) {
			$GLOBALS['ec_test']['blogs'][1]['term_meta'][ $id ]['_artist_profile_id'] = $profile_id;
		}
	}

	public function test_term_lookup_self_heal_never_writes_to_colliding_main_blog_post(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$GLOBALS['ec_test']['blogs'][1]['posts'][12] = (object) array(
			'ID'          => 12,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Unrelated post',
			'post_name'   => 'unrelated-post',
		);
		$GLOBALS['ec_test']['current_blog_id'] = 1;

		$this->assertSame( 12, ec_get_artist_profile_id( 101 ) );
		$this->assertSame( 101, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertArrayNotHasKey( 12, $GLOBALS['ec_test']['blogs'][1]['post_meta'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['current_blog_id'] );
	}

	public function test_deleted_target_is_rejected_and_stale_reference_is_removed(): void {
		$this->addProfile( 12, 'the-band', 999 );

		$this->assertSame( 0, ec_get_artist_term_id( 12 ) );
		$this->assertArrayNotHasKey( '_artist_term_id', $GLOBALS['ec_test']['blogs'][4]['post_meta'][12] ?? array() );

		$this->addTerm( 101, 'missing-profile', 999 );
		$this->assertSame( 0, ec_get_artist_profile_id( 101 ) );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
	}

	public function test_live_reciprocal_collision_fails_closed(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addProfile( 13, 'other-band', 101 );
		$this->addTerm( 101, 'the-band', 13 );

		$this->assertSame( 0, ec_get_artist_term_id( 12 ) );
		$this->assertSame( 13, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
		$this->assertArrayNotHasKey( '_artist_term_id', $GLOBALS['ec_test']['blogs'][4]['post_meta'][12] );
	}

	public function test_stale_term_metadata_cannot_steal_a_validly_bound_profile(): void {
		$this->addProfile( 12, 'the-band', 102 );
		$this->addTerm( 101, 'the-band', 12 );
		$this->addTerm( 102, 'renamed-band', 12 );

		$this->assertSame( 0, ec_get_artist_profile_id( 101 ) );
		$this->assertSame( 102, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
	}

	public function test_unbound_same_slug_term_cannot_steal_a_validly_bound_profile(): void {
		$this->addProfile( 12, 'the-band', 102 );
		$this->addTerm( 101, 'the-band' );
		$this->addTerm( 102, 'renamed-band', 12 );

		$this->assertSame( 0, ec_get_artist_profile_id( 101 ) );
		$this->assertSame( 102, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertArrayNotHasKey( 101, $GLOBALS['ec_test']['blogs'][1]['term_meta'] );
	}

	public function test_rebinding_cleans_the_old_inverse_reference(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'old-name', 12 );
		$this->addTerm( 102, 'new-name' );

		$this->assertTrue( ec_bind_artist_profile_to_term( 12, 102 ) );
		$this->assertSame( 102, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
	}

	public function test_create_uses_and_releases_the_canonical_lock(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );

		$this->assertTrue( ec_bind_artist_profile_to_term( 12, 101 ) );
		$this->assertSame( array( 'ec_artist_binding_v1' ), $GLOBALS['ec_test']['db_lock_order'] );
		$this->assertArrayNotHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
	}

	public function test_boundary_probe_restores_existing_error_suppression(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$GLOBALS['wpdb']->suppress_errors = true;

		$this->assertTrue( ec_bind_artist_profile_to_term( 12, 101 ) );
		$this->assertTrue( $GLOBALS['wpdb']->suppress_errors );
	}

	public function test_sync_holds_one_lock_across_term_creation_and_binding(): void {
		$this->addProfile( 12, 'the-band' );

		$this->assertSame( 1, ec_sync_artist_profile_term_binding( 12 ) );
		$this->assertSame( array( 'ec_artist_binding_v1' ), $GLOBALS['ec_test']['db_lock_order'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][1]['_artist_profile_id'] );
	}

	public function test_writer_rejects_a_caller_owned_transaction_without_locking(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$GLOBALS['ec_test']['in_transaction'] = true;

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 101 ) );
		$this->assertSame( 'artist_binding_nested_transaction', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( array( 'ec_artist_binding_v1' ), $GLOBALS['ec_test']['db_lock_order'] );
		$this->assertSame( array( true, false ), $GLOBALS['ec_test']['error_suppression'] );
		$this->assertArrayHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
		$this->assertArrayNotHasKey( '_artist_term_id', $GLOBALS['ec_test']['blogs'][4]['post_meta'][12] ?? array() );
		$GLOBALS['ec_test']['in_transaction'] = false;
		ec_artist_binding_release_deferred_locks();
		$this->assertArrayNotHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
	}

	public function test_release_failure_fails_closed_and_retains_lock_tracking(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$GLOBALS['ec_test']['advisory_release_result'] = 'error';

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 101 ) );
		$this->assertSame( 'artist_binding_release_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( 'ec_artist_binding_v1', $GLOBALS['ec_artist_binding_lock'] );
	}

	public function test_locked_read_is_the_consumer_revalidation_contract(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$this->assertSame( 'artist_binding_lock_required', ec_read_locked_artist_binding( 12, 101 )->get_error_code() );

		$lock = ec_acquire_artist_binding_lock();
		$GLOBALS['ec_test']['in_transaction'] = true;
		$this->assertSame( array( 'profile_id' => 12, 'term_id' => 101 ), ec_read_locked_artist_binding( 12, 101 ) );
		$this->assertContains( array( 4, 12 ), $GLOBALS['ec_test']['clean_post_cache_calls'] );
		$this->assertContains( array( 1, 101, 'artist' ), $GLOBALS['ec_test']['clean_term_cache_calls'] );
		$this->assertSame( 'artist_binding_release_transaction_active', ec_release_artist_binding_lock( $lock )->get_error_code() );
		$GLOBALS['ec_test']['in_transaction'] = false;
		$this->assertTrue( ec_release_artist_binding_lock( $lock ) );
	}

	public function test_consumer_cannot_acquire_binding_lock_after_starting_transaction(): void {
		$GLOBALS['ec_test']['in_transaction'] = true;

		$result = ec_acquire_artist_binding_lock();
		$this->assertSame( 'artist_binding_nested_transaction', $result->get_error_code() );
		$this->assertArrayHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
		$GLOBALS['ec_test']['in_transaction'] = false;
		ec_artist_binding_release_deferred_locks();
		$this->assertArrayNotHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
	}

	public function test_resolver_revalidates_after_waiting_and_never_undoes_the_winner(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$this->addTerm( 102, 'winning-band' );
		$GLOBALS['ec_test']['after_external_artist_lock'] = static function () {
			$GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id']      = 102;
			$GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] = 12;
		};

		$this->assertFalse( ec_reconcile_artist_profile_term_pair( 12, 101 ) );
		$this->assertSame( 102, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] );
		$this->assertArrayNotHasKey( 101, $GLOBALS['ec_test']['blogs'][1]['term_meta'] );
	}

	public function test_concurrent_rebind_has_one_winner(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'old-name', 12 );
		$this->addTerm( 102, 'first-name' );
		$this->addTerm( 103, 'second-name' );
		$GLOBALS['ec_test']['after_external_artist_lock'] = static function () {
			$GLOBALS['ec_test']['competing_rebind'] = ec_bind_artist_profile_to_term( 12, 103 );
		};

		$this->assertTrue( ec_bind_artist_profile_to_term( 12, 102 ) );
		$this->assertFalse( $GLOBALS['ec_test']['competing_rebind'] );
		$this->assertSame( 102, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
		$this->assertArrayNotHasKey( 103, $GLOBALS['ec_test']['blogs'][1]['term_meta'] );
	}

	public function test_consumer_acquires_binding_before_distinct_membership_lock(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$binding_lock = ec_acquire_artist_binding_lock();
		$this->assertSame( array( 'profile_id' => 12, 'term_id' => 101 ), ec_read_locked_artist_binding( 12, 101 ) );
		$this->assertTrue( ec_acquire_artist_membership_lock( 7, 12 ) );

		$this->assertSame( array( 'ec_artist_binding_v1', 'ec_artist_membership_7_12' ), $GLOBALS['ec_test']['db_lock_order'] );
		ec_release_artist_membership_lock( 7, 12 );
		$this->assertTrue( ec_release_artist_binding_lock( $binding_lock ) );
	}

	public function test_unsupported_database_fails_closed_without_raw_database_errors(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$GLOBALS['wpdb'] = new EcTestSqliteWpdb();

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 101 ) );
		$this->assertSame( 'artist_binding_lock_unsupported', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( array( 'retryable' => true ), ec_get_artist_binding_failure()->get_error_data() );
	}

	public function test_primary_boundary_failure_survives_release_failure(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$GLOBALS['ec_test']['in_transaction']          = true;
		$GLOBALS['ec_test']['advisory_release_result'] = 'error';

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 101 ) );
		$this->assertSame( 'artist_binding_nested_transaction', ec_get_artist_binding_failure()->get_error_code() );
		$GLOBALS['ec_test']['in_transaction'] = false;
		ec_artist_binding_release_deferred_locks();
		$this->assertSame( 'artist_binding_release_failed', ec_get_artist_binding_release_failure()->get_error_code() );
	}

	public function test_throwable_releases_the_binding_lock(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$GLOBALS['ec_test']['after_post_meta_update'] = static function () {
			throw new RuntimeException( 'Injected writer failure.' );
		};

		try {
			ec_bind_artist_profile_to_term( 12, 101 );
			$this->fail( 'Expected injected writer failure.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Injected writer failure.', $exception->getMessage() );
		}
		$this->assertArrayNotHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
		$this->assertArrayNotHasKey( 'ec_artist_binding_lock', $GLOBALS );
	}

	public function test_failed_rebinding_restores_the_previous_reciprocal_pair(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'old-name', 12 );
		$this->addTerm( 102, 'new-name' );
		$GLOBALS['ec_test']['fail_term_meta_update_keys']['_artist_profile_id'] = 1;

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 102 ) );
		$this->assertSame( 101, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
		$this->assertArrayNotHasKey( 102, $GLOBALS['ec_test']['blogs'][1]['term_meta'] );
	}

	public function test_rebinding_stops_when_old_inverse_cannot_be_removed(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'old-name', 12 );
		$this->addTerm( 102, 'new-name' );
		$GLOBALS['ec_test']['fail_term_meta_delete_keys']['_artist_profile_id'] = 1;

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 102 ) );
		$this->assertSame( 101, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
		$this->assertArrayNotHasKey( 102, $GLOBALS['ec_test']['blogs'][1]['term_meta'] );
	}

	public function test_profile_side_compensation_exhaustion_is_reported_for_manual_repair(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 102, 'the-band' );
		$GLOBALS['ec_test']['fail_term_meta_update_keys']['_artist_profile_id'] = 1;
		$GLOBALS['ec_test']['fail_post_meta_delete_keys']['_artist_term_id'] = 5;

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 102 ) );
		$this->assertSame( 'artist_binding_compensation_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertFalse( ec_get_artist_binding_failure()->get_error_data()['retryable'] );
		$this->assertSame( 102, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertArrayNotHasKey( 102, $GLOBALS['ec_test']['blogs'][1]['term_meta'] );
	}

	public function test_term_side_compensation_exhaustion_is_reported_for_manual_repair(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 102, 'the-band' );
		$GLOBALS['ec_test']['fail_post_meta_update_keys']['_artist_term_id'] = 1;
		$GLOBALS['ec_test']['fail_term_meta_delete_keys']['_artist_profile_id'] = 5;

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 102 ) );
		$this->assertSame( 'artist_binding_compensation_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertFalse( ec_get_artist_binding_failure()->get_error_data()['retryable'] );
		$this->assertArrayNotHasKey( '_artist_term_id', $GLOBALS['ec_test']['blogs'][4]['post_meta'][12] ?? array() );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] );
	}

	public function test_existing_pair_compensation_exhaustion_is_reported_for_manual_repair(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'old-name', 12 );
		$this->addTerm( 102, 'new-name' );
		$GLOBALS['ec_test']['fail_term_meta_update_keys']['_artist_profile_id'] = 1;
		$GLOBALS['ec_test']['fail_post_meta_update_on_calls'] = array( 2, 3, 4 );

		$this->assertFalse( ec_bind_artist_profile_to_term( 12, 102 ) );
		$this->assertSame( 'artist_binding_compensation_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertFalse( ec_get_artist_binding_failure()->get_error_data()['retryable'] );
		$this->assertSame( 102, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
	}

	public function test_sync_propagates_resolver_compensation_failure_without_cleanup(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 102, 'the-band' );
		$GLOBALS['ec_test']['fail_post_meta_update_keys']['_artist_term_id'] = 1;
		$GLOBALS['ec_test']['fail_term_meta_delete_keys']['_artist_profile_id'] = 5;

		$result = ec_sync_artist_profile_term_binding( 12 );

		$this->assertSame( 'artist_binding_compensation_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertArrayNotHasKey( '_artist_term_id', $GLOBALS['ec_test']['blogs'][4]['post_meta'][12] ?? array() );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] );
	}

	public function test_deleting_a_colliding_main_blog_post_does_not_unbind_the_profile(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['current_blog_id'] = 1;

		$this->assertNull( ec_artist_binding_pre_delete_post( null, $GLOBALS['ec_test']['blogs'][4]['posts'][12], true ) );

		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
	}

	public function test_profile_deletion_cleans_reciprocal_and_additional_stale_term_references(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$this->addTerm( 102, 'stale-band', 12 );

		wp_delete_post( 12, true );
		$this->assertSame( array( 'ec_artist_binding_v1' ), $GLOBALS['ec_test']['db_lock_order'] );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][102] );
	}

	public function test_delete_holds_binding_lock_until_core_finishes(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$this->addTerm( 102, 'replacement' );
		$GLOBALS['ec_test']['before_post_delete'] = static function () {
			$GLOBALS['ec_test']['delete_competing_bind'] = ec_bind_artist_profile_to_term( 12, 102 );
			$GLOBALS['ec_test']['delete_lock_held']      = isset( $GLOBALS['ec_test']['db_locks']['ec_artist_binding_v1'] );
		};

		$this->assertNotFalse( wp_delete_post( 12, true ) );
		$this->assertFalse( $GLOBALS['ec_test']['delete_competing_bind'] );
		$this->assertTrue( $GLOBALS['ec_test']['delete_lock_held'] );
		$this->assertArrayNotHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
	}

	public function test_failed_core_delete_retains_lock_until_shutdown_cleanup(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['fail_post_delete'] = true;

		$this->assertFalse( wp_delete_post( 12, true ) );
		$this->assertArrayHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
		ec_artist_binding_release_delete_locks();
		$this->assertArrayNotHasKey( 'ec_artist_binding_v1', $GLOBALS['ec_test']['db_locks'] );
	}

	public function test_delete_vetoes_when_inverse_query_fails(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['fail_get_terms'] = true;

		$this->assertFalse( wp_delete_post( 12, true ) );
		$this->assertSame( 'artist_binding_delete_query_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertArrayHasKey( 12, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
	}

	public function test_delete_vetoes_when_inverse_delete_fails(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['fail_term_meta_delete_keys']['_artist_profile_id'] = 1;

		$this->assertFalse( wp_delete_post( 12, true ) );
		$this->assertSame( 'artist_binding_delete_inverse_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertArrayHasKey( 12, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
	}

	public function test_delete_vetoes_when_inverse_claim_remains_after_reported_success(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['term_meta_delete_reports_success_without_delete'] = true;

		$this->assertFalse( wp_delete_post( 12, true ) );
		$this->assertSame( 'artist_binding_delete_inverse_remaining', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertArrayHasKey( 12, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
	}

	public function test_delete_compensates_a_successful_removal_before_later_query_failure(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['fail_get_terms_on_call'] = 2;

		$this->assertFalse( wp_delete_post( 12, true ) );
		$this->assertSame( 'artist_binding_delete_query_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['term_meta_add_calls']['_artist_profile_id'] );
	}

	public function test_delete_compensates_earlier_removals_when_later_delete_fails(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$this->addTerm( 102, 'stale-band', 12 );
		$GLOBALS['ec_test']['fail_term_meta_delete_on_call']['_artist_profile_id'] = 2;

		$this->assertFalse( wp_delete_post( 12, true ) );
		$this->assertSame( 'artist_binding_delete_inverse_failed', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] );
	}

	public function test_delete_reports_nonretryable_compensation_write_failure(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$this->addTerm( 102, 'stale-band', 12 );
		$GLOBALS['ec_test']['fail_term_meta_delete_on_call']['_artist_profile_id'] = 2;
		$GLOBALS['ec_test']['fail_term_meta_add_keys']['_artist_profile_id']       = 3;

		$this->assertFalse( wp_delete_post( 12, true ) );
		$failure = ec_get_artist_binding_failure();
		$this->assertSame( 'artist_binding_delete_compensation_failed', $failure->get_error_code() );
		$this->assertSame( 'artist_binding_delete_inverse_failed', $failure->get_error_data()['primary_code'] );
		$this->assertSame( array( 101 ), $failure->get_error_data()['affected_term_ids'] );
		$this->assertSame( 'write', $failure->get_error_data()['failure_stage'] );
		$this->assertFalse( $failure->get_error_data()['retryable'] );
	}

	public function test_delete_reports_nonretryable_compensation_verification_failure(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$this->addTerm( 102, 'stale-band', 12 );
		$GLOBALS['ec_test']['fail_term_meta_delete_on_call']['_artist_profile_id'] = 2;
		$GLOBALS['ec_test']['term_meta_add_reports_success_without_add']           = true;

		$this->assertFalse( wp_delete_post( 12, true ) );
		$failure = ec_get_artist_binding_failure();
		$this->assertSame( 'artist_binding_delete_compensation_failed', $failure->get_error_code() );
		$this->assertSame( 'artist_binding_delete_inverse_failed', $failure->get_error_data()['primary_code'] );
		$this->assertSame( array( 101 ), $failure->get_error_data()['affected_term_ids'] );
		$this->assertSame( 'verification', $failure->get_error_data()['failure_stage'] );
		$this->assertFalse( $failure->get_error_data()['retryable'] );
	}

	public function test_delete_compensation_never_overwrites_changed_term_state(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['fail_get_terms_on_call'] = 2;
		$GLOBALS['ec_test']['before_get_terms_failure'] = static function () {
			$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 99;
		};

		$this->assertFalse( wp_delete_post( 12, true ) );
		$failure = ec_get_artist_binding_failure();
		$this->assertSame( 'artist_binding_delete_compensation_failed', $failure->get_error_code() );
		$this->assertSame( 'state_changed', $failure->get_error_data()['failure_stage'] );
		$this->assertSame( 99, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
	}

	public function test_profile_deletion_cleans_term_references_without_profile_metadata(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'stale-band', 12 );

		wp_delete_post( 12, true );

		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
	}

	public function test_profile_deletion_cleans_noncanonical_numeric_term_references(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'stale-band', 12 );
		$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = '012';

		wp_delete_post( 12, true );

		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
	}

	public function test_profile_deletion_uses_stable_complete_bounded_uncached_batches(): void {
		$this->addProfile( 12, 'the-band' );
		for ( $term_id = 1000; $term_id < 1100; ++$term_id ) {
			$this->addTerm( $term_id, 'malformed-band-' . $term_id, 12 );
			$GLOBALS['ec_test']['blogs'][1]['term_meta'][ $term_id ]['_artist_profile_id'] = '12broken';
		}
		for ( $term_id = 1100; $term_id < 1305; ++$term_id ) {
			$this->addTerm( $term_id, 'stale-band-' . $term_id, 12 );
		}

		wp_delete_post( 12, true );

		$this->assertSame( '12broken', $GLOBALS['ec_test']['blogs'][1]['term_meta'][1000]['_artist_profile_id'] );
		$this->assertSame( '12broken', $GLOBALS['ec_test']['blogs'][1]['term_meta'][1099]['_artist_profile_id'] );
		foreach ( range( 1100, 1304 ) as $term_id ) {
			$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][ $term_id ] );
		}
		foreach ( $GLOBALS['ec_test']['get_terms_calls'] as $args ) {
			$this->assertSame( 100, $args['number'] );
			$this->assertSame( 'term_id', $args['orderby'] );
			$this->assertSame( 'ASC', $args['order'] );
			$this->assertFalse( $args['cache_results'] );
			$this->assertFalse( $args['update_term_meta_cache'] );
		}
		$this->assertSame( array( 0, 100, 100, 100, 100 ), array_column( $GLOBALS['ec_test']['get_terms_calls'], 'offset' ) );
	}

	public function test_profile_deletion_keeps_adjacent_large_integer_references_distinct(): void {
		$profile_id = 9007199254740993;
		$this->addProfile( $profile_id, 'large-id-band' );
		$this->addTerm( 101, 'large-id-band', $profile_id );
		$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = array(
			'09007199254740993',
			'9007199254740992',
		);

		wp_delete_post( $profile_id, true );

		$this->assertSame(
			array( '9007199254740992' ),
			$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id']
		);
	}

	public function test_profile_deletion_skips_malformed_numeric_cast_matches_without_looping(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'malformed-band', 12 );
		$this->addTerm( 102, 'stale-band', 12 );
		$this->addTerm( 103, 'different-numeric-band', 12 );
		$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = '12broken';
		$GLOBALS['ec_test']['blogs'][1]['term_meta'][103]['_artist_profile_id'] = '12.5';

		wp_delete_post( 12, true );

		$this->assertSame( '12broken', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][102] );
		$this->assertSame( '12.5', $GLOBALS['ec_test']['blogs'][1]['term_meta'][103]['_artist_profile_id'] );
		$this->assertCount( 3, $GLOBALS['ec_test']['get_terms_calls'] );
		$this->assertSame( 2, $GLOBALS['ec_test']['get_terms_calls'][2]['offset'] );
	}

	public function test_profile_deletion_does_not_mutate_wrong_taxonomy_or_unrelated_terms(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$this->addTerm( 102, 'genre-term', 12, 'genre' );
		$this->addTerm( 103, 'other-band', 13 );

		wp_delete_post( 12, true );

		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][102]['_artist_profile_id'] );
		$this->assertSame( 13, $GLOBALS['ec_test']['blogs'][1]['term_meta'][103]['_artist_profile_id'] );
	}

	public function test_profile_deletion_does_not_mutate_a_colliding_main_blog_post(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['blogs'][1]['posts'][12] = (object) array(
			'ID'          => 12,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Unrelated post',
			'post_name'   => 'unrelated-post',
		);
		$GLOBALS['ec_test']['blogs'][1]['post_meta'][12]['_artist_profile_id'] = 'unchanged';

		wp_delete_post( 12, true );

		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
		$this->assertSame( 'unchanged', $GLOBALS['ec_test']['blogs'][1]['post_meta'][12]['_artist_profile_id'] );
	}

	public function test_profile_deletion_restores_the_callers_artist_blog(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band', 12 );

		wp_delete_post( 12, true );

		$this->assertSame( 4, $GLOBALS['ec_test']['current_blog_id'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
	}

	public function test_slug_renames_do_not_break_a_valid_id_binding(): void {
		$this->addProfile( 12, 'renamed-profile', 101 );
		$this->addTerm( 101, 'original-term-slug', 12 );

		$this->assertSame( 101, ec_get_artist_term_id( 12 ) );
		$this->assertSame( 12, ec_get_artist_profile_id( 101 ) );
	}

	public function test_integrity_backfill_uses_a_new_migration_key_on_upgraded_sites(): void {
		$this->addProfile( 12, 'the-band' );
		$this->addTerm( 101, 'the-band' );
		$GLOBALS['ec_test']['options']['extrachill_artist_platform_term_binding_backfill'] = '1.0.0';

		ec_backfill_artist_term_bindings();

		$this->assertSame( 101, $GLOBALS['ec_test']['blogs'][4]['post_meta'][12]['_artist_term_id'] );
		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
		$this->assertSame( '2.0.0', $GLOBALS['ec_test']['options']['extrachill_artist_platform_term_binding_integrity_backfill'] );
	}
}
