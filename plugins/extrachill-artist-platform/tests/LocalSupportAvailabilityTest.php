<?php

use PHPUnit\Framework\TestCase;

final class LocalSupportAvailabilityTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'current_user_id' => 7,
			'managed_artists' => array( 7 => array( 42, 43, 44, 45 ) ),
			'canonical_locations' => array(
				'charleston' => array( 'term_id' => 10, 'name' => 'Charleston', 'slug' => 'charleston' ),
				'austin'     => array( 'term_id' => 11, 'name' => 'Austin', 'slug' => 'austin' ),
			),
			'local_scenes' => array(
				7 => array( 'term_id' => 10, 'name' => 'Charleston', 'slug' => 'charleston' ),
			),
			'blogs' => array(
				1 => array(
					'terms' => array(
						142 => (object) array( 'term_id' => 142, 'taxonomy' => 'artist', 'slug' => 'test-band' ),
						143 => (object) array( 'term_id' => 143, 'taxonomy' => 'artist', 'slug' => 'excluded-band' ),
						144 => (object) array( 'term_id' => 144, 'taxonomy' => 'artist', 'slug' => 'austin-band' ),
						145 => (object) array( 'term_id' => 145, 'taxonomy' => 'artist', 'slug' => 'opted-out-band' ),
					),
					'term_meta' => array(
						142 => array( '_artist_profile_id' => 42 ),
						143 => array( '_artist_profile_id' => 43 ),
						144 => array( '_artist_profile_id' => 44 ),
						145 => array( '_artist_profile_id' => 45 ),
					),
				),
				4 => array(
					'posts' => array(
						42 => $this->artist( 42, 'Test Band', 'test-band' ),
						43 => $this->artist( 43, 'Excluded Band', 'excluded-band' ),
						44 => $this->artist( 44, 'Austin Band', 'austin-band' ),
						45 => $this->artist( 45, 'Opted Out Band', 'opted-out-band' ),
					),
					'post_meta' => array(
						42 => $this->artist_meta( 142, 'charleston', 'Rock', array( 7, 8, 9 ) ),
						43 => $this->artist_meta( 143, 'charleston', 'Rock', array( 7 ) ),
						44 => $this->artist_meta( 144, 'austin', 'Rock', array( 7 ) ),
						45 => array_merge( $this->artist_meta( 145, 'charleston', 'Rock', array( 7 ) ), array( '_local_support_available' => '' ) ),
					),
				),
			),
			'user_meta' => array(
				7 => array( '_artist_profile_ids' => array( 42, 43, 44, 45 ) ),
				8 => array( '_artist_profile_ids' => array() ),
			),
			'users' => array(
				7 => (object) array( 'ID' => 7, 'user_login' => 'manager', 'user_email' => 'manager@example.com', 'display_name' => 'Manager' ),
				8 => (object) array( 'ID' => 8, 'user_login' => 'stale', 'user_email' => 'stale@example.com', 'display_name' => 'Stale' ),
			),
		);
		extrachill_artist_platform_register_abilities();
	}

	private function artist( int $id, string $name, string $slug ): object {
		return (object) array(
			'ID'           => $id,
			'post_type'    => 'artist_profile',
			'post_status'  => 'publish',
			'post_title'   => $name,
			'post_name'    => $slug,
			'post_content' => 'Public artist bio.',
		);
	}

	private function artist_meta( int $term_id, string $scene, string $genre, array $member_ids ): array {
		return array(
			'_artist_term_id'         => $term_id,
			'_artist_member_ids'      => $member_ids,
			'_local_support_available' => '1',
			'_local_support_scene'     => $scene,
			'_genre'                   => $genre,
			'_local_city'              => 'Display City',
		);
	}

	public function test_manager_abilities_enforce_the_exact_artist_on_permission_and_execution_paths(): void {
		$get    = wp_get_ability( 'extrachill/artist-get-local-support-availability' );
		$update = wp_get_ability( 'extrachill/artist-update-local-support-availability' );

		$this->assertTrue( $get->check_permissions( array( 'id' => 42 ) ) );
		$this->assertTrue( $update->check_permissions( array( 'id' => 42, 'available' => true ) ) );

		$GLOBALS['ec_test']['managed_artists'][7] = array( 43 );
		$this->assertFalse( $get->check_permissions( array( 'id' => 42 ) ) );
		$this->assertFalse( $update->check_permissions( array( 'id' => 42, 'available' => false ) ) );
		$this->assertSame( 'artist_access_denied', extrachill_artist_platform_ability_get_local_support_availability( array( 'id' => 42 ) )->get_error_code() );
		$this->assertSame( 'artist_access_denied', extrachill_artist_platform_ability_update_local_support_availability( array( 'id' => 42, 'available' => false ) )->get_error_code() );
	}

	public function test_opt_in_defaults_to_manager_local_scene_and_validates_overrides(): void {
		delete_post_meta( 42, '_local_support_available' );
		delete_post_meta( 42, '_local_support_scene' );

		$result = extrachill_artist_platform_ability_update_local_support_availability(
			array( 'id' => 42, 'available' => true )
		);
		$this->assertTrue( $result['available'] );
		$this->assertSame( 'charleston', $result['scene']['slug'] );
		$this->assertSame( 'charleston', get_post_meta( 42, '_local_support_scene', true ) );

		$invalid = extrachill_artist_platform_ability_update_local_support_availability(
			array( 'id' => 42, 'available' => true, 'scene_slug' => 'made-up-city' )
		);
		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'location_not_found', $invalid->get_error_code() );
		$this->assertSame( 'charleston', get_post_meta( 42, '_local_support_scene', true ) );

		$override = extrachill_artist_platform_ability_update_local_support_availability(
			array( 'id' => 42, 'available' => true, 'scene_slug' => 'austin' )
		);
		$this->assertSame( 'austin', $override['scene']['slug'] );
	}

	public function test_opt_out_immediately_removes_artist_from_candidate_resolution(): void {
		$GLOBALS['ec_test']['authorized_local_support_producers'] = array( 'extrachill-events-local-support' );

		$before = extrachill_artist_platform_resolve_local_support_candidates( 'extrachill-events-local-support', 'charleston' );
		$this->assertSame( array( 42, 43 ), array_column( $before['candidates'], 'artist_profile_id' ) );

		extrachill_artist_platform_ability_update_local_support_availability( array( 'id' => 42, 'available' => false ) );
		$after = extrachill_artist_platform_resolve_local_support_candidates( 'extrachill-events-local-support', 'charleston' );
		$this->assertSame( array( 43 ), array_column( $after['candidates'], 'artist_profile_id' ) );
	}

	public function test_private_candidate_contract_filters_and_discloses_only_valid_manager_ids(): void {
		$ability = wp_get_ability( 'extrachill/artist-query-local-support-candidates' );
		$input   = array(
			'producer'           => 'extrachill-events-local-support',
			'scene_slug'         => 'charleston',
			'genre'              => 'rock',
			'exclude_artist_ids' => array( 43 ),
		);

		$this->assertFalse( $ability->check_permissions( $input ) );
		$this->assertSame( 'local_support_producer_forbidden', extrachill_artist_platform_ability_query_local_support_candidates( $input )->get_error_code() );

		$GLOBALS['ec_test']['authorized_local_support_producers'] = array( 'extrachill-events-local-support' );
		$this->assertTrue( $ability->check_permissions( $input ) );
		$result = $ability->execute( $input );

		$this->assertCount( 1, $result['candidates'] );
		$candidate = $result['candidates'][0];
		$this->assertSame( 42, $candidate['artist_profile_id'] );
		$this->assertSame( 142, $candidate['artist_term_id'] );
		$this->assertSame( array( 7 ), $candidate['manager_user_ids'] );
		$this->assertSame( 'Display City', $candidate['local_city'] );
		$this->assertArrayNotHasKey( 'email', $candidate );
		$this->assertArrayNotHasKey( 'phone', $candidate );
		$this->assertArrayNotHasKey( 'subscribers', $candidate );
		$this->assertStringNotContainsString( 'manager@example.com', json_encode( $result ) );
	}
}
