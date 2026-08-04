<?php

use PHPUnit\Framework\TestCase;

final class LinkPageOwnerReferenceTest extends TestCase {
	protected function setUp(): void {
		$this->resetProviders();
		$GLOBALS['_wp_switched_stack'] = array();
		$GLOBALS['switched']           = false;
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				4 => array( 'terms' => array(), 'term_meta' => array(), 'posts' => array(), 'post_meta' => array() ),
				7 => array( 'terms' => array(), 'term_meta' => array(), 'posts' => array(), 'post_meta' => array() ),
			),
		);
		extrachill_register_artist_profile_cpt();
		extrachill_register_artist_link_page_cpt();
		$this->addPost( 4, 20, 'artist_profile', 'test-artist' );
		$this->addTerm( 7, 30, 'place' );
	}

	protected function tearDown(): void {
		$this->resetProviders();
	}

	private function resetProviders(): void {
		$registry   = ec_link_page_owner_compatibility_registry();
		$reflection = new ReflectionObject( $registry );
		$providers  = $reflection->getProperty( 'providers' );
		$providers->setAccessible( true );
		$providers->setValue( $registry, array() );
		ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
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

	private function addTerm( $blog_id, $term_id, $taxonomy ): void {
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['terms'][ $term_id ] = (object) array(
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
			'slug'     => 'object-' . $term_id,
		);
	}

	private function postOwner( $object_id = 20 ): array {
		return array(
			'kind'      => 'post',
			'blog_id'   => 4,
			'subtype'   => 'artist_profile',
			'object_id' => $object_id,
		);
	}

	public function test_post_and_term_references_parse_format_and_normalize_round_trip(): void {
		$post_reference = ec_format_link_page_owner_reference( $this->postOwner() );
		$term_reference = ec_format_link_page_owner_reference(
			array( 'kind' => 'term', 'blog_id' => 7, 'subtype' => 'place', 'object_id' => 30 )
		);

		$this->assertSame( 'post:4:artist_profile:20', $post_reference );
		$this->assertSame( $post_reference, ec_normalize_link_page_owner_reference( $post_reference ) );
		$this->assertSame( $this->postOwner() + array( 'reference' => $post_reference ), ec_parse_link_page_owner_reference( $post_reference ) );
		$this->assertSame( 'term:7:place:30', $term_reference );
		$this->assertSame( $term_reference, ec_normalize_link_page_owner_reference( $term_reference ) );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
	}

	/**
	 * @dataProvider invalidReferenceProvider
	 */
	public function test_malformed_and_invalid_owner_references_fail( $reference, $error_code ): void {
		$result = ec_normalize_link_page_owner_reference( $reference );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $error_code, $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function invalidReferenceProvider(): array {
		return array(
			'malformed'        => array( 'post/4/artist_profile/20', 'invalid_link_page_owner_reference' ),
			'invalid kind'     => array( 'user:4:subscriber:20', 'invalid_link_page_owner_reference' ),
			'invalid blog'     => array( 'post:99:artist_profile:20', 'invalid_link_page_owner_blog' ),
			'invalid subtype'  => array( 'post:4:event:20', 'invalid_link_page_owner_object' ),
			'invalid taxonomy' => array( 'term:7:venue:30', 'invalid_link_page_owner_object' ),
			'invalid object'   => array( 'post:4:artist_profile:999', 'invalid_link_page_owner_object' ),
			'zero object'      => array( 'term:7:place:0', 'invalid_link_page_owner_reference' ),
		);
	}

	public function test_legacy_artist_fallback_does_not_write_during_read(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'test-artist' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;

		$owner = ec_get_link_page_owner( 40 );

		$this->assertSame( 'post:4:artist_profile:20', $owner['reference'] );
		$this->assertArrayNotHasKey( EC_LINK_PAGE_OWNER_META_KEY, $GLOBALS['ec_test']['blogs'][4]['post_meta'][40] );
		$this->assertArrayNotHasKey( 'post_meta_update_calls', $GLOBALS['ec_test'] );
	}

	public function test_artist_creation_dual_writes_without_changing_id_or_slug(): void {
		$link_page_id = ec_create_link_page( 20 );

		$this->assertSame( 21, $link_page_id );
		$this->assertSame( 'test-artist', get_post_field( 'post_name', $link_page_id ) );
		$this->assertSame( 20, (int) get_post_meta( $link_page_id, '_associated_artist_profile_id', true ) );
		$this->assertSame( 21, (int) get_post_meta( 20, '_extrch_link_page_id', true ) );
		$this->assertSame( 'post:4:artist_profile:20', get_post_meta( $link_page_id, EC_LINK_PAGE_OWNER_META_KEY, true ) );
	}

	public function test_owner_conflict_and_duplicate_references_fail_deterministically(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'first' );
		$this->addPost( 4, 41, 'artist_link_page', 'second' );
		$this->addPost( 4, 42, 'artist_link_page', 'third' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:20';

		$conflict = ec_assign_link_page_owner( 41, $this->postOwner() );
		$this->assertSame( 'link_page_owner_conflict', $conflict->get_error_code() );

		$GLOBALS['ec_test']['blogs'][4]['post_meta'][41][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:20';
		$duplicate_pages = ec_get_link_page_id_for_owner( $this->postOwner() );
		$this->assertSame( 'duplicate_link_pages_for_owner', $duplicate_pages->get_error_code() );

		$GLOBALS['ec_test']['blogs'][4]['post_meta'][42][ EC_LINK_PAGE_OWNER_META_KEY ] = array(
			'post:4:artist_profile:20',
			'post:4:artist_profile:20',
		);
		$duplicate_rows = ec_get_link_page_owner( 42 );
		$this->assertSame( 'duplicate_link_page_owner_references', $duplicate_rows->get_error_code() );
	}

	public function test_three_canonical_pages_fail_even_when_two_are_allowed(): void {
		foreach ( array( 40, 41, 42 ) as $link_page_id ) {
			$this->addPost( 4, $link_page_id, 'artist_link_page', 'page-' . $link_page_id );
			$GLOBALS['ec_test']['blogs'][4]['post_meta'][ $link_page_id ][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:20';
		}

		$result = ec_get_link_page_id_for_owner( $this->postOwner(), array( 40, 41 ) );

		$this->assertSame( 'duplicate_link_pages_for_owner', $result->get_error_code() );
	}

	public function test_canonical_and_separate_legacy_candidates_conflict(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'canonical' );
		$this->addPost( 4, 41, 'artist_link_page', 'legacy' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:20';
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][41]['_associated_artist_profile_id'] = 20;

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 'duplicate_link_pages_for_owner', $result->get_error_code() );
	}

	public function test_same_page_canonical_and_legacy_divergence_fails_for_both_owners(): void {
		$this->addPost( 4, 21, 'artist_profile', 'other-artist' );
		$this->addPost( 4, 40, 'artist_link_page', 'divergent' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:21';

		$legacy_owner    = ec_get_link_page_id_for_owner( $this->postOwner( 20 ) );
		$canonical_owner = ec_get_link_page_id_for_owner( $this->postOwner( 21 ) );

		$this->assertSame( 'link_page_owner_divergence', $legacy_owner->get_error_code() );
		$this->assertSame( 'link_page_owner_divergence', $canonical_owner->get_error_code() );
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

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 'link_page_owner_provider_reentrancy', $result->get_error_code() );
	}

	public function test_later_provider_cannot_suppress_earlier_error(): void {
		ec_register_link_page_owner_compatibility_provider(
			'error-provider',
			static function () {
				return new WP_Error( 'provider_blocked', 'Provider blocked.' );
			},
			5
		);
		ec_register_link_page_owner_compatibility_provider(
			'later-provider',
			static function () {
				$GLOBALS['ec_test']['later_provider_called'] = true;
				return array();
			},
			20
		);

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 'provider_blocked', $result->get_error_code() );
		$this->assertTrue( $GLOBALS['ec_test']['later_provider_called'] );
	}

	public function test_provider_cannot_mutate_private_registry_storage(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'legacy' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;
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

		$first  = ec_get_link_page_id_for_owner( $this->postOwner() );
		$second = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 40, $first );
		$this->assertSame( 40, $second );
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
		$this->addPost( 4, 40, 'artist_link_page', 'first' );
		$this->addPost( 4, 41, 'artist_link_page', 'second' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][41]['_associated_artist_profile_id'] = 20;
		ec_register_link_page_owner_compatibility_provider( 'empty-provider', static function () { return array(); }, 20 );

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 'duplicate_link_pages_for_owner', $result->get_error_code() );
	}

	public function test_provider_cannot_claim_page_canonically_owned_by_another_reference(): void {
		$this->addPost( 4, 21, 'artist_profile', 'other-artist' );
		$this->addPost( 4, 40, 'artist_link_page', 'other-owner' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:21';
		ec_register_link_page_owner_compatibility_provider(
			'wrong-owner-provider',
			static function ( $operation, $context ) {
				return 'owner_pages' === $operation
					? array( array( 'link_page_id' => 40, 'owner_reference' => $context['owner_reference'] ) )
					: array();
			}
		);

		$result = ec_get_link_page_id_for_owner( $this->postOwner( 20 ) );

		$this->assertSame( 'link_page_owner_divergence', $result->get_error_code() );
	}

	public function test_owner_pages_claim_must_agree_with_all_page_owner_claims(): void {
		$this->addPost( 4, 21, 'artist_profile', 'legacy-owner' );
		$this->addPost( 4, 40, 'artist_link_page', 'uncanonicalized' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 21;
		ec_register_link_page_owner_compatibility_provider(
			'one-way-owner',
			static function ( $operation, $context ) {
				return 'owner_pages' === $operation
					? array( array( 'link_page_id' => 40, 'owner_reference' => $context['owner_reference'] ) )
					: array();
			}
		);

		$result = ec_get_link_page_id_for_owner( $this->postOwner( 20 ) );

		$this->assertSame( 'link_page_owner_divergence', $result->get_error_code() );
	}

	public function test_provider_switch_result_is_validated_after_storage_context_restoration(): void {
		$this->addPost( 7, 50, 'artist_link_page', 'cross-site' );
		ec_register_link_page_owner_compatibility_provider(
			'context-switcher',
			static function ( $operation, $context ) {
				if ( 'owner_pages' !== $operation ) {
					return array();
				}
				switch_to_blog( 7 );
				return array( array( 'link_page_id' => 50, 'owner_reference' => $context['owner_reference'] ) );
			},
			5
		);

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 'invalid_link_page_owner_candidate', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
		$this->assertFalse( $GLOBALS['switched'] );
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

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 'link_page_owner_provider_exception', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}

	public function test_provider_restores_nested_entry_context_exactly(): void {
		ec_register_link_page_owner_compatibility_provider(
			'nested-switcher',
			static function () {
				switch_to_blog( 4 );
				return array();
			},
			5
		);
		switch_to_blog( 7 );

		$result = ec_collect_link_page_owner_compatibility_claims(
			'owner_pages',
			array( 'owner_reference' => 'post:4:artist_profile:20' )
		);

		$this->assertSame( array(), $result );
		$this->assertSame( 7, get_current_blog_id() );
		$this->assertSame( array( 4 ), $GLOBALS['ec_test']['blog_stack'] );
		$this->assertSame( array( 4 ), $GLOBALS['_wp_switched_stack'] );
		$this->assertTrue( $GLOBALS['switched'] );
		restore_current_blog();
	}

	public function test_distinct_page_owner_claims_fail_closed(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'multiple-owners' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;
		ec_register_link_page_owner_compatibility_provider(
			'term-provider',
			static function ( $operation ) {
				return 'page_owner' === $operation
					? array( array( 'link_page_id' => 40, 'owner_reference' => 'term:7:place:30' ) )
					: array();
			}
		);

		$result = ec_get_link_page_owner( 40 );

		$this->assertSame( 'multiple_link_page_owner_claims', $result->get_error_code() );
	}

	public function test_duplicate_and_wrong_owner_claims_fail_closed(): void {
		$this->addPost( 4, 21, 'artist_profile', 'other-artist' );
		$this->addPost( 4, 40, 'artist_link_page', 'claimed' );
		$duplicate_claim = static function ( $operation, $context ) {
			return 'owner_pages' === $operation
				? array( array( 'link_page_id' => 40, 'owner_reference' => $context['owner_reference'] ) )
				: array();
		};
		ec_register_link_page_owner_compatibility_provider( 'duplicate-one', $duplicate_claim );
		ec_register_link_page_owner_compatibility_provider( 'duplicate-two', $duplicate_claim );

		$duplicate = ec_get_link_page_id_for_owner( $this->postOwner( 20 ) );

		$this->assertSame( 'duplicate_link_page_owner_claim', $duplicate->get_error_code() );

		$this->resetProviders();
		ec_register_link_page_owner_compatibility_provider(
			'wrong-reference',
			static function ( $operation ) {
				return 'owner_pages' === $operation
					? array( array( 'link_page_id' => 40, 'owner_reference' => 'post:4:artist_profile:21' ) )
					: array();
			}
		);

		$wrong_owner = ec_get_link_page_id_for_owner( $this->postOwner( 20 ) );

		$this->assertSame( 'link_page_owner_claim_mismatch', $wrong_owner->get_error_code() );
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
					? array( array( 'link_page_id' => $candidate_id, 'owner_reference' => $context['owner_reference'] ) )
					: array();
			}
		);

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 'invalid_link_page_owner_candidate', $result->get_error_code() );
	}

	public function test_malformed_provider_registration_and_duplicate_name_fail(): void {
		$invalid_name = ec_register_link_page_owner_compatibility_provider( 'Bad Name', '__return_true' );
		$invalid_callback = ec_register_link_page_owner_compatibility_provider( 'bad-callback', 'missing_callback' );
		$invalid_priority = ec_register_link_page_owner_compatibility_provider( 'bad-priority', '__return_true', '10' );
		$duplicate = ec_register_link_page_owner_compatibility_provider( 'artist-platform', '__return_true' );

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

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( $error_code, $result->get_error_code() );
	}

	public function malformedProviderResultProvider(): array {
		return array(
			'invalid result' => array( static function () { return 'invalid'; }, 'invalid_link_page_owner_provider_result' ),
			'exception'      => array( static function () { throw new RuntimeException( 'failed' ); }, 'link_page_owner_provider_exception' ),
			'malformed claim' => array( static function () { return array( array( 'link_page_id' => 40 ) ); }, 'invalid_link_page_owner_claim' ),
		);
	}

	public function test_provider_order_is_deterministic_without_affecting_result(): void {
		foreach ( array( array( 'z-provider', 20 ), array( 'b-provider', 5 ), array( 'a-provider', 5 ) ) as $provider ) {
			ec_register_link_page_owner_compatibility_provider(
				$provider[0],
				static function () use ( $provider ) {
					$GLOBALS['ec_test']['provider_order'][] = $provider[0];
					return array();
				},
				$provider[1]
			);
		}

		$result = ec_get_link_page_id_for_owner( $this->postOwner() );

		$this->assertSame( 0, $result );
		$this->assertSame( array( 'a-provider', 'b-provider', 'z-provider' ), $GLOBALS['ec_test']['provider_order'] );
	}

	public function invalidCompatibilityCandidateProvider(): array {
		return array(
			'zero'          => array( 0 ),
			'malformed'     => array( '40' ),
			'missing'       => array( 999 ),
			'unrelated post' => array( 20 ),
			'deleted'       => array(
				40,
				static function ( $test ) {
					$test->addPost( 4, 40, 'artist_link_page', 'deleted' );
					unset( $GLOBALS['ec_test']['blogs'][4]['posts'][40] );
				},
			),
			'cross context' => array(
				50,
				static function ( $test ) {
					$test->addPost( 7, 50, 'artist_link_page', 'other-site' );
				},
			),
		);
	}

	public function test_malformed_stored_reference_does_not_fall_back_to_legacy_owner(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'test-artist' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ]    = 'broken';

		$owner = ec_get_link_page_owner( 40 );

		$this->assertSame( 'invalid_link_page_owner_reference', $owner->get_error_code() );
	}

	public function test_conflict_created_during_assignment_is_compensated(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'first' );
		$this->addPost( 4, 41, 'artist_link_page', 'second' );
		$GLOBALS['ec_test']['after_post_meta_add'] = static function () {
			$GLOBALS['ec_test']['blogs'][4]['post_meta'][41][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:20';
		};

		$result = ec_assign_link_page_owner( 40, $this->postOwner() );

		$this->assertSame( 'duplicate_link_pages_for_owner', $result->get_error_code() );
		$this->assertArrayNotHasKey( EC_LINK_PAGE_OWNER_META_KEY, $GLOBALS['ec_test']['blogs'][4]['post_meta'][40] );
	}

	public function test_partial_duplicate_rows_are_compensated_before_uniqueness_check(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'first' );
		$GLOBALS['ec_test']['after_post_meta_add'] = static function () {
			$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = array( 'post:4:artist_profile:20', 'post:4:artist_profile:20' );
		};

		$result = ec_assign_link_page_owner( 40, $this->postOwner() );

		$this->assertSame( 'link_page_owner_assignment_failed', $result->get_error_code() );
		$this->assertSame( array( 'post:4:artist_profile:20' ), $GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] );
	}

	public function test_different_owner_inserted_immediately_before_assignment_is_not_overwritten(): void {
		$this->addPost( 4, 21, 'artist_profile', 'other-artist' );
		$this->addPost( 4, 40, 'artist_link_page', 'first' );
		$GLOBALS['ec_test']['before_post_meta_add'] = static function () {
			$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:21';
		};

		$result = ec_assign_link_page_owner( 40, $this->postOwner( 20 ) );

		$this->assertSame( 'link_page_owner_conflict', $result->get_error_code() );
		$this->assertSame( 'post:4:artist_profile:21', get_post_meta( 40, EC_LINK_PAGE_OWNER_META_KEY, true ) );
	}

	public function test_backfill_halts_after_failed_partial_assignment_compensation(): void {
		$this->addPost( 4, 21, 'artist_profile', 'second-artist' );
		$this->addPost( 4, 40, 'artist_link_page', 'first' );
		$this->addPost( 4, 41, 'artist_link_page', 'second' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][41]['_associated_artist_profile_id'] = 21;
		$GLOBALS['ec_test']['after_post_meta_add'] = static function () {
			$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = array( 'post:4:artist_profile:20', 'post:4:artist_profile:20' );
		};
		$GLOBALS['ec_test']['fail_metadata_delete_by_mid'] = true;

		$result = ec_backfill_link_page_owner_references( 2, 0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( array( 40 => 'link_page_owner_compensation_failed' ), $result['errors'] );
		$this->assertSame( 0, $result['next_offset'] );
		$this->assertArrayNotHasKey( EC_LINK_PAGE_OWNER_META_KEY, $GLOBALS['ec_test']['blogs'][4]['post_meta'][41] );
	}

	public function test_forced_replacement_rejects_mismatched_canonical_and_legacy_owners(): void {
		$this->addPost( 4, 21, 'artist_profile', 'other-artist' );
		$this->addPost( 4, 30, 'artist_link_page', 'test-artist' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_extrch_link_page_id'] = 30;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:21';

		$result = ec_create_link_page( 20, true );

		$this->assertSame( 'link_page_previous_owner_conflict', $result->get_error_code() );
		$this->assertSame( 30, (int) get_post_meta( 20, '_extrch_link_page_id', true ) );
		$this->assertSame( 20, (int) get_post_meta( 30, '_associated_artist_profile_id', true ) );
		$this->assertSame( 'post:4:artist_profile:21', get_post_meta( 30, EC_LINK_PAGE_OWNER_META_KEY, true ) );
		$this->assertSame( array( 20, 21, 30 ), array_keys( $GLOBALS['ec_test']['blogs'][4]['posts'] ) );
		$this->assertArrayNotHasKey( 'deleted_posts', $GLOBALS['ec_test'] );
	}

	public function test_failed_previous_canonical_owner_restoration_requires_manual_reconciliation(): void {
		$this->addPost( 4, 30, 'artist_link_page', 'test-artist' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_extrch_link_page_id'] = 30;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:20';
		$GLOBALS['ec_test']['fail_post_meta_delete_keys']['_associated_artist_profile_id'] = 1;
		$GLOBALS['ec_test']['after_post_meta_update'] = static function () {
			$GLOBALS['ec_test']['fail_post_meta_update_keys'][ EC_LINK_PAGE_OWNER_META_KEY ] = 1;
		};

		$result = ec_create_link_page( 20, true );

		$this->assertSame( 'link_page_association_compensation_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( 30, (int) get_post_meta( 20, '_extrch_link_page_id', true ) );
		$this->assertSame( 20, (int) get_post_meta( 30, '_associated_artist_profile_id', true ) );
		$this->assertEmpty( get_post_meta( 30, EC_LINK_PAGE_OWNER_META_KEY, true ) );
		$this->assertSame( array( 20, 30 ), array_keys( $GLOBALS['ec_test']['blogs'][4]['posts'] ) );
	}

	public function test_cross_blog_post_and_term_validation_always_restores_context(): void {
		$this->addPost( 7, 50, 'event', 'event-owner' );

		$this->assertSame( 'post:7:event:50', ec_normalize_link_page_owner_reference( 'post:7:event:50' ) );
		$this->assertSame( 'term:7:place:30', ec_normalize_link_page_owner_reference( 'term:7:place:30' ) );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
	}

	public function test_creation_rolls_back_when_owner_assignment_fails(): void {
		$GLOBALS['ec_test']['fail_post_meta_add_keys'][ EC_LINK_PAGE_OWNER_META_KEY ] = 1;

		$result = ec_create_link_page( 20 );

		$this->assertSame( 'link_page_owner_assignment_failed', $result->get_error_code() );
		$this->assertSame( array( 20 ), array_keys( $GLOBALS['ec_test']['blogs'][4]['posts'] ) );
		$this->assertEmpty( get_post_meta( 20, '_extrch_link_page_id', true ) );
	}

	public function test_backfill_is_bounded_and_idempotent(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'test-artist' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;

		$first  = ec_backfill_link_page_owner_references( 1, 0 );
		$second = ec_backfill_link_page_owner_references( 1, 0 );

		$this->assertSame( array( 'processed' => 1, 'updated' => 1, 'skipped' => 0, 'errors' => array(), 'next_offset' => 1 ), $first );
		$this->assertSame( array( 'processed' => 1, 'updated' => 0, 'skipped' => 1, 'errors' => array(), 'next_offset' => 1 ), $second );
	}

	public function test_backfill_halts_before_skipping_globally_conflicting_canonical_owner(): void {
		$this->addPost( 4, 40, 'artist_link_page', 'canonical' );
		$this->addPost( 4, 41, 'artist_link_page', 'legacy' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:20';
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][41]['_associated_artist_profile_id'] = 20;

		$result = ec_backfill_link_page_owner_references( 2, 0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( array( 40 => 'duplicate_link_pages_for_owner' ), $result['errors'] );
		$this->assertSame( 0, $result['next_offset'] );
	}

	public function test_backfill_halts_on_same_page_divergence_and_duplicate_canonical_rows(): void {
		$this->addPost( 4, 21, 'artist_profile', 'other-artist' );
		$this->addPost( 4, 40, 'artist_link_page', 'divergent' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:artist_profile:21';

		$divergent = ec_backfill_link_page_owner_references( 1, 0 );

		$this->assertSame( array( 40 => 'link_page_owner_divergence' ), $divergent['errors'] );
		$this->assertSame( 0, $divergent['next_offset'] );

		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = array(
			'post:4:artist_profile:20',
			'post:4:artist_profile:20',
		);
		$duplicate = ec_backfill_link_page_owner_references( 1, 0 );

		$this->assertSame( array( 40 => 'duplicate_link_page_owner_references' ), $duplicate['errors'] );
		$this->assertSame( 0, $duplicate['next_offset'] );
	}

	public function test_generic_owner_reference_helpers_have_no_domain_owner_knowledge(): void {
		$source = strtolower( file_get_contents( dirname( __DIR__ ) . '/inc/link-pages/owner-reference.php' ) );

		$this->assertStringNotContainsString( 'artist', $source );
		$this->assertStringNotContainsString( 'venue', $source );
	}
}
