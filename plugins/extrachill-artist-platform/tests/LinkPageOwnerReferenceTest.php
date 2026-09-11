<?php

require_once __DIR__ . '/support/base-test-case.php';

final class LinkPageOwnerReferenceTest extends EC_Artist_Platform_TestCase {
	private $profile_id;
	private $second_profile_id;
	private $place_term_id;

	protected function setUp(): void {
		parent::setUp();

		$this->resetProviders();

		$this->profile_id        = $this->create_artist_profile( 'Test Artist', array( 'post_name' => 'test-artist' ) );
		$this->second_profile_id = $this->create_artist_profile( 'Other Artist', array( 'post_name' => 'other-artist' ) );

		switch_to_blog( ec_get_blog_id( 'events' ) );
		try {
			if ( ! taxonomy_exists( 'place' ) ) {
				register_taxonomy( 'place', 'post', array( 'public' => true ) );
			}
			$created             = wp_insert_term( 'Object 30', 'place', array( 'slug' => 'object-30' ) );
			$this->place_term_id = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
		} finally {
			restore_current_blog();
		}
	}

	protected function tearDown(): void {
		$this->resetProviders();
		parent::tearDown();
	}

	private function resetProviders(): void {
		$registry   = ec_link_page_owner_compatibility_registry();
		$reflection = new ReflectionObject( $registry );
		$providers  = $reflection->getProperty( 'providers' );
		$providers->setAccessible( true );
		$providers->setValue( $registry, array() );
		ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
	}

	private function artistBlog(): int {
		return $this->artist_blog_id();
	}

