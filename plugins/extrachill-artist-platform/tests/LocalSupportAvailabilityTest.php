<?php

require_once __DIR__ . '/support/base-test-case.php';

final class LocalSupportAvailabilityTest extends EC_Artist_Platform_TestCase {
	private $manager_id;
	private $stale_manager_id;
	private $profiles;
	private $producer_filter_on;

	protected function setUp(): void {
		parent::setUp();

		$this->manager_id       = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stale_manager_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Seed the canonical local scenes the extrachill-users resolver reads.
		switch_to_blog( ec_get_blog_id( 'events' ) );
		try {
			if ( ! taxonomy_exists( 'location' ) ) {
				register_taxonomy( 'location', 'post', array( 'public' => true ) );
			}
			// Canonical locations are hierarchical: region > state > city.
			$region = wp_insert_term( 'United States', 'location', array( 'slug' => 'united-states' ) );
			if ( is_wp_error( $region ) ) {
				$region_id = (int) ( get_term_by( 'slug', 'united-states', 'location' )->term_id ?? 0 );
			} else {
				$region_id = (int) $region['term_id'];
			}
			foreach ( array(
				'South Carolina' => 'south-carolina',
				'Texas'          => 'texas',
			) as $state_name => $state_slug ) {
				$state                    = wp_insert_term( $state_name, 'location', array(
					'slug'   => $state_slug,
					'parent' => $region_id,
				) );
				$state_ids[ $state_slug ] = is_wp_error( $state )
					? (int) ( get_term_by( 'slug', $state_slug, 'location' )->term_id ?? 0 )
					: (int) $state['term_id'];
			}
			foreach ( array(
				'Charleston' => array( 'charleston', 'south-carolina' ),
				'Austin'     => array( 'austin', 'texas' ),
			) as $name => $parts ) {
				if ( ! get_term_by( 'slug', $parts[0], 'location' ) ) {
					wp_insert_term( $name, 'location', array(
						'slug'   => $parts[0],
						'parent' => $state_ids[ $parts[1] ],
					) );
				}
			}
		} finally {
			restore_current_blog();
		}
		update_user_meta( $this->manager_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, 'charleston' );

		$this->profiles = array();
		$index          = 0;
		foreach ( array(
			'Test Band'      => array(
				'scene'   => 'charleston',
				'members' => array( $this->manager_id, $this->stale_manager_id ),
				'genres'  => array( 'rock' ),
			),
			'Excluded Band'  => array(
				'scene'   => 'charleston',
				'members' => array( $this->manager_id ),
				'genres'  => array( 'hip-hop' ),
			),
			'Austin Band'    => array(
				'scene'   => 'austin',
				'members' => array( $this->manager_id ),
				'genres'  => array(),
			),
			'Opted Out Band' => array(
				'scene'     => 'charleston',
				'members'   => array( $this->manager_id ),
				'opted_out' => true,
				'genres'    => array(),
			),
		) as $name => $config ) {
			$profile_id                    = $this->create_artist_profile( $name );
			$term_id                       = $this->create_artist_term( 'ls-band-' . ( ++$index ), $profile_id );
			$this->profiles[ $profile_id ] = $config;
			switch_to_blog( $this->artist_blog_id() );
			update_post_meta( $profile_id, '_artist_term_id', $term_id );
			if ( empty( $config['opted_out'] ) ) {
				update_post_meta( $profile_id, '_local_support_available', '1' );
			}
			update_post_meta( $profile_id, '_local_support_scene', $config['scene'] );
			update_post_meta( $profile_id, '_local_city', 'Display City' );
			foreach ( $config['members'] as $member_id ) {
				$members   = get_post_meta( $profile_id, '_artist_member_ids', true );
				$members   = is_array( $members ) ? $members : array();
				$members[] = $member_id;
				update_post_meta( $profile_id, '_artist_member_ids', array_map( 'intval', array_unique( $members ) ) );
			}
			if ( ! empty( $config['genres'] ) ) {
				$genre_ids = array();
				foreach ( $config['genres'] as $genre_slug ) {
					$term = get_term_by( 'slug', $genre_slug, 'genre' );
					if ( $term ) {
						$genre_ids[] = (int) $term->term_id;
					} else {
						$created     = wp_insert_term( strtoupper( $genre_slug ), 'genre', array( 'slug' => $genre_slug ) );
						$genre_ids[] = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
					}
				}
				wp_set_object_terms( $profile_id, array_filter( $genre_ids ), 'genre', false );
			}
			restore_current_blog();
			foreach ( $config['members'] as $member_id ) {
				$ids   = get_user_meta( $member_id, '_artist_profile_ids', true );
				$ids   = is_array( $ids ) ? $ids : array();
				$ids[] = $profile_id;
				update_user_meta( $member_id, '_artist_profile_ids', array_map( 'intval', array_unique( $ids ) ) );
			}
		}

		$this->producer_filter_on             = new stdClass();
		$this->producer_filter_on->authorized = array();
	}

