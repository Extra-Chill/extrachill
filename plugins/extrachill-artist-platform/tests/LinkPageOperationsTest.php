<?php

use PHPUnit\Framework\TestCase;

final class LinkPageOperationsTest extends TestCase {
	protected function setUp(): void {
		$this->resetRegistry( ec_link_page_owner_compatibility_registry(), 'providers' );
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
		ec_register_link_page_operation_provider( 'artist-platform', 'ec_artist_link_page_operation_provider' );
		$GLOBALS['_wp_switched_stack'] = array();
		$GLOBALS['switched']           = false;
		$GLOBALS['ec_test']            = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				4 => array( 'terms' => array(), 'term_meta' => array(), 'posts' => array(), 'post_meta' => array() ),
				7 => array( 'terms' => array(), 'term_meta' => array(), 'posts' => array(), 'post_meta' => array() ),
			),
		);
		extrachill_register_artist_profile_cpt();
		extrachill_register_artist_link_page_cpt();
		$this->addPost( 4, 20, 'artist_profile', 'test-owner' );
		$this->addPost( 4, 40, 'artist_link_page', 'test-page' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_extrch_link_page_id']             = 40;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id']    = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ]       = 'post:4:artist_profile:20';
	}

	protected function tearDown(): void {
		$this->resetRegistry( ec_link_page_owner_compatibility_registry(), 'providers' );
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
	}

	private function resetRegistry( $registry, $property_name ): void {
		$reflection = new ReflectionObject( $registry );
		$property   = $reflection->getProperty( $property_name );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );
	}

	private function addPost( $blog_id, $post_id, $post_type, $slug ): void {
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'][ $post_id ] = (object) array(
			'ID'          => $post_id,
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => ucwords( str_replace( '-', ' ', $slug ) ),
			'post_name'   => $slug,
		);
	}

	private function authorizeOwner(): void {
		$GLOBALS['ec_test']['current_user_id']    = 7;
		$GLOBALS['ec_test']['managed_artists'][7] = array( 20 );
	}

	public function test_current_owner_can_read_by_id_and_reference_deterministically(): void {
		$this->authorizeOwner();

		$by_id        = ec_read_link_page( 40 );
		$by_reference = ec_read_link_page( 'post:4:artist_profile:20' );

		$this->assertSame( 20, $by_id['artist_id'] );
		$this->assertSame( 40, $by_id['link_page_id'] );
		$this->assertSame( $by_id, $by_reference );
		$this->assertSame( 40, ec_resolve_link_page_operation_target( 40 )['link_page_id'] );
		$this->assertSame( 40, ec_resolve_link_page_operation_target( 'post:4:artist_profile:20' )['link_page_id'] );
	}

	public function test_current_wrappers_preserve_read_and_save_payloads(): void {
		$this->authorizeOwner();
		$expected = ec_get_link_page_data( 20, 40 );

		$this->assertSame(
			$expected,
			extrachill_artist_platform_ability_get_link_page_data( array( 'artist_id' => 20, 'link_page_id' => 40 ) )
		);

		$result = extrachill_artist_platform_ability_save_link_page_links(
			array(
				'artist_id' => 20,
				'links'     => array(),
			)
		);

		$this->assertSame( 20, $result['artist_id'] );
		$this->assertSame( 40, $result['link_page_id'] );
		$this->assertSame( array(), $result['links'] );
		$this->assertSame( $result, ec_get_link_page_data( 20, 40 ) );
	}

	public function test_unauthenticated_and_unrelated_callers_fail_at_operation_boundary(): void {
		$this->assertSame( 'link_page_operation_forbidden', ec_read_link_page( 40 )->get_error_code() );
		$this->assertSame( 'link_page_operation_forbidden', ec_save_link_page( 40, array( 'bio' => 'Nope' ) )->get_error_code() );

		$GLOBALS['ec_test']['current_user_id'] = 8;
		$this->assertSame( 'link_page_operation_forbidden', ec_read_link_page( 40 )->get_error_code() );
		$this->assertEmpty( get_post_meta( 40, '_link_page_bio_text', true ) );
	}

	public function test_unknown_operation_is_rejected_by_owner_authorization(): void {
		$this->authorizeOwner();

		$result = ec_prepare_link_page_operation( 40, 'delete' );

		$this->assertSame( 'link_page_operation_forbidden', $result->get_error_code() );
	}

	/**
	 * @dataProvider invalidTargetProvider
	 */
	public function test_malformed_missing_divergent_duplicate_and_unavailable_targets_fail_closed( $setup, $target, $error_code ): void {
		$setup( $this );
		$result = ec_read_link_page( $target );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $error_code, $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
	}

	public function invalidTargetProvider(): array {
		return array(
			'empty target' => array( static function () {}, array(), 'invalid_link_page_operation_target' ),
			'extra target field' => array(
				static function () {},
				array( 'link_page_id' => 40, 'owner_reference' => 'post:4:artist_profile:20', 'artist_id' => 20 ),
				'invalid_link_page_operation_target',
			),
			'malformed' => array( static function () {}, 'post/4/type/20', 'invalid_link_page_owner_reference' ),
			'missing page' => array( static function () {}, 999, 'invalid_link_page' ),
			'unavailable owner' => array( static function () {}, 'post:99:type:20', 'invalid_link_page_owner_blog' ),
			'divergent pair' => array(
				static function ( $test ) {
					$test->addPost( 4, 21, 'artist_profile', 'other-owner' );
					$test->addPost( 4, 41, 'artist_link_page', 'other-page' );
					$GLOBALS['ec_test']['blogs'][4]['post_meta'][41]['_associated_artist_profile_id'] = 21;
					$GLOBALS['ec_test']['blogs'][4]['post_meta'][41][ EC_LINK_PAGE_OWNER_META_KEY ]    = 'post:4:artist_profile:21';
				},
				array( 'link_page_id' => 40, 'owner_reference' => 'post:4:artist_profile:21' ),
				'link_page_operation_target_divergence',
			),
			'duplicate references' => array(
				static function () {
					$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = array(
						'post:4:artist_profile:20',
						'post:4:artist_profile:20',
					);
				},
				40,
				'duplicate_link_page_owner_references',
			),
			'duplicate pages' => array(
				static function ( $test ) {
					$test->addPost( 4, 41, 'artist_link_page', 'duplicate-page' );
					$GLOBALS['ec_test']['blogs'][4]['post_meta'][41]['_associated_artist_profile_id'] = 20;
					$GLOBALS['ec_test']['blogs'][4]['post_meta'][41][ EC_LINK_PAGE_OWNER_META_KEY ]    = 'post:4:artist_profile:20';
				},
				'post:4:artist_profile:20',
				'duplicate_link_pages_for_owner',
			),
		);
	}

	public function test_missing_provider_and_provider_exceptions_fail_closed(): void {
		$this->authorizeOwner();
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		$this->assertSame( 'link_page_operation_provider_missing', ec_read_link_page( 40 )->get_error_code() );

		ec_register_link_page_operation_provider(
			'throwing',
			static function () {
				throw new RuntimeException( 'failed' );
			}
		);
		$this->assertSame( 'link_page_operation_provider_exception', ec_read_link_page( 40 )->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
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
					'read'      => static function () { return array(); },
					'save'      => static function () { return array(); },
				);
			}
		);

		$result = ec_read_link_page( 40 );

		$this->assertSame( 'link_page_operation_provider_exception', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
		$this->assertFalse( $GLOBALS['switched'] );
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
					'save'      => static function () { return array(); },
				);
			}
		);

		$result = ec_read_link_page( 40 );

		$this->assertSame( 'link_page_operation_provider_exception', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
		$this->assertFalse( $GLOBALS['switched'] );
	}

	public function test_owner_change_during_authorization_prevents_operation_execution(): void {
		$this->authorizeOwner();
		$this->addPost( 4, 21, 'artist_profile', 'other-owner' );
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		ec_register_link_page_operation_provider(
			'ownership-mutator',
			static function () {
				return array(
					'authorize' => static function () {
						$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:21';
						return true;
					},
					'read'      => static function () {
						$GLOBALS['ec_test']['operation_executed'] = true;
						return array();
					},
					'save'      => static function () { return array(); },
				);
			}
		);

		$result = ec_read_link_page( 40 );

		$this->assertSame( 'link_page_owner_divergence', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'operation_executed', $GLOBALS['ec_test'] );
	}

	public function test_cross_blog_owner_normalization_and_provider_matching_restore_context_exactly(): void {
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		$GLOBALS['ec_test']['blogs'][7]['terms'][30] = (object) array( 'term_id' => 30, 'taxonomy' => 'place', 'slug' => 'room' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'term:7:place:30';
		unset( $GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] );
		ec_register_link_page_operation_provider(
			'cross-blog',
			static function ( $resolved ) {
				if ( 'term:7:place:30' !== $resolved['owner_reference'] ) {
					return null;
				}
				switch_to_blog( 7 );
				return array(
					'authorize' => '__return_true',
					'read'      => static function ( $target ) { return $target; },
					'save'      => static function ( $target ) { return $target; },
				);
			}
		);

		switch_to_blog( 7 );
		switch_to_blog( 4 );
		$result = ec_read_link_page( array( 'link_page_id' => 40, 'owner_reference' => 'term:7:place:30' ) );

		$this->assertSame( 'term:7:place:30', $result['owner_reference'] );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array( 4, 7 ), $GLOBALS['ec_test']['blog_stack'] );
		$this->assertSame( array( 4, 7 ), $GLOBALS['_wp_switched_stack'] );
		$this->assertTrue( $GLOBALS['switched'] );
		restore_current_blog();
		restore_current_blog();
	}

	public function test_operation_registry_is_append_only_and_provider_order_is_deterministic(): void {
		$this->resetRegistry( ec_link_page_operation_provider_registry(), 'providers' );
		foreach ( array( array( 'z-provider', 20 ), array( 'b-provider', 5 ), array( 'a-provider', 5 ) ) as $provider ) {
			ec_register_link_page_operation_provider(
				$provider[0],
				static function () use ( $provider ) {
					$GLOBALS['ec_test']['operation_provider_order'][] = $provider[0];
					return null;
				},
				$provider[1]
			);
		}

		$result = ec_read_link_page( 40 );

		$this->assertSame( 'link_page_operation_provider_missing', $result->get_error_code() );
		$this->assertSame( array( 'a-provider', 'b-provider', 'z-provider' ), $GLOBALS['ec_test']['operation_provider_order'] );
		$this->assertFalse( method_exists( ec_link_page_operation_provider_registry(), 'reset' ) );
		$this->assertFalse( method_exists( ec_link_page_operation_provider_registry(), 'unregister' ) );
	}

	public function test_generic_operation_source_contains_no_domain_policy(): void {
		$source = strtolower( file_get_contents( dirname( __DIR__ ) . '/inc/link-pages/operations.php' ) );

		foreach ( array( 'artist', 'venue', 'booking', 'events', '_associated_artist_profile_id' ) as $term ) {
			$this->assertStringNotContainsString( $term, $source );
		}
	}
}