	private function createLinkPage( string $slug ): int {
		switch_to_blog( $this->artistBlog() );
		try {
			return (int) self::factory()->post->create(
				array(
					'post_type'   => 'artist_link_page',
					'post_status' => 'publish',
					'post_name'   => $slug,
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	private function ownerReference( ?int $profile_id = null ): string {
		return 'post:' . $this->artistBlog() . ':artist_profile:' . ( $profile_id ?? $this->profile_id );
	}

	private function postOwner( ?int $object_id = null ): array {
		return array(
			'kind'      => 'post',
			'blog_id'   => $this->artistBlog(),
			'subtype'   => 'artist_profile',
			'object_id' => $object_id ?? $this->profile_id,
		);
	}

	public function test_post_and_term_references_parse_format_and_normalize_round_trip(): void {
		$post_reference = ec_format_link_page_owner_reference( $this->postOwner() );
		$term_reference = ec_format_link_page_owner_reference(
			array(
				'kind'      => 'term',
				'blog_id'   => ec_get_blog_id( 'events' ),
				'subtype'   => 'place',
				'object_id' => $this->place_term_id,
			)
		);

		$this->assertSame( $this->ownerReference(), $post_reference );
		$this->assertSame( $post_reference, ec_normalize_link_page_owner_reference( $post_reference ) );
		$this->assertSame( $this->postOwner() + array( 'reference' => $post_reference ), ec_parse_link_page_owner_reference( $post_reference ) );
		$this->assertSame( 'term:' . ec_get_blog_id( 'events' ) . ':place:' . $this->place_term_id, $term_reference );
		$this->assertSame( $term_reference, ec_normalize_link_page_owner_reference( $term_reference ) );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
	}

	/**
	 * @dataProvider invalidReferenceProvider
	 */
	public function test_malformed_and_invalid_owner_references_fail( $reference, $error_code ): void {
		$result = ec_normalize_link_page_owner_reference( $reference );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $error_code, $result->get_error_code() );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function invalidReferenceProvider(): array {
		return array(
			'malformed'        => array( 'post/4/artist_profile/20', 'invalid_link_page_owner_reference' ),
			'invalid kind'     => array( 'user:4:subscriber:20', 'invalid_link_page_owner_reference' ),
			'invalid blog'     => array( 'post:99:artist_profile:20', 'invalid_link_page_owner_blog' ),
			'invalid subtype'  => array( 'post:4:event:20', 'invalid_link_page_owner_object' ),
			'invalid taxonomy' => array( 'term:7:venue:30', 'invalid_link_page_owner_object' ),
			'invalid object'   => array( 'post:4:artist_profile:999999', 'invalid_link_page_owner_object' ),
			'zero object'      => array( 'term:7:place:0', 'invalid_link_page_owner_reference' ),
		);
	}

	public function test_legacy_artist_fallback_does_not_write_during_read(): void {
		$link_page_id = $this->createLinkPage( 'legacy-only' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $link_page_id, '_associated_artist_profile_id', $this->profile_id );
		restore_current_blog();

		switch_to_blog( $this->artistBlog() );
		try {
			$owner = ec_get_link_page_owner( $link_page_id );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $this->ownerReference(), $owner['reference'] );
		$this->assertSame( '', get_post_meta( $link_page_id, EC_LINK_PAGE_OWNER_META_KEY, true ) );
	}

	public function test_artist_creation_dual_writes_without_changing_id_or_slug(): void {
		// Owner assignment serializes through the canonical binding lock.
		$this->ec_require_mysql_advisory_locks();
		switch_to_blog( $this->artistBlog() );
		try {
			$link_page_id = (int) ec_create_link_page( $this->profile_id );
		} finally {
			restore_current_blog();
		}

		$this->assertGreaterThan( 0, $link_page_id );
		$this->assertNotInstanceOf( WP_Error::class, get_post( $link_page_id ) );
		$this->assertSame( $this->profile_id, (int) get_post_meta( $link_page_id, '_associated_artist_profile_id', true ) );
		$this->assertSame( $link_page_id, (int) get_post_meta( $this->profile_id, '_extrch_link_page_id', true ) );
		$this->assertSame( $this->ownerReference(), get_post_meta( $link_page_id, EC_LINK_PAGE_OWNER_META_KEY, true ) );
	}

	public function test_owner_conflict_and_duplicate_references_fail_deterministically(): void {
		// Owner assignment serializes through the canonical binding lock.
		$this->ec_require_mysql_advisory_locks();
		$first  = $this->createLinkPage( 'first' );
		$second = $this->createLinkPage( 'second' );
		$third  = $this->createLinkPage( 'third' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $first, EC_LINK_PAGE_OWNER_META_KEY, $this->ownerReference() );
		restore_current_blog();

		switch_to_blog( $this->artistBlog() );
		try {
			$conflict = ec_assign_link_page_owner( $second, $this->postOwner() );
			$this->assertSame( 'link_page_owner_conflict', $conflict->get_error_code() );

			update_post_meta( $second, EC_LINK_PAGE_OWNER_META_KEY, $this->ownerReference() );
			$duplicate_pages = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 'duplicate_link_pages_for_owner', $duplicate_pages->get_error_code() );

			update_post_meta( $third, EC_LINK_PAGE_OWNER_META_KEY, array( $this->ownerReference(), $this->ownerReference() ) );
			$duplicate_rows = ec_get_link_page_owner( $third );
			$this->assertSame( 'duplicate_link_page_owner_references', $duplicate_rows->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_three_canonical_pages_fail_even_when_two_are_allowed(): void {
		$ids = array( $this->createLinkPage( 'page-40' ), $this->createLinkPage( 'page-41' ), $this->createLinkPage( 'page-42' ) );
		switch_to_blog( $this->artistBlog() );
		foreach ( $ids as $id ) {
			update_post_meta( $id, EC_LINK_PAGE_OWNER_META_KEY, $this->ownerReference() );
		}
		restore_current_blog();

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner(), array( $ids[0], $ids[1] ) );
			$this->assertSame( 'duplicate_link_pages_for_owner', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_canonical_and_separate_legacy_candidates_conflict(): void {
		$canonical = $this->createLinkPage( 'canonical' );
		$legacy    = $this->createLinkPage( 'legacy' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $canonical, EC_LINK_PAGE_OWNER_META_KEY, $this->ownerReference() );
		update_post_meta( $legacy, '_associated_artist_profile_id', $this->profile_id );
		restore_current_blog();

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 'duplicate_link_pages_for_owner', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_same_page_canonical_and_legacy_divergence_fails_for_both_owners(): void {
		$divergent = $this->createLinkPage( 'divergent' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $divergent, '_associated_artist_profile_id', $this->profile_id );
		update_post_meta( $divergent, EC_LINK_PAGE_OWNER_META_KEY, $this->ownerReference( $this->second_profile_id ) );
		restore_current_blog();

		switch_to_blog( $this->artistBlog() );
		try {
			$legacy_owner    = ec_get_link_page_id_for_owner( $this->postOwner( $this->profile_id ) );
			$canonical_owner = ec_get_link_page_id_for_owner( $this->postOwner( $this->second_profile_id ) );

			$this->assertSame( 'link_page_owner_divergence', $legacy_owner->get_error_code() );
			$this->assertSame( 'link_page_owner_divergence', $canonical_owner->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_provider_same_subject_reentrancy_fails_closed(): void {
		ec_register_link_page_owner_compatibility_provider(
			'reentrant-provider',
			function ( $operation, $context ) {
				if ( 'owner_pages' !== $operation ) {
					return array();
				}
				return ec_collect_raw_link_page_owner_compatibility_claims( $operation, $context );
			},
			5
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 'link_page_owner_provider_reentrancy', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_later_provider_cannot_suppress_earlier_error(): void {
		ec_register_link_page_owner_compatibility_provider(
			'error-provider',
			static function () {
				return new WP_Error( 'provider_blocked', 'Provider blocked.' );
			},
			5
		);
		$GLOBALS['ec_test_later_provider_called'] = false;
		ec_register_link_page_owner_compatibility_provider(
			'later-provider',
			static function () {
				$GLOBALS['ec_test_later_provider_called'] = true;
				return array();
			},
			20
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 'provider_blocked', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertTrue( $GLOBALS['ec_test_later_provider_called'] );
	}

	public function test_provider_cannot_mutate_private_registry_storage(): void {
		$legacy = $this->createLinkPage( 'legacy' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $legacy, '_associated_artist_profile_id', $this->profile_id );
		restore_current_blog();

		ec_register_link_page_owner_compatibility_provider(
			'registry-tamper',
			static function () {
				$GLOBALS['ec_link_page_owner_compatibility_providers'] = array(
					'injected' => array( 'callback' => 'missing_callback' ),
				);
				return array();
			},
			5
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$first  = ec_get_link_page_id_for_owner( $this->postOwner() );
			$second = ec_get_link_page_id_for_owner( $this->postOwner() );

			$this->assertSame( $legacy, $first );
			$this->assertSame( $legacy, $second );
		} finally {
			restore_current_blog();
		}
		$this->assertCount( 2, ec_link_page_owner_compatibility_registry()->snapshot() );
	}

	public function test_registry_exposes_no_reset_or_unregister_api(): void {
		$registry = ec_link_page_owner_compatibility_registry();

		$this->assertFalse( method_exists( $registry, 'reset' ) );
		$this->assertFalse( method_exists( $registry, 'unregister' ) );
		$this->assertFalse( function_exists( 'ec_reset_link_page_owner_compatibility_providers' ) );
		$this->assertFalse( function_exists( 'ec_unregister_link_page_owner_compatibility_provider' ) );
	}

	public function test_later_provider_cannot_erase_earlier_candidates(): void {
		$first  = $this->createLinkPage( 'first' );
		$second = $this->createLinkPage( 'second' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $first, '_associated_artist_profile_id', $this->profile_id );
		update_post_meta( $second, '_associated_artist_profile_id', $this->profile_id );
		restore_current_blog();
		ec_register_link_page_owner_compatibility_provider(
			'empty-provider',
			static function () {
				return array();
			},
			20
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 'duplicate_link_pages_for_owner', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_provider_cannot_claim_page_canonically_owned_by_another_reference(): void {
		$claimed = $this->createLinkPage( 'other-owner' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $claimed, EC_LINK_PAGE_OWNER_META_KEY, $this->ownerReference( $this->second_profile_id ) );
		restore_current_blog();

		ec_register_link_page_owner_compatibility_provider(
			'wrong-owner-provider',
			function ( $operation, $context ) use ( $claimed ) {
				return 'owner_pages' === $operation
					? array(
						array(
							'link_page_id'    => $claimed,
							'owner_reference' => $context['owner_reference'],
						),
					)
					: array();
			}
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner( $this->profile_id ) );
			$this->assertSame( 'link_page_owner_divergence', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_owner_pages_claim_must_agree_with_all_page_owner_claims(): void {
		$uncanonicalized = $this->createLinkPage( 'uncanonicalized' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $uncanonicalized, '_associated_artist_profile_id', $this->second_profile_id );
		restore_current_blog();

		ec_register_link_page_owner_compatibility_provider(
			'one-way-owner',
			function ( $operation, $context ) use ( $uncanonicalized ) {
				return 'owner_pages' === $operation
					? array(
						array(
							'link_page_id'    => $uncanonicalized,
							'owner_reference' => $context['owner_reference'],
						),
					)
					: array();
			}
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner( $this->profile_id ) );
			$this->assertSame( 'link_page_owner_divergence', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_provider_switch_result_is_validated_after_storage_context_restoration(): void {
		ec_register_link_page_owner_compatibility_provider(
			'context-switcher',
			static function ( $operation, $context ) {
				if ( 'owner_pages' !== $operation ) {
					return array();
				}
				switch_to_blog( 1 );
				return array(
					array(
						'link_page_id'    => 99999999,
						'owner_reference' => $context['owner_reference'],
					),
				);
			},
			5
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 'invalid_link_page_owner_candidate', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
		$this->assertFalse( $GLOBALS['switched'] ?? false );
	}

	public function test_provider_switch_exception_restores_storage_context(): void {
		ec_register_link_page_owner_compatibility_provider(
			'throwing-switcher',
			static function () {
				switch_to_blog( 7 );
				throw new RuntimeException( 'failed' );
			},
			5
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 'link_page_owner_provider_exception', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
	}

	public function test_provider_restores_nested_entry_context_exactly(): void {
		ec_register_link_page_owner_compatibility_provider(
			'nested-switcher',
			function () {
				switch_to_blog( $this->artistBlog() );
				return array();
			},
			5
		);

		$stack_before = $GLOBALS['_wp_switched_stack'] ?? array();

		try {
			$result = ec_collect_link_page_owner_compatibility_claims(
				'owner_pages',
				array( 'owner_reference' => $this->ownerReference() )
			);

			$this->assertSame( array(), $result );
			$this->assertSame( $stack_before, $GLOBALS['_wp_switched_stack'] ?? array(), 'The provider must leave the caller blog stack exactly as it found it.' );
		} finally {
			while ( ! empty( $GLOBALS['_wp_switched_stack'] ?? array() ) ) {
				restore_current_blog();
			}
		}
	}

	public function test_distinct_page_owner_claims_fail_closed(): void {
		$multipage = $this->createLinkPage( 'multiple-owners' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $multipage, '_associated_artist_profile_id', $this->profile_id );
		restore_current_blog();

		ec_register_link_page_owner_compatibility_provider(
			'term-provider',
			function ( $operation ) {
				return 'page_owner' === $operation
					? array(
						array(
							'link_page_id'    => 0,
							'owner_reference' => 'term:' . ec_get_blog_id( 'events' ) . ':place:' . $this->place_term_id,
						),
					)
					: array();
			}
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_owner( $multipage );
			$this->assertContains(
				$result->get_error_code(),
				array( 'multiple_link_page_owner_claims', 'duplicate_link_page_owner_claim', 'invalid_link_page_owner_claim', 'invalid_link_page_owner_candidate', 'link_page_owner_claim_mismatch' )
			);
		} finally {
			restore_current_blog();
		}
	}

	public function test_duplicate_and_wrong_owner_claims_fail_closed(): void {
		$claimed = $this->createLinkPage( 'claimed' );

		$duplicate_claim = function ( $operation, $context ) use ( $claimed ) {
			return 'owner_pages' === $operation
				? array(
					array(
						'link_page_id'    => $claimed,
						'owner_reference' => $context['owner_reference'],
					),
				)
				: array();
		};
		ec_register_link_page_owner_compatibility_provider( 'duplicate-one', $duplicate_claim );
		ec_register_link_page_owner_compatibility_provider( 'duplicate-two', $duplicate_claim );

		switch_to_blog( $this->artistBlog() );
		try {
			$duplicate = ec_get_link_page_id_for_owner( $this->postOwner( $this->profile_id ) );
			$this->assertSame( 'duplicate_link_page_owner_claim', $duplicate->get_error_code() );
		} finally {
			restore_current_blog();
		}

		$this->resetProviders();
		$other_reference = $this->ownerReference( $this->second_profile_id );
		ec_register_link_page_owner_compatibility_provider(
			'wrong-reference',
			static function ( $operation ) use ( $claimed, $other_reference ) {
				return 'owner_pages' === $operation
					? array(
						array(
							'link_page_id'    => $claimed,
							'owner_reference' => $other_reference,
						),
					)
					: array();
			}
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$wrong_owner = ec_get_link_page_id_for_owner( $this->postOwner( $this->profile_id ) );
			$this->assertSame( 'link_page_owner_claim_mismatch', $wrong_owner->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * @dataProvider invalidCompatibilityCandidateProvider
	 */
	public function test_invalid_compatibility_candidate_ids_fail_closed( $candidate_id, $setup = null ): void {
		if ( $setup ) {
			$setup( $this );
		}
		ec_register_link_page_owner_compatibility_provider(
			'invalid-candidate-provider',
			static function ( $operation, $context ) use ( $candidate_id ) {
				return 'owner_pages' === $operation
					? array(
						array(
							'link_page_id'    => $candidate_id,
							'owner_reference' => $context['owner_reference'],
						),
					)
					: array();
			}
		);

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 'invalid_link_page_owner_candidate', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function invalidCompatibilityCandidateProvider(): array {
		return array(
			'zero'           => array( 0 ),
			'malformed'      => array( '40' ),
			'missing'        => array( 99999999 ),
			'unrelated post' => array( 'profile' ),
		);
	}

	public function test_malformed_provider_registration_and_duplicate_name_fail(): void {
		$invalid_name     = ec_register_link_page_owner_compatibility_provider( 'Bad Name', '__return_true' );
		$invalid_callback = ec_register_link_page_owner_compatibility_provider( 'bad-callback', 'missing_callback' );
		$invalid_priority = ec_register_link_page_owner_compatibility_provider( 'bad-priority', '__return_true', '10' );
		$duplicate        = ec_register_link_page_owner_compatibility_provider( 'artist-platform', '__return_true' );

		$this->assertSame( 'invalid_link_page_owner_provider', $invalid_name->get_error_code() );
		$this->assertSame( 'invalid_link_page_owner_provider', $invalid_callback->get_error_code() );
		$this->assertSame( 'invalid_link_page_owner_provider', $invalid_priority->get_error_code() );
		$this->assertSame( 'duplicate_link_page_owner_provider', $duplicate->get_error_code() );
	}

	/**
	 * @dataProvider malformedProviderResultProvider
	 */
	public function test_malformed_provider_results_and_claims_fail_closed( $provider, $error_code ): void {
		ec_register_link_page_owner_compatibility_provider( 'malformed-provider', $provider );

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( $error_code, $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function malformedProviderResultProvider(): array {
		return array(
			'invalid result'  => array(
				static function () {
						return 'invalid';
				},
				'invalid_link_page_owner_provider_result',
			),
			'exception'       => array(
				static function () {
						throw new RuntimeException( 'failed' );
				},
				'link_page_owner_provider_exception',
			),
			'malformed claim' => array(
				static function () {
						return array( array( 'link_page_id' => 40 ) );
				},
				'invalid_link_page_owner_claim',
			),
		);
	}

	public function test_provider_order_is_deterministic_without_affecting_result(): void {
		$GLOBALS['ec_test_provider_order'] = array();
		foreach ( array( array( 'z-provider', 20 ), array( 'b-provider', 5 ), array( 'a-provider', 5 ) ) as $provider ) {
			ec_register_link_page_owner_compatibility_provider(
				$provider[0],
				static function () use ( $provider ) {
					$GLOBALS['ec_test_provider_order'][] = $provider[0];
					return array();
				},
				$provider[1]
			);
		}

		switch_to_blog( $this->artistBlog() );
		try {
			$result = ec_get_link_page_id_for_owner( $this->postOwner() );
			$this->assertSame( 0, $result );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( array( 'a-provider', 'b-provider', 'z-provider' ), $GLOBALS['ec_test_provider_order'] );
	}

	public function test_malformed_stored_reference_does_not_fall_back_to_legacy_owner(): void {
		$page = $this->createLinkPage( 'test-artist' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $page, '_associated_artist_profile_id', $this->profile_id );
		update_post_meta( $page, EC_LINK_PAGE_OWNER_META_KEY, 'broken' );
		restore_current_blog();

		switch_to_blog( $this->artistBlog() );
		try {
			$owner = ec_get_link_page_owner( $page );
			$this->assertSame( 'invalid_link_page_owner_reference', $owner->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_backfill_is_bounded_and_idempotent(): void {
		// Backfill assignment serializes through the canonical binding lock.
		$this->ec_require_mysql_advisory_locks();
		$page = $this->createLinkPage( 'test-artist' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $page, '_associated_artist_profile_id', $this->profile_id );
		update_post_meta( $this->profile_id, '_extrch_link_page_id', $page );
		restore_current_blog();

		$first  = ec_backfill_link_page_owner_references( 100, 0 );
		$second = ec_backfill_link_page_owner_references( 100, 0 );

		$this->assertGreaterThanOrEqual( 1, $first['processed'], wp_json_encode( $first ) );
		$this->assertSame( array(), $first['errors'] );
		$this->assertSame( array(), $second['errors'] );
	}

	public function test_generic_owner_reference_helpers_have_no_domain_owner_knowledge(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source-file read, not a remote URL.
		$source = strtolower( file_get_contents( dirname( __DIR__ ) . '/inc/link-pages/owner-reference.php' ) );

		$this->assertStringNotContainsString( 'artist', $source );
		$this->assertStringNotContainsString( 'venue', $source );
	}

	public function test_fallback_cross_blog_mutation_uses_artist_site_guard_and_restores_context(): void {
		$page = $this->createLinkPage( 'test-artist' );
		switch_to_blog( $this->artistBlog() );
		update_post_meta( $page, '_associated_artist_profile_id', $this->profile_id );
		update_post_meta( $page, EC_LINK_PAGE_OWNER_META_KEY, $this->ownerReference() );
		update_post_meta( $this->profile_id, '_extrch_link_page_id', $page );
		restore_current_blog();

		$this->assertSame( 1, get_current_blog_id() );
		try {
			$result           = ec_artist_with_link_page_lock(
				$this->profile_id,
				function ( $link_page_id ) {
					return array(
						'link_page_id' => $link_page_id,
						'blog_id'      => get_current_blog_id(),
					);
				},
				true
			);
			$blog_after_lock  = get_current_blog_id();
			$stack_after_lock = $GLOBALS['_wp_switched_stack'] ?? array();
		} finally {
			while ( ! empty( $GLOBALS['_wp_switched_stack'] ?? array() ) ) {
				restore_current_blog();
			}
		}

		$this->assertSame( array(
			'link_page_id' => $page,
			'blog_id'      => $this->artistBlog(),
		), $result );
		$this->assertSame( 1, $blog_after_lock );
		$this->assertSame( array(), $stack_after_lock, 'The caller context must survive the cross-blog mutation.' );
		$this->assertArrayNotHasKey( 'ec_artist_link_page_local_lock', $GLOBALS );
	}
}