	/**
	 * Authorize a producer through the real permission filter.
	 */
	private function authorize_producer( string $producer ): void {
		$this->producer_filter_on->authorized[] = $producer;
		$state                                  = $this->producer_filter_on;
		$this->ec_inject_filter(
			'extrachill_artist_platform_local_support_producer_authorized',
			static function ( $authorized, $candidate_producer ) use ( $state ) {
				return in_array( $candidate_producer, $state->authorized, true );
			},
			100
		);
	}

	public function test_manager_abilities_enforce_the_exact_artist_on_permission_and_execution_paths(): void {
		$get    = wp_get_ability( 'extrachill/artist-get-local-support-availability' );
		$update = wp_get_ability( 'extrachill/artist-update-local-support-availability' );
		$ids    = array_keys( $this->profiles );
		$owned  = $ids[0];
		$other  = $ids[1];

		wp_set_current_user( $this->manager_id );
		$permitted = $get->check_permissions( array( 'id' => $owned ) );
		if ( ! $permitted ) {
			$this->fail( wp_json_encode( array(
				'owned'       => $owned,
				'user_meta'   => get_user_meta( $this->manager_id, '_artist_profile_ids', true ),
				'manage'      => ec_can_manage_artist( $this->manager_id, $owned ),
				'artists_for' => ec_get_artists_for_user( $this->manager_id ),
			) ) );
		}
		$this->assertTrue( $permitted );
		$this->assertTrue( $update->check_permissions( array(
			'id'        => $owned,
			'available' => true,
		) ) );

		// Narrow the manager to one artist; the other profiles are denied.
		update_user_meta( $this->manager_id, '_artist_profile_ids', array( $other ) );

		$this->assertFalse( $get->check_permissions( array( 'id' => $owned ) ) );
		$this->assertSame( 'artist_access_denied', extrachill_artist_platform_ability_get_local_support_availability( array( 'id' => $owned ) )->get_error_code() );
		$this->assertSame( 'artist_access_denied', extrachill_artist_platform_ability_update_local_support_availability( array(
			'id'        => $owned,
			'available' => false,
		) )->get_error_code() );
	}

