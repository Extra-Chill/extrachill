<?php

use PHPUnit\Framework\TestCase;

final class ExternalArtistOnboardingTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				1 => array( 'terms' => array(), 'term_meta' => array(), 'posts' => array(), 'post_meta' => array() ),
				4 => array( 'terms' => array(), 'term_meta' => array(), 'posts' => array(), 'post_meta' => array() ),
			),
			'users'           => array(),
			'user_meta'       => array(),
			'email_users'     => array(),
		);

		wp_register_ability(
			'extrachill/create-user',
			array(
				'execute_callback'    => function ( $input ) {
					$email = strtolower( $input['email'] );
					if ( email_exists( $email ) ) {
						return new WP_Error( 'existing_user_email', 'Email already exists.' );
					}
					$user_id = count( $GLOBALS['ec_test']['users'] ) + 1;
					$GLOBALS['ec_test']['users'][ $user_id ] = (object) array(
						'ID'           => $user_id,
						'user_login'   => $input['username'],
						'user_email'   => $email,
						'display_name' => $input['username'],
					);
					$GLOBALS['ec_test']['email_users'][ $email ] = $user_id;
					if ( ! empty( $input['unclaimed'] ) ) {
						update_user_meta( $user_id, 'ec_unclaimed', 1 );
					}
					return $user_id;
				},
				'permission_callback' => '__return_true',
				'meta'                => array(),
			)
		);
		extrachill_artist_platform_register_abilities();
		wp_register_ability(
			'extrachill/get-artist-data',
			array(
				'execute_callback'    => function ( $input ) {
					return array( 'id' => (int) $input['artist_id'], 'name' => get_the_title( $input['artist_id'] ) );
				},
				'permission_callback' => '__return_true',
				'meta'                => array(),
			)
		);
	}

	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'submitter_email' => 'artist@example.com',
				'artist_name'     => 'Test Artist',
				'source_type'     => 'external_form',
				'source_id'       => 'lead-123',
				'return_url'      => 'https://caller.example/complete',
			),
			$overrides
		);
	}

	private function addUser( $user_id, $email, $unclaimed = false ): void {
		$GLOBALS['ec_test']['users'][ $user_id ] = (object) array(
			'ID'           => $user_id,
			'user_login'   => 'user-' . $user_id,
			'user_email'   => $email,
			'display_name' => 'User ' . $user_id,
		);
		$GLOBALS['ec_test']['email_users'][ strtolower( $email ) ] = $user_id;
		if ( $unclaimed ) {
			$GLOBALS['ec_test']['user_meta'][ $user_id ]['ec_unclaimed'] = 1;
		}
	}

	private function addProfile( $profile_id, $name = 'Test Artist' ): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'][ $profile_id ] = (object) array(
			'ID'          => $profile_id,
			'post_type'   => 'artist_profile',
			'post_status' => 'publish',
			'post_title'  => $name,
			'post_name'   => sanitize_title( $name ),
			'post_author' => 99,
		);
	}

	private function addTerm( $term_id, $name = 'Test Artist' ): void {
		$GLOBALS['ec_test']['blogs'][1]['terms'][ $term_id ] = (object) array(
			'term_id'  => $term_id,
			'taxonomy' => 'artist',
			'slug'     => sanitize_title( $name ),
		);
	}

	private function assertRequiredSchemaShape( array $schema, array $value ): void {
		foreach ( $schema['required'] ?? array() as $required ) {
			$this->assertArrayHasKey( $required, $value );
		}
		if ( false === ( $schema['additionalProperties'] ?? true ) ) {
			$this->assertSame( array(), array_diff( array_keys( $value ), array_keys( $schema['properties'] ?? array() ) ) );
		}
		foreach ( $schema['properties'] ?? array() as $key => $property_schema ) {
			if ( ! array_key_exists( $key, $value ) ) {
				continue;
			}
			$types       = (array) ( $property_schema['type'] ?? array() );
			$actual_type = is_int( $value[ $key ] ) ? 'integer' : ( is_bool( $value[ $key ] ) ? 'boolean' : ( is_array( $value[ $key ] ) ? 'object' : ( is_null( $value[ $key ] ) ? 'null' : gettype( $value[ $key ] ) ) ) );
			$this->assertContains( $actual_type, $types, $key . ' has the wrong schema type.' );
			if ( isset( $property_schema['enum'] ) ) {
				$this->assertContains( $value[ $key ], $property_schema['enum'], $key . ' is outside the schema enum.' );
			}
			if ( is_array( $value[ $key ] ) && 'object' === $actual_type ) {
				$this->assertRequiredSchemaShape( $property_schema, $value[ $key ] );
			}
		}
	}

	public function test_new_submitter_gets_one_unclaimed_account_and_claim_email_across_retries(): void {
		$first  = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$second = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );

		$this->assertSame( 'account_claim_required', $first['outcome'] );
		$this->assertTrue( $first['user']['created'] );
		$this->assertSame( 'sent', $first['claim']['delivery'] );
		$this->assertSame( 'eligible_after_claim_and_consent', $first['artist']['state'] );
		$this->assertSame( 'account_claim_required', $second['outcome'] );
		$this->assertFalse( $second['user']['created'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['users'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['claim_deliveries'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_existing_claimed_user_must_authenticate_then_consent(): void {
		$this->addUser( 7, 'artist@example.com' );

		$anonymous = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$this->assertSame( 'authentication_required', $anonymous['outcome'] );

		$GLOBALS['ec_test']['current_user_id'] = 7;
		$authenticated = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$this->assertSame( 'artist_consent_required', $authenticated['outcome'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_authenticated_consent_creates_artist_membership_binding_and_link_page_once(): void {
		$this->addUser( 7, 'artist@example.com' );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$input = $this->input(
			array(
				'consent' => array(
					'profile_creation'  => true,
					'link_page'          => true,
					'disclosure_version' => 'artist-offer-v1',
				),
			)
		);

		$first = extrachill_artist_platform_ability_onboard_external_artist( $input );
		$this->assertSame( 'artist_created', $first['outcome'] );
		$this->assertSame( 'managed', $first['membership']['state'] );
		$this->assertSame( 'created', $first['link_page']['state'] );
		$this->assertNotNull( $first['artist']['term_id'] );
		$sources = get_post_meta( $first['artist']['profile_id'], '_ec_external_onboarding_sources', true );
		$this->assertSame( 'external_form', reset( $sources )['type'] );
		$this->assertSame( 'lead-123', reset( $sources )['id'] );
		$this->assertSame( array( 'artist-offer-v1' ), reset( $sources )['disclosure_versions'] );

		$profile_id = $first['artist']['profile_id'];
		$GLOBALS['ec_test']['managed_artists'][7] = array( $profile_id );
		$second = extrachill_artist_platform_ability_onboard_external_artist( $input );
		$this->assertSame( 'managed_artist', $second['outcome'] );
		$this->assertSame( 'existing', $second['link_page']['state'] );
		$this->assertSame( $first['link_page']['id'], $second['link_page']['id'] );
		$this->assertCount( 2, $GLOBALS['ec_test']['blogs'][4]['posts'] );

		$without_consent = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$this->assertSame( 'managed_artist', $without_consent['outcome'] );
		$sources = get_post_meta( $profile_id, '_ec_external_onboarding_sources', true );
		$this->assertTrue( reset( $sources )['profile_creation'] );
		$this->assertTrue( reset( $sources )['link_page'] );
		$this->assertSame( array( 'artist-offer-v1' ), reset( $sources )['disclosure_versions'] );
	}

	public function test_existing_managed_artist_reuses_link_page(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['current_user_id']     = 7;
		$GLOBALS['ec_test']['managed_artists'][7] = array( 20 );
		$GLOBALS['ec_test']['blogs'][4]['posts'][30] = (object) array(
			'ID' => 30, 'post_type' => 'artist_link_page', 'post_status' => 'publish', 'post_title' => 'Test Artist', 'post_name' => 'test-artist',
		);
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_extrch_link_page_id'] = 30;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30]['_associated_artist_profile_id'] = 20;

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );

		$this->assertSame( 'managed_artist', $result['outcome'] );
		$this->assertSame( array( 'state' => 'existing', 'id' => 30 ), $result['link_page'] );
	}

	public function test_existing_unowned_artist_requires_membership_request_without_grant_or_invite(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['current_user_id'] = 7;

		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'consent' => array( 'profile_creation' => true, 'link_page' => true, 'disclosure_version' => 'artist-offer-v1' ) ) )
		);

		$this->assertSame( 'membership_request_required', $result['outcome'] );
		$this->assertSame( 'request_required', $result['membership']['state'] );
		$this->assertEmpty( get_post_meta( 20, '_pending_invitations', true ) );
		$this->assertEmpty( get_user_meta( 7, '_artist_profile_ids', true ) );
	}

	public function test_existing_unclaimed_account_cannot_create_or_join_artist(): void {
		$this->addUser( 7, 'artist@example.com', true );
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['current_user_id'] = 7;

		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'consent' => array( 'profile_creation' => true, 'link_page' => true, 'disclosure_version' => 'artist-offer-v1' ) ) )
		);

		$this->assertSame( 'account_claim_required', $result['outcome'] );
		$this->assertSame( 'claim_account', $result['next_action'] );
		$this->assertEmpty( get_user_meta( 7, '_artist_profile_ids', true ) );
	}

	public function test_duplicate_canonical_artist_name_never_creates_profile(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addTerm( 50 );
		$GLOBALS['ec_test']['current_user_id'] = 7;

		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'consent' => array( 'profile_creation' => true, 'disclosure_version' => 'artist-offer-v1' ) ) )
		);

		$this->assertSame( 'membership_request_required', $result['outcome'] );
		$this->assertSame( 50, $result['artist']['term_id'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_conflicting_user_and_artist_identities_fail_closed(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addProfile( 20, 'One Artist' );
		$this->addProfile( 21, 'Two Artist' );
		$this->addTerm( 50, 'One Artist' );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_term_id'] = 50;
		$GLOBALS['ec_test']['blogs'][1]['term_meta'][50]['_artist_profile_id'] = 20;

		$user_conflict = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'submitter_user_id' => 7, 'submitter_email' => 'other@example.com' ) )
		);
		$this->assertSame( 'conflicting_submitter_identity', $user_conflict->get_error_code() );

		$artist_conflict = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'artist_profile_id' => 21, 'artist_term_id' => 50 ) )
		);
		$this->assertSame( 'conflicting_artist_identity', $artist_conflict->get_error_code() );
	}

	public function test_ability_is_internal_and_declared_idempotent(): void {
		$meta = wp_get_ability( 'extrachill/onboard-external-artist' )->get_meta();

		$this->assertFalse( $meta['show_in_rest'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
	}

	public function test_anonymous_existing_owner_cannot_provision_link_page(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['managed_artists'][7] = array( 20 );

		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input(
				array(
					'consent' => array( 'link_page' => true, 'disclosure_version' => 'artist-offer-v1' ),
				)
			)
		);

		$this->assertSame( 'authentication_required', $result['outcome'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertEmpty( get_post_meta( 20, '_extrch_link_page_id', true ) );
	}

	public function test_invalid_artist_identity_does_not_create_account(): void {
		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'artist_profile_id' => 999 ) )
		);

		$this->assertSame( 'invalid_artist_identity', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['users'] );
		$this->assertArrayNotHasKey( 'claim_deliveries', $GLOBALS['ec_test'] );
	}

	public function test_failed_claim_delivery_is_retried_until_sent(): void {
		$GLOBALS['ec_test']['fail_claim_delivery'] = true;
		$first = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$this->assertSame( 'failed', $first['claim']['delivery'] );

		$GLOBALS['ec_test']['fail_claim_delivery'] = false;
		$second = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$third  = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );

		$this->assertSame( 'sent', $second['claim']['delivery'] );
		$this->assertSame( 'previously_sent', $third['claim']['delivery'] );
		$this->assertCount( 2, $GLOBALS['ec_test']['claim_deliveries'] );
	}

	public function test_explicit_identity_must_match_submitted_name(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addProfile( 20, 'Different Artist' );

		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'artist_profile_id' => 20 ) )
		);

		$this->assertSame( 'conflicting_artist_identity', $result->get_error_code() );
	}

	public function test_mutating_consent_requires_disclosure_version(): void {
		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'consent' => array( 'profile_creation' => true ) ) )
		);

		$this->assertSame( 'missing_consent_disclosure', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['users'] );
	}

	public function test_post_lock_identity_resolution_prevents_concurrent_duplicate_profile(): void {
		$this->addUser( 7, 'artist@example.com' );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['after_external_artist_lock'] = function () {
			$this->addProfile( 20 );
		};

		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input(
				array(
					'consent' => array( 'profile_creation' => true, 'disclosure_version' => 'artist-offer-v1' ),
				)
			)
		);

		$this->assertSame( 'membership_request_required', $result['outcome'] );
		$this->assertSame( 20, $result['artist']['profile_id'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_provenance_failure_rolls_back_new_profile_before_link_creation(): void {
		$this->addUser( 7, 'artist@example.com' );
		$GLOBALS['ec_test']['current_user_id']     = 7;
		$GLOBALS['ec_test']['fail_post_meta_update'] = true;

		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input(
				array(
					'consent' => array(
						'profile_creation'  => true,
						'link_page'          => true,
						'disclosure_version' => 'artist-offer-v1',
					),
				)
			)
		);

		$this->assertSame( 'artist_onboarding_source_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertEmpty( get_user_meta( 7, '_artist_profile_ids', true ) );
	}

	public function test_artist_name_requires_a_stable_slug(): void {
		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'artist_name' => '!!!' ) )
		);

		$this->assertSame( 'invalid_artist_name', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['users'] );
	}

	public function test_stale_claim_delivery_reservation_is_recovered(): void {
		$this->addUser( 7, 'artist@example.com', true );
		$GLOBALS['ec_test']['user_meta'][7]['_ec_artist_onboarding_claim_delivery'] = array(
			'state'      => 'pending',
			'started_at' => time() - ( 16 * MINUTE_IN_SECONDS ),
		);

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );

		$this->assertSame( 'sent', $result['claim']['delivery'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['claim_deliveries'] );
	}

	public function test_indirect_identity_mismatch_fails_closed(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addProfile( 20, 'Different Artist' );
		$this->addTerm( 50 );
		$GLOBALS['ec_test']['blogs'][1]['term_meta'][50]['_artist_profile_id'] = 20;

		$result = extrachill_artist_platform_ability_onboard_external_artist(
			$this->input( array( 'artist_term_id' => 50 ) )
		);

		$this->assertSame( 'conflicting_artist_identity', $result->get_error_code() );
	}

	public function test_link_page_association_failure_rolls_back_and_retry_creates_one_page(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['managed_artists'][7] = array( 20 );
		$GLOBALS['ec_test']['fail_post_meta_update_keys']['_extrch_link_page_id'] = 1;
		$input = $this->input(
			array(
				'consent' => array( 'link_page' => true, 'disclosure_version' => 'artist-offer-v1' ),
			)
		);

		$failed = extrachill_artist_platform_ability_onboard_external_artist( $input );
		$this->assertSame( 'link_page_association_failed', $failed->get_error_code() );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertEmpty( get_post_meta( 20, '_extrch_link_page_id', true ) );

		$retried = extrachill_artist_platform_ability_onboard_external_artist( $input );
		$this->assertSame( 'managed_artist', $retried['outcome'] );
		$this->assertSame( 'created', $retried['link_page']['state'] );
		$link_pages = array_filter(
			$GLOBALS['ec_test']['blogs'][4]['posts'],
			static function ( $post ) {
				return 'artist_link_page' === $post->post_type;
			}
		);
		$this->assertCount( 1, $link_pages );
		$this->assertSame( 20, (int) get_post_meta( $retried['link_page']['id'], '_associated_artist_profile_id', true ) );
	}

	public function test_link_page_missing_inverse_association_is_rolled_back(): void {
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['fail_meta_input_keys']['_associated_artist_profile_id'] = 1;

		$result = ec_create_link_page( 20 );

		$this->assertSame( 'link_page_association_failed', $result->get_error_code() );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertEmpty( get_post_meta( 20, '_extrch_link_page_id', true ) );
	}

	public function test_binding_failure_removes_new_empty_term_and_retry_succeeds(): void {
		$this->addUser( 7, 'artist@example.com' );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['fail_term_meta_update_keys']['_artist_profile_id'] = 1;
		$input = $this->input(
			array(
				'consent' => array( 'profile_creation' => true, 'disclosure_version' => 'artist-offer-v1' ),
			)
		);

		$failed = extrachill_artist_platform_ability_onboard_external_artist( $input );
		$this->assertSame( 'artist_term_binding_failed', $failed->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][1]['terms'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertEmpty( get_user_meta( 7, '_artist_profile_ids', true ) );

		$retried = extrachill_artist_platform_ability_onboard_external_artist( $input );
		$this->assertSame( 'artist_created', $retried['outcome'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][1]['terms'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertNotNull( $retried['artist']['term_id'] );
	}

	public function test_active_claim_send_is_suppressed_and_expired_send_is_reissued(): void {
		$this->addUser( 7, 'artist@example.com', true );
		$GLOBALS['ec_test']['user_meta'][7]['_ec_artist_onboarding_claim_delivery'] = array(
			'state'   => 'sent',
			'sent_at' => time(),
		);

		$active = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$this->assertSame( 'previously_sent', $active['claim']['delivery'] );
		$this->assertArrayNotHasKey( 'claim_deliveries', $GLOBALS['ec_test'] );

		$GLOBALS['ec_test']['user_meta'][7]['_ec_artist_onboarding_claim_delivery']['sent_at'] = time() - DAY_IN_SECONDS - 1;
		$expired = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$this->assertSame( 'sent', $expired['claim']['delivery'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['claim_deliveries'] );
	}

	public function test_registered_output_contract_requires_nested_response_shape(): void {
		$ability = wp_get_ability( 'extrachill/onboard-external-artist' );
		$schema  = $ability->get_output_schema();
		$GLOBALS['ec_test']['allow_external_artist_onboarding'] = true;
		$result  = $ability->execute( $this->input() );

		$this->assertSame(
			array( 'outcome', 'user', 'artist', 'membership', 'claim', 'link_page', 'source', 'return_url', 'next_action' ),
			$schema['required']
		);
		$this->assertRequiredSchemaShape( $schema, $result );
		$this->assertSame( array( 'id', 'state', 'created' ), $schema['properties']['user']['required'] );
		$this->assertSame( array( 'name', 'profile_id', 'term_id', 'state' ), $schema['properties']['artist']['required'] );
	}

	public function test_external_onboarding_ability_requires_explicit_internal_caller_opt_in(): void {
		$ability = wp_get_ability( 'extrachill/onboard-external-artist' );

		$denied = $ability->execute( $this->input() );
		$this->assertSame( 'ability_permission_denied', $denied->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['users'] );

		$GLOBALS['ec_test']['allow_external_artist_onboarding'] = true;
		$allowed = $ability->execute( $this->input() );
		$this->assertSame( 'account_claim_required', $allowed['outcome'] );
	}

	public function test_inverse_only_link_page_association_is_repaired_and_reused(): void {
		$this->addUser( 7, 'artist@example.com' );
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['managed_artists'][7] = array( 20 );
		$GLOBALS['ec_test']['blogs'][4]['posts'][30] = (object) array(
			'ID' => 30, 'post_type' => 'artist_link_page', 'post_status' => 'publish', 'post_title' => 'Test Artist', 'post_name' => 'test-artist',
		);
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30]['_associated_artist_profile_id'] = 20;

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );

		$this->assertSame( 'managed_artist', $result['outcome'] );
		$this->assertSame( array( 'state' => 'existing', 'id' => 30 ), $result['link_page'] );
		$this->assertSame( 30, (int) get_post_meta( 20, '_extrch_link_page_id', true ) );
		$this->assertCount( 2, $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_failed_term_delete_leaves_recoverable_term_for_successful_retry(): void {
		$this->addUser( 7, 'artist@example.com' );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['fail_term_meta_update_keys']['_artist_profile_id'] = 1;
		$GLOBALS['ec_test']['fail_term_delete'] = true;
		$input = $this->input(
			array(
				'consent' => array( 'profile_creation' => true, 'disclosure_version' => 'artist-offer-v1' ),
			)
		);

		$failed = extrachill_artist_platform_ability_onboard_external_artist( $input );
		$this->assertSame( 'artist_term_binding_failed', $failed->get_error_code() );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][1]['terms'] );
		$this->assertSame( 'test-artist', $GLOBALS['ec_test']['blogs'][1]['term_meta'][1]['_ec_artist_binding_recoverable'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );

		$GLOBALS['ec_test']['fail_term_delete'] = false;
		$retried = extrachill_artist_platform_ability_onboard_external_artist( $input );
		$this->assertSame( 'artist_created', $retried['outcome'] );
		$this->assertSame( 1, $retried['artist']['term_id'] );
		$this->assertArrayNotHasKey( '_ec_artist_binding_recoverable', $GLOBALS['ec_test']['blogs'][1]['term_meta'][1] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][1]['terms'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_legacy_claim_marker_is_reissued_instead_of_suppressed_forever(): void {
		$this->addUser( 7, 'artist@example.com', true );
		$GLOBALS['ec_test']['user_meta'][7]['_ec_artist_onboarding_claim_delivery'] = 1;

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );

		$this->assertSame( 'sent', $result['claim']['delivery'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['claim_deliveries'] );
	}

	public function test_inverse_only_repair_failure_does_not_create_duplicate_link_page(): void {
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['blogs'][4]['posts'][30] = (object) array(
			'ID' => 30, 'post_type' => 'artist_link_page', 'post_status' => 'publish', 'post_title' => 'Test Artist', 'post_name' => 'test-artist',
		);
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['fail_post_meta_update_keys']['_extrch_link_page_id'] = 1;

		$result = ec_create_link_page( 20 );

		$this->assertSame( 'link_page_association_repair_failed', $result->get_error_code() );
		$this->assertCount( 2, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertEmpty( get_post_meta( 20, '_extrch_link_page_id', true ) );
	}

	public function test_forced_link_replacement_failure_restores_previous_association(): void {
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['blogs'][4]['posts'][30] = (object) array(
			'ID' => 30, 'post_type' => 'artist_link_page', 'post_status' => 'publish', 'post_title' => 'Test Artist', 'post_name' => 'test-artist',
		);
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_extrch_link_page_id'] = 30;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['fail_meta_input_keys']['_associated_artist_profile_id'] = 1;

		$result = ec_create_link_page( 20, true );

		$this->assertSame( 'link_page_association_failed', $result->get_error_code() );
		$this->assertSame( 30, (int) get_post_meta( 20, '_extrch_link_page_id', true ) );
		$this->assertSame( 20, (int) get_post_meta( 30, '_associated_artist_profile_id', true ) );
		$this->assertCount( 2, $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_forced_link_replacement_rolls_back_when_previous_page_cannot_detach(): void {
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['blogs'][4]['posts'][30] = (object) array(
			'ID' => 30, 'post_type' => 'artist_link_page', 'post_status' => 'publish', 'post_title' => 'Test Artist', 'post_name' => 'test-artist',
		);
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_extrch_link_page_id'] = 30;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][30]['_associated_artist_profile_id'] = 20;
		$GLOBALS['ec_test']['fail_post_meta_delete_keys']['_associated_artist_profile_id'] = 1;

		$result = ec_create_link_page( 20, true );

		$this->assertSame( 'link_page_previous_detach_failed', $result->get_error_code() );
		$this->assertSame( 30, (int) get_post_meta( 20, '_extrch_link_page_id', true ) );
		$this->assertSame( 20, (int) get_post_meta( 30, '_associated_artist_profile_id', true ) );
		$this->assertCount( 2, $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_persistent_profile_pointer_rollback_failure_keeps_new_page_for_manual_repair(): void {
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['fail_meta_input_keys']['_associated_artist_profile_id'] = 1;
		$GLOBALS['ec_test']['fail_post_meta_delete_keys']['_extrch_link_page_id'] = 5;

		$result = ec_create_link_page( 20 );

		$this->assertSame( 'link_page_association_compensation_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$new_link_id = (int) get_post_meta( 20, '_extrch_link_page_id', true );
		$this->assertGreaterThan( 0, $new_link_id );
		$this->assertSame( 'artist_link_page', get_post_type( $new_link_id ) );
		$this->assertEmpty( get_post_meta( $new_link_id, '_associated_artist_profile_id', true ) );
	}

	public function test_persistent_page_inverse_rollback_failure_keeps_page_for_manual_repair(): void {
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['fail_post_meta_update_keys']['_extrch_link_page_id'] = 1;
		$GLOBALS['ec_test']['fail_post_meta_delete_keys']['_associated_artist_profile_id'] = 5;

		$result = ec_create_link_page( 20 );

		$this->assertSame( 'link_page_association_compensation_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertEmpty( get_post_meta( 20, '_extrch_link_page_id', true ) );
		$this->assertCount( 2, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertSame( 20, (int) get_post_meta( 21, '_associated_artist_profile_id', true ) );
	}

	public function test_page_delete_failure_after_safe_metadata_compensation_requires_manual_repair(): void {
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['fail_meta_input_keys']['_associated_artist_profile_id'] = 1;
		$GLOBALS['ec_test']['fail_post_delete'] = true;

		$result = ec_create_link_page( 20 );

		$this->assertSame( 'link_page_association_compensation_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertEmpty( get_post_meta( 20, '_extrch_link_page_id', true ) );
		$this->assertSame( 'artist_link_page', get_post_type( 21 ) );
	}

	public function test_link_default_snapshot_is_optional_because_reads_merge_runtime_defaults(): void {
		$this->addProfile( 20 );
		$GLOBALS['ec_test']['fail_post_meta_update_keys']['_link_page_custom_css_vars'] = 5;

		$link_page_id = ec_create_link_page( 20 );
		$this->assertIsInt( $link_page_id );
		$this->assertEmpty( get_post_meta( $link_page_id, '_link_page_custom_css_vars', true ) );

		$data = ec_get_link_page_data( 20, $link_page_id );
		$this->assertSame( '#fff', $data['css_vars']['--link-page-card-bg-color'] );
		$this->assertSame( '#000', $data['css_vars']['--link-page-link-text-color'] );
	}

	public function test_manual_binding_compensation_failure_preserves_profile_for_reconciliation(): void {
		$this->addUser( 7, 'artist@example.com' );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['fail_post_meta_update_keys']['_artist_term_id'] = 1;
		$GLOBALS['ec_test']['fail_term_meta_delete_keys']['_artist_profile_id'] = 5;
		$input = $this->input(
			array(
				'consent' => array( 'profile_creation' => true, 'disclosure_version' => 'artist-offer-v1' ),
			)
		);

		$result = extrachill_artist_platform_ability_onboard_external_artist( $input );

		$this->assertSame( 'artist_binding_compensation_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertNotEmpty( get_user_meta( 7, '_artist_profile_ids', true ) );
		$this->assertSame( 1, $GLOBALS['ec_test']['blogs'][1]['term_meta'][1]['_artist_profile_id'] );
	}

	public function test_term_delete_success_without_final_deletion_is_manual_failure(): void {
		$this->addUser( 7, 'artist@example.com' );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['fail_term_meta_update_keys']['_artist_profile_id'] = 1;
		$GLOBALS['ec_test']['report_term_delete_success_without_delete'] = true;
		$input = $this->input(
			array(
				'consent' => array( 'profile_creation' => true, 'disclosure_version' => 'artist-offer-v1' ),
			)
		);

		$result = extrachill_artist_platform_ability_onboard_external_artist( $input );

		$this->assertSame( 'artist_term_binding_rollback_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][1]['terms'] );
	}

	public function test_persistent_term_reference_after_profile_delete_is_manual_failure(): void {
		$this->addUser( 7, 'artist@example.com' );
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['fail_term_meta_update_keys']['_artist_profile_id'] = 1;
		$GLOBALS['ec_test']['fail_term_meta_delete_keys']['_artist_profile_id'] = 10;
		$GLOBALS['ec_test']['before_post_delete'] = function ( $profile_id ) {
			$GLOBALS['ec_test']['blogs'][1]['terms'][99] = (object) array(
				'term_id'  => 99,
				'taxonomy' => 'artist',
				'slug'     => 'stale-reference',
				'count'    => 0,
			);
			$GLOBALS['ec_test']['blogs'][1]['term_meta'][99]['_artist_profile_id'] = $profile_id;
		};
		$input = $this->input(
			array(
				'consent' => array( 'profile_creation' => true, 'disclosure_version' => 'artist-offer-v1' ),
			)
		);

		$result = extrachill_artist_platform_ability_onboard_external_artist( $input );

		$this->assertSame( 'artist_onboarding_rollback_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['blogs'][1]['term_meta'][99]['_artist_profile_id'] );
	}
}
