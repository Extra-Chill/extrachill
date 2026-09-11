<?php

require_once __DIR__ . '/support/base-test-case.php';

final class LinkPageOperationsTest extends EC_Artist_Platform_TestCase {
	private $owner_id;
	private $profile_id;
	private $link_page_id;

	protected function setUp(): void {
		parent::setUp();

		$this->resetRegistry( ec_link_page_owner_compatibility_registry(), 'providers' );
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
		ec_register_link_page_operation_provider( 'artist-platform', 'ec_artist_link_page_operation_provider' );

		$this->owner_id   = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->profile_id = $this->create_artist_profile( 'Test Owner', array( 'post_name' => 'test-owner' ) );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->link_page_id = (int) self::factory()->post->create(
				array(
					'post_type'   => 'artist_link_page',
					'post_status' => 'publish',
					'post_name'   => 'test-page',
				)
			);
			update_post_meta( $this->link_page_id, '_associated_artist_profile_id', $this->profile_id );
			update_post_meta( $this->profile_id, '_extrch_link_page_id', $this->link_page_id );
			update_post_meta( $this->link_page_id, EC_LINK_PAGE_OWNER_META_KEY, 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->profile_id );
		} finally {
			restore_current_blog();
		}
	}

	protected function tearDown(): void {
		$this->resetRegistry( ec_link_page_owner_compatibility_registry(), 'providers' );
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

			$result = extrachill_artist_platform_ability_save_link_page_links(
				array(
					'artist_id' => $this->profile_id,
					'links'     => array(),
				)
			);

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

			$this->assertSame( 'link_page_owner_divergence', $result->get_error_code() );
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

	public function test_generic_operation_source_contains_no_domain_policy(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source-file read, not a remote URL.
		$source = strtolower( file_get_contents( dirname( __DIR__ ) . '/inc/link-pages/operations.php' ) );

		foreach ( array( 'artist', 'venue', 'booking', 'events', '_associated_artist_profile_id' ) as $term ) {
			$this->assertStringNotContainsString( $term, $source );
		}
	}
}