	public function test_opt_in_defaults_to_manager_local_scene_and_validates_overrides(): void {
		wp_set_current_user( $this->manager_id );
		$ids   = array_keys( $this->profiles );
		$owned = $ids[0];
		delete_post_meta( $owned, '_local_support_available' );
		delete_post_meta( $owned, '_local_support_scene' );

		$result = extrachill_artist_platform_ability_update_local_support_availability(
			array(
				'id'        => $owned,
				'available' => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->fail( 'update failed: ' . $result->get_error_code() . ' ' . $result->get_error_message() );
		}
		$this->assertTrue( $result['available'] );
		$this->assertSame( 'charleston', $result['scene']['slug'] );
		switch_to_blog( $this->artist_blog_id() );
		try {
			$stored_scene = get_post_meta( $owned, '_local_support_scene', true );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 'charleston', $stored_scene );

		$invalid = extrachill_artist_platform_ability_update_local_support_availability(
			array(
				'id'         => $owned,
				'available'  => true,
				'scene_slug' => 'made-up-city',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'location_not_found', $invalid->get_error_code() );
		switch_to_blog( $this->artist_blog_id() );
		try {
			$stored_scene = get_post_meta( $owned, '_local_support_scene', true );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 'charleston', $stored_scene );

		$override = extrachill_artist_platform_ability_update_local_support_availability(
			array(
				'id'         => $owned,
				'available'  => true,
				'scene_slug' => 'austin',
			)
		);
		$this->assertSame( 'austin', $override['scene']['slug'] );
		switch_to_blog( $this->artist_blog_id() );
		try {
			$override_scene = get_post_meta( $owned, '_local_support_scene', true );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 'austin', $override_scene );
	}

	public function test_opt_out_immediately_removes_artist_from_candidate_resolution(): void {
		// Candidate resolution requires canonical artist term IDs, which serialize
		// through the MySQL-only binding lock.
		$this->ec_require_mysql_advisory_locks();
		$this->authorize_producer( 'extrachill-events-local-support' );
		$ids   = array_keys( $this->profiles );
		$owned = $ids[0];
		$excl  = $ids[1];
		wp_set_current_user( $this->manager_id );

		$before = extrachill_artist_platform_resolve_local_support_candidates( 'extrachill-events-local-support', 'charleston' );
		if ( is_wp_error( $before ) ) {
			$this->fail( 'resolve failed: ' . $before->get_error_code() . ' ' . $before->get_error_message() );
		}
		$this->assertSame( array( $owned, $excl ), array_map( 'intval', array_column( $before['candidates'], 'artist_profile_id' ) ) );

		extrachill_artist_platform_ability_update_local_support_availability( array(
			'id'        => $owned,
			'available' => false,
		) );
		$after = extrachill_artist_platform_resolve_local_support_candidates( 'extrachill-events-local-support', 'charleston' );
		$this->assertSame( array( $excl ), array_map( 'intval', array_column( $after['candidates'], 'artist_profile_id' ) ) );
	}

	public function test_private_candidate_contract_filters_and_discloses_only_valid_manager_ids(): void {
		// Candidate resolution requires canonical artist term IDs, which serialize
		// through the MySQL-only binding lock.
		$this->ec_require_mysql_advisory_locks();
		$this->authorize_producer( 'extrachill-events-local-support' );
		$ids   = array_keys( $this->profiles );
		$owned = $ids[0];
		$excl  = $ids[1];
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'extrachill/artist-query-local-support-candidates' );
		$input   = array(
			'producer'           => 'extrachill-events-local-support',
			'scene_slug'         => 'charleston',
			'genres'             => array( 'rock' ),
			'exclude_artist_ids' => array( $excl ),
		);

		$this->assertFalse( $ability->check_permissions( $input ) );
		$this->assertSame( 'local_support_producer_forbidden', extrachill_artist_platform_ability_query_local_support_candidates( $input )->get_error_code() );

		$this->authorize_producer( 'extrachill-events-local-support' );
		$this->assertTrue( $ability->check_permissions( $input ) );
		$result = $ability->execute( $input );

		$this->assertNotEmpty( $result['candidates'] );
		$candidate = $result['candidates'][0];
		$this->assertSame( $owned, (int) $candidate['artist_profile_id'] );
		$this->assertSame( 'Display City', $candidate['local_city'] );
		$this->assertArrayNotHasKey( 'email', $candidate );
		$this->assertArrayNotHasKey( 'phone', $candidate );
		$this->assertArrayNotHasKey( 'subscribers', $candidate );
		$this->assertStringNotContainsString( '@example.com', wp_json_encode( $result ) );
	}

	public function test_genre_filter_intersects_resolved_slugs_instead_of_exact_matching(): void {
		// Candidate resolution requires canonical artist term IDs, which serialize
		// through the MySQL-only binding lock.
		$this->ec_require_mysql_advisory_locks();
		$this->authorize_producer( 'extrachill-events-local-support' );
		$ids   = array_keys( $this->profiles );
		$owned = $ids[0];
		$excl  = $ids[1];

		// 'rap' resolves to the hip-hop slug; the free-text legacy values would
		// never survive an exact string comparison.
		$result = extrachill_artist_platform_resolve_local_support_candidates(
			'extrachill-events-local-support',
			'charleston',
			array( 'rap' )
		);
		if ( is_wp_error( $result ) ) {
			$this->fail( 'resolve failed: ' . $result->get_error_code() . ' ' . $result->get_error_message() );
		}
		if ( array( $excl ) !== array_map( 'intval', array_column( $result['candidates'], 'artist_profile_id' ) ) ) {
			switch_to_blog( $this->artist_blog_id() );
			$debug = array(
				'available' => get_posts( array(
					'post_type'   => 'artist_profile',
					'post_status' => 'publish',
					'fields'      => 'ids',
					'meta_key'    => EXTRACHILL_ARTIST_LOCAL_SUPPORT_AVAILABLE_META,
					'meta_value'  => '1',
				) ),
				'term_ids'  => array_map( fn( $id ) => ec_get_artist_term_id( $id ), array_keys( $this->profiles ) ),
				'managers'  => array_map( fn( $id ) => get_post_meta( $id, '_artist_member_ids', true ), array_keys( $this->profiles ) ),
				'scenes'    => array_map( fn( $id ) => get_post_meta( $id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_SCENE_META, true ), array_keys( $this->profiles ) ),
			);
			restore_current_blog();
			$this->fail( wp_json_encode( $debug ) );
		}
		$this->assertSame( array( $excl ), array_map( 'intval', array_column( $result['candidates'], 'artist_profile_id' ) ) );

		$unresolvable = extrachill_artist_platform_resolve_local_support_candidates(
			'extrachill-events-local-support',
			'charleston',
			array( 'idk' )
		);
		$this->assertSame( array(), $unresolvable['candidates'] );
		$this->assertGreaterThan( 0, $owned );
	}
}
