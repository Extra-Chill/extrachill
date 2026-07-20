<?php

use PHPUnit\Framework\TestCase;

final class ArtistTermBindingTest extends TestCase {
	protected function setUp(): void {
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

	private function addTerm( $id, $slug, $profile_id = 0 ) {
		$GLOBALS['ec_test']['blogs'][1]['terms'][ $id ] = (object) array(
			'term_id'  => $id,
			'taxonomy' => 'artist',
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
		$this->assertArrayNotHasKey( '_artist_term_id', $GLOBALS['ec_test']['blogs'][4]['post_meta'][12] );

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

	public function test_deleting_a_colliding_main_blog_post_does_not_unbind_the_profile(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );
		$GLOBALS['ec_test']['current_blog_id'] = 1;

		ec_delete_artist_profile_term_binding( 12 );

		$this->assertSame( 12, $GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] );
	}

	public function test_profile_deletion_cleans_the_reciprocal_term_reference(): void {
		$this->addProfile( 12, 'the-band', 101 );
		$this->addTerm( 101, 'the-band', 12 );

		ec_delete_artist_profile_term_binding( 12 );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][101] );
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
