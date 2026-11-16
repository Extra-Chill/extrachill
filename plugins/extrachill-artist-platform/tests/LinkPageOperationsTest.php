<?php

require_once __DIR__ . '/support/base-test-case.php';

final class LinkPageOperationsTest extends EC_Artist_Platform_TestCase {
	private $owner_id;
	private $profile_id;
	private $link_page_id;

	protected function setUp(): void {
		parent::setUp();

		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		ec_register_link_page_operation_provider( 'artist-platform', array( \ExtraChillArtistPlatform\Artist_Link_Pages::class, 'operation_provider' ) );

		$this->owner_id   = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->profile_id = $this->create_artist_profile( 'Test Owner', array( 'post_name' => 'test-owner' ) );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->link_page_id = (int) self::factory()->post->create(
				array(
					'post_type'   => 'ec_link_page',
					'post_status' => 'publish',
					'post_name'   => 'test-page',
				)
			);
			update_post_meta( $this->link_page_id, EC_LINK_PAGE_OWNER_META_KEY, 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->profile_id );
		} finally {
			restore_current_blog();
		}
	}

	protected function tearDown(): void {
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		parent::tearDown();
	}

	private function resetRegistry( $registry, $property_name ): void {
		$reflection = new ReflectionObject( $registry );
		$property   = $reflection->getProperty( $property_name );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );
	}

	private function authorizeOwner(): void {
		wp_set_current_user( $this->owner_id );
		$this->create_artist_membership( $this->owner_id, $this->profile_id );
		switch_to_blog( $this->artist_blog_id() );
		restore_current_blog();
	}

	public function test_current_owner_can_read_by_id_and_reference_deterministically(): void {
		$this->authorizeOwner();

		switch_to_blog( $this->artist_blog_id() );
		try {
			$reference    = 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->profile_id;
			$by_id        = ec_read_link_page( $this->link_page_id );
			$by_reference = ec_read_link_page( $reference );

			$this->assertSame( $this->profile_id, (int) $by_id['artist_id'] );
			$this->assertSame( $this->link_page_id, (int) $by_id['link_page_id'] );
			$this->assertEquals( $by_id, $by_reference );
			$this->assertSame( $this->link_page_id, (int) ec_resolve_link_page_operation_target( $this->link_page_id )['link_page_id'] );
			$this->assertSame( $this->link_page_id, (int) ec_resolve_link_page_operation_target( $reference )['link_page_id'] );
		} finally {
			restore_current_blog();
		}
	}

	public function test_current_wrappers_preserve_read_and_save_payloads(): void {
		$this->authorizeOwner();

		switch_to_blog( $this->artist_blog_id() );
		try {
			$expected = ec_get_link_page_data( $this->profile_id, $this->link_page_id );

			$this->assertEquals(
				$expected,
				extrachill_artist_platform_ability_get_link_page_data( array(
					'artist_id'    => $this->profile_id,
					'link_page_id' => $this->link_page_id,
				) )
			);

			// Saving allocates under a MySQL GET_LOCK advisory lock; the read
			// contract above is portable, the save half needs MySQL.
			$this->ec_require_mysql_advisory_locks();

			$result = extrachill_artist_platform_ability_save_link_page_links(
				array(
					'artist_id' => $this->profile_id,
					'links'     => array(),
				)
			);

			$this->assertNotWPError( $result );
			$this->assertSame( $this->profile_id, (int) $result['artist_id'] );
			$this->assertSame( $this->link_page_id, (int) $result['link_page_id'] );
			$this->assertSame( array(), $result['links'] );
			$this->assertEquals( $result, ec_get_link_page_data( $this->profile_id, $this->link_page_id ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_unauthenticated_and_unrelated_callers_fail_at_operation_boundary(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertSame( 'link_page_operation_forbidden', ec_read_link_page( $this->link_page_id )->get_error_code() );
			$this->assertSame( 'link_page_operation_forbidden', ec_save_link_page( $this->link_page_id, array( 'bio' => 'Nope' ) )->get_error_code() );

			wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$this->assertSame( 'link_page_operation_forbidden', ec_read_link_page( $this->link_page_id )->get_error_code() );
			$this->assertEmpty( get_post_meta( $this->link_page_id, '_link_page_bio_text', true ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_unknown_operation_is_rejected_by_owner_authorization(): void {
		$this->authorizeOwner();

		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_prepare_link_page_operation( $this->link_page_id, 'delete' );

			$this->assertSame( 'link_page_operation_forbidden', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_malformed_missing_divergent_duplicate_and_unavailable_targets_fail_closed(): void {
		$this->authorizeOwner();

		switch_to_blog( $this->artist_blog_id() );
		try {
			$reference = 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->profile_id;

			$this->assertSame( 'invalid_link_page_operation_target', ec_read_link_page( array() )->get_error_code() );
			$this->assertSame( 'invalid_link_page_owner_reference', ec_read_link_page( 'post/4/type/20' )->get_error_code() );
			$this->assertSame( 'invalid_link_page', ec_read_link_page( 999999 )->get_error_code() );
			$this->assertSame( 'invalid_link_page_owner_blog', ec_read_link_page( 'post:99:artist_profile:20' )->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
	}

	public function test_missing_provider_and_provider_exceptions_fail_closed(): void {
		$this->authorizeOwner();
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertSame( 'link_page_operation_provider_missing', ec_read_link_page( $this->link_page_id )->get_error_code() );

			ec_register_link_page_operation_provider(
				'throwing',
				static function () {
					throw new RuntimeException( 'failed' );
				}
			);
			$this->assertSame( 'link_page_operation_provider_exception', ec_read_link_page( $this->link_page_id )->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_provider_authorization_exception_restores_context(): void {
		$this->authorizeOwner();
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		ec_register_link_page_operation_provider(
			'throwing-authorization',
			static function () {
				return array(
					'authorize' => static function () {
						switch_to_blog( 7 );
						throw new RuntimeException( 'failed' );
					},
					'read'      => static function () {
						return array();
					},
					'save'      => static function () {
						return array();
					},
				);
			}
		);

		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_read_link_page( $this->link_page_id );

			$this->assertSame( 'link_page_operation_provider_exception', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
		$this->assertFalse( $GLOBALS['switched'] ?? false );
	}

	public function test_provider_read_exception_restores_context_and_fails_closed(): void {
		$this->authorizeOwner();
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		ec_register_link_page_operation_provider(
			'throwing-read',
			static function () {
				return array(
					'authorize' => '__return_true',
					'read'      => static function () {
						switch_to_blog( 7 );
						throw new RuntimeException( 'failed' );
					},
					'save'      => static function () {
						return array();
					},
				);
			}
		);

		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_read_link_page( $this->link_page_id );

			$this->assertSame( 'link_page_operation_provider_exception', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
		$this->assertFalse( $GLOBALS['switched'] ?? false );
	}

	public function test_owner_change_during_authorization_prevents_operation_execution(): void {
		$this->authorizeOwner();
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		$target_page    = $this->link_page_id;
		$second_profile = $this->create_artist_profile( 'Mutator Owner', array( 'post_name' => 'mutator-owner' ) );
		ec_register_link_page_operation_provider(
			'ownership-mutator',
			static function () use ( $target_page, $second_profile ) {
				return array(
					'authorize' => static function () use ( $target_page, $second_profile ) {
						update_post_meta( $target_page, EC_LINK_PAGE_OWNER_META_KEY, 'post:4:artist_profile:' . $second_profile );
						return true;
					},
					'read'      => static function () {
						$GLOBALS['ec_test_operation_executed'] = true;
						return array();
					},
					'save'      => static function () {
						return array();
					},
				);
			}
		);

		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_read_link_page( $this->link_page_id );

			// The owner reference is the only binding: once it moves, the
			// original owner no longer resolves any page.
			$this->assertSame( 'link_page_not_found', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertArrayNotHasKey( 'ec_test_operation_executed', $GLOBALS );
	}

	public function test_operation_registry_is_append_only_and_provider_order_is_deterministic(): void {
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		$GLOBALS['ec_test_provider_order'] = array();
		foreach ( array( array( 'z-provider', 20 ), array( 'b-provider', 5 ), array( 'a-provider', 5 ) ) as $provider ) {
			ec_register_link_page_operation_provider(
				$provider[0],
				static function () use ( $provider ) {
					$GLOBALS['ec_test_provider_order'][] = $provider[0];
					return null;
				},
				$provider[1]
			);
		}

		$this->authorizeOwner();
		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_read_link_page( $this->link_page_id );

			$this->assertSame( 'link_page_operation_provider_missing', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( array( 'a-provider', 'b-provider', 'z-provider' ), $GLOBALS['ec_test_provider_order'] );
		$this->assertFalse( method_exists( ec_link_page_operation_provider_registry(), 'reset' ) );
		$this->assertFalse( method_exists( ec_link_page_operation_provider_registry(), 'unregister' ) );
	}

	/**
	 * Build the definition's snapshot exactly as a refresh would, without the
	 * refresh's MySQL advisory lock.
	 */
	private function storeArtistSnapshot(): array {
		$definition = \ExtraChillArtistPlatform\Artist_Link_Pages::class;
		$reference  = $definition::owner_reference( $this->profile_id );
		$profile_id = $this->profile_id;
		$build      = Closure::bind(
			static function () use ( $definition, $profile_id, $reference ) {
				return $definition::build_snapshot( $profile_id, $reference );
			},
			null,
			\ExtraChill\LinkPages\Owned_Link_Pages::class
		);
		$snapshot   = $build();
		$this->assertIsArray( $snapshot );
		switch_to_blog( ec_get_link_page_storage_blog_id() );
		try {
			update_post_meta( $this->link_page_id, $definition::SNAPSHOT_META_KEY, $snapshot );
		} finally {
			restore_current_blog();
		}
		return $snapshot;
	}

	/** Remove any snapshot a canonical save hook already refreshed. */
	private function deleteArtistSnapshot(): void {
		switch_to_blog( ec_get_link_page_storage_blog_id() );
		try {
			delete_post_meta( $this->link_page_id, \ExtraChillArtistPlatform\Artist_Link_Pages::SNAPSHOT_META_KEY );
		} finally {
			restore_current_blog();
		}
	}

	/** Seed artist identity on the canonical profile. */
	private function seedArtistIdentity(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			wp_update_post(
				array(
					'ID'           => $this->profile_id,
					'post_excerpt' => 'Artist excerpt.',
					'post_content' => 'Artist biography.',
				)
			);
			update_post_meta( $this->profile_id, '_website_url', 'https://owner.example' );
			update_post_meta(
				$this->profile_id,
				'_artist_profile_social_links',
				array(
					array(
						'id'   => 'social-1',
						'type' => 'instagram',
						'url'  => 'https://instagram.com/test-owner',
					),
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	public function test_artist_definition_reference_management_url_and_registration(): void {
		$definition = \ExtraChillArtistPlatform\Artist_Link_Pages::class;

		$this->assertSame( 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->profile_id, $definition::owner_reference( $this->profile_id ) );
		$this->assertSame( ec_get_site_url( 'artist' ) . '/manage-link-page?artist_id=' . $this->profile_id, $definition::management_url( $this->profile_id ) );
		$this->assertSame( 'MusicGroup', ( new ReflectionClassConstant( $definition, 'SCHEMA_TYPE' ) )->getValue() );
		$this->assertTrue( is_subclass_of( $definition, \ExtraChill\LinkPages\Owned_Link_Pages::class ) );
		$names = array_column( ec_link_page_public_projection_registry()->snapshot(), 'callback', 'name' );
		$this->assertSame( array( $definition, 'public_projection_provider' ), $names['artist-platform'] );
		$this->assertFalse( function_exists( 'ec_artist_link_page_public_projection_provider' ) );
		$this->assertFalse( function_exists( 'ec_artist_link_page_operation_provider' ) );
		$this->assertFalse( function_exists( 'ec_artist_with_link_page_lock' ) );
	}

	public function test_artist_definition_authorizes_through_artist_membership(): void {
		$definition = \ExtraChillArtistPlatform\Artist_Link_Pages::class;

		$this->assertSame( 'artist_profile_link_page_forbidden', $definition::authorize( $this->profile_id )->get_error_code() );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'artist_profile_link_page_forbidden', $definition::authorize( $this->profile_id )->get_error_code() );

		$this->authorizeOwner();
		$this->assertTrue( $definition::authorize( $this->profile_id ) );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_artist_definition_snapshot_reads_canonical_identity(): void {
		$this->seedArtistIdentity();
		$snapshot = $this->storeArtistSnapshot();

		$this->assertSame( 'Test Owner', $snapshot['title'] );
		$this->assertSame( 'Artist excerpt.', $snapshot['description'] );
		$this->assertSame( 'https://owner.example', $snapshot['website'] );
		$this->assertSame(
			array(
				array(
					'type' => 'instagram',
					'url'  => 'https://instagram.com/test-owner',
				),
			),
			$snapshot['social_links']
		);
		$this->assertTrue( $snapshot['published'] );
		$this->assertSame( $this->profile_id, $snapshot['source']['artist_profile_post_id'] );
	}

	public function test_public_projection_renders_from_snapshot_with_page_bio_over_artist_bio(): void {
		$this->seedArtistIdentity();
		$this->storeArtistSnapshot();
		// A canonical change the snapshot has not seen must not leak into the
		// public page: rendering reads only the snapshot plus page content.
		switch_to_blog( $this->artist_blog_id() );
		wp_update_post(
			array(
				'ID'         => $this->profile_id,
				'post_title' => 'Renamed Owner',
			)
		);
		restore_current_blog();

		$projection = ec_get_link_page_public_projection( $this->link_page_id );
		$this->assertIsArray( $projection );
		$this->assertSame( 'Test Owner', $projection['display_title'] );
		$this->assertSame( 'Artist excerpt.', $projection['bio'] );
		$this->assertSame( (string) $this->profile_id, $projection['body_attributes']['data-extrch-artist-id'] );
		$this->assertSame( array( $this->profile_id ), $projection['legacy_head_arguments'] );
		$this->assertSame( 'ec_artist_link_page_enqueue_asset_extensions', $projection['assets'] );
		$this->assertSame( 'MusicGroup', $projection['seo']['schema'][0]['@type'] );
		// The same MusicGroup node the artist profile page publishes.
		switch_to_blog( $this->artist_blog_id() );
		$profile_url = get_permalink( $this->profile_id );
		restore_current_blog();
		$this->assertSame( $profile_url . '#musicgroup', $projection['seo']['schema'][0]['@id'] );
		$this->assertSame( $profile_url, $projection['seo']['schema'][0]['url'] );
		$this->assertSame( array( '@id' => $profile_url . '#musicgroup' ), $projection['seo']['schema'][1]['mainEntity'] );
		$this->assertSame( array( 'https://owner.example', 'https://instagram.com/test-owner' ), $projection['seo']['schema'][0]['sameAs'] );

		switch_to_blog( ec_get_link_page_storage_blog_id() );
		try {
			// The page-owned bio field ec_save_link_page_persistence() writes.
			update_post_meta( $this->link_page_id, '_link_page_bio_text', 'Page bio.' );
		} finally {
			restore_current_blog();
		}
		$projection = ec_get_link_page_public_projection( $this->link_page_id );
		$this->assertSame( 'Page bio.', $projection['bio'] );
		$this->assertSame( 'Page bio.', $projection['seo']['description'] );
		$this->assertSame( 'Artist excerpt.', $projection['seo']['schema'][0]['description'] );
	}

	public function test_missing_snapshot_fails_closed_without_writing(): void {
		$this->seedArtistIdentity();
		$this->deleteArtistSnapshot();

		$result = ec_get_link_page_public_projection( $this->link_page_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_profile_link_page_snapshot_invalid', $result->get_error_code() );
		switch_to_blog( ec_get_link_page_storage_blog_id() );
		try {
			$this->assertFalse( metadata_exists( 'post', $this->link_page_id, \ExtraChillArtistPlatform\Artist_Link_Pages::SNAPSHOT_META_KEY ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_unpublished_artist_is_unavailable_from_snapshot(): void {
		switch_to_blog( $this->artist_blog_id() );
		wp_update_post(
			array(
				'ID'          => $this->profile_id,
				'post_status' => 'draft',
			)
		);
		restore_current_blog();
		$this->storeArtistSnapshot();
		$result = ec_get_link_page_public_projection( $this->link_page_id );
		$this->assertSame( 'link_page_public_owner_unavailable', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}
}
