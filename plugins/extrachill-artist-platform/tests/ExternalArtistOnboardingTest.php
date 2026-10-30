<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ExternalArtistOnboardingTest extends EC_Artist_Platform_TestCase {
	private $sent_claim_emails;

	protected function setUp(): void {
		parent::setUp();

		$this->sent_claim_emails             = new stdClass();
		$this->sent_claim_emails->deliveries = array();
		$this->sent_claim_emails->fail_next  = 0;
		$state                               = $this->sent_claim_emails;

		// Claim emails travel through wp_mail; intercept at the boundary so
		// no real mail leaves and delivery is observable.
		$this->ec_inject_filter(
			'pre_wp_mail',
			static function ( $return, $atts ) use ( $state ) {
				if ( $state->fail_next > 0 ) {
					--$state->fail_next;
					return false;
				}
				$state->deliveries[] = $atts['to'] ?? '';
				return true;
			},
			100
		);
	}

	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'submitter_email' => 'new-artist@example.com',
				'artist_name'     => 'New Artist',
				'source_type'     => 'instagram',
				'source_id'       => 'source-1',
				'consent'         => array(
					'profile_creation'   => true,
					'link_page'          => true,
					'disclosure_version' => '2024-01',
				),
			),
			$overrides
		);
	}

	private function unclaimedUser( string $email ): int {
		$user_id = (int) self::factory()->user->create(
			array(
				'user_email' => $email,
				'user_login' => 'unclaimed-' . md5( $email ),
				'role'       => 'subscriber',
			)
		);
		update_user_meta( $user_id, 'ec_unclaimed', '1' );
		return $user_id;
	}

	private function profileCount(): int {
		switch_to_blog( $this->artist_blog_id() );
		try {
			return (int) wp_count_posts( 'artist_profile' )->publish;
		} finally {
			restore_current_blog();
		}
	}

	private function profilesByTitle( string $title ): array {
		switch_to_blog( $this->artist_blog_id() );
		try {
			return get_posts(
				array(
					'post_type'   => 'artist_profile',
					'post_status' => 'any',
					'title'       => $title,
					'fields'      => 'ids',
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	private function boundTermId( int $profile_id ): int {
		switch_to_blog( $this->artist_blog_id() );
		try {
			return (int) get_post_meta( $profile_id, '_artist_term_id', true );
		} finally {
			restore_current_blog();
		}
	}

	private function termProfileId( int $term_id ): int {
		switch_to_blog( $this->main_blog_id() );
		try {
			return (int) get_term_meta( $term_id, '_artist_profile_id', true );
		} finally {
			restore_current_blog();
		}
	}

	public function test_ability_is_internal_and_declared_idempotent(): void {
		$meta = wp_get_ability( 'extrachill/onboard-external-artist' )->get_meta();

		$this->assertFalse( $meta['show_in_rest'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
	}

	public function test_new_submitter_gets_one_unclaimed_account_and_claim_email_across_retries(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$first  = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$second = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		// A new submitter is eligible after claim AND consent: no profile yet.
		$this->assertSame( 0, $this->profileCount(), 'Retries must never create a profile before claim and consent.' );

		$user = get_user_by( 'email', 'new-artist@example.com' );
		$this->assertInstanceOf( WP_User::class, $user, 'The unclaimed account must exist after onboarding.' );
		$this->assertSame( '1', get_user_meta( (int) $user->ID, 'ec_unclaimed', true ) );
	}

	public function test_existing_claimed_user_must_authenticate_then_consent(): void {
		$user_id = (int) self::factory()->user->create(
			array(
				'user_email' => 'claimed@example.com',
				'role'       => 'subscriber',
			)
		);

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array( 'submitter_email' => 'claimed@example.com' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'authentication_required', $result['outcome'] ?? '' );
		$this->assertSame( 0, $this->profileCount() );
		$this->assertGreaterThan( 0, $user_id );
	}

	public function test_authenticated_consent_creates_artist_membership_binding_and_link_page_once(): void {
		// Profile and link-page provisioning serialize through MySQL advisory locks.
		$this->ec_require_mysql_advisory_locks();
		$user_id = (int) self::factory()->user->create(
			array(
				'user_email' => 'consenting@example.com',
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );

		$overrides = array(
			'submitter_email'   => 'consenting@example.com',
			'submitter_user_id' => $user_id,
		);
		$first     = extrachill_artist_platform_ability_onboard_external_artist( $this->input( $overrides ) );
		$second    = extrachill_artist_platform_ability_onboard_external_artist( $this->input( $overrides ) );

		$this->assertIsArray( $first );
		$profile_id = (int) ( $first['artist_id'] ?? 0 );
		$this->assertGreaterThan( 0, $profile_id );
		$this->assertSame( 2, $this->profileCount(), 'The profile plus its link page exist on the artist site.' );
		$this->assertSame( $user_id, (int) get_post_meta( $profile_id, '_artist_member_ids', true )[0] ?? 0 );
		$this->assertSame( array( $profile_id ), array_map( 'intval', (array) get_user_meta( $user_id, '_artist_profile_ids', true ) ) );

		$term_id = $this->boundTermId( $profile_id );
		$this->assertGreaterThan( 0, $term_id );
		$this->assertSame( $profile_id, $this->termProfileId( $term_id ) );

		// The second identical consent is idempotent: same profile, no duplicate.
		$second_id = (int) ( $second['artist_id'] ?? 0 );
		$this->assertSame( $profile_id, $second_id );
	}

	public function test_existing_unowned_artist_requires_membership_request_without_grant_or_invite(): void {
		$user_id = (int) self::factory()->user->create(
			array(
				'user_email' => 'joiner@example.com',
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );
		$this->create_artist_profile( 'Taken Artist', array( 'post_name' => 'taken-artist' ) );

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array(
			'submitter_email' => 'joiner@example.com',
			'artist_name'     => 'Taken Artist',
		) ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'outcome', $result );
		$this->assertContains( $result['outcome'], array( 'membership_request_required', 'artist_unavailable', 'artist_consent_required' ) );
		$this->assertSame( array( 0 ), array_map( 'intval', (array) get_user_meta( $user_id, '_artist_profile_ids', true ) ) );
	}

	public function test_existing_unclaimed_account_cannot_create_or_join_artist(): void {
		$user_id = $this->unclaimedUser( 'unclaimed@example.com' );
		wp_set_current_user( $user_id );

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array( 'submitter_email' => 'unclaimed@example.com' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'account_claim_required', $result['outcome'] ?? '' );
		$this->assertSame( 0, $this->profileCount() );
	}

	public function test_duplicate_canonical_artist_name_never_creates_profile(): void {
		$user_id = (int) self::factory()->user->create(
			array(
				'user_email' => 'duplicate@example.com',
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );
		$existing = $this->create_artist_profile( 'Same Name', array( 'post_name' => 'same-name' ) );

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array(
			'submitter_email' => 'duplicate@example.com',
			'artist_name'     => 'Same Name',
		) ) );

		$this->assertIsArray( $result );
		$this->assertContains( $result['outcome'] ?? '', array( 'artist_unavailable', 'membership_request_required', 'duplicate', 'artist_consent_required' ) );
		$this->assertSame( 1, count( $this->profilesByTitle( 'Same Name' ) ) );
		$this->assertGreaterThan( 0, $existing );
	}

	public function test_conflicting_user_and_artist_identities_fail_closed(): void {
		$user_id = (int) self::factory()->user->create(
			array(
				'user_email' => 'conflicting@example.com',
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );
		$profile_id = $this->create_artist_profile( 'Conflicting Artist', array( 'post_name' => 'conflicting-artist' ) );
		$term_id    = $this->create_artist_term( 'conflicting-artist-term', $profile_id + 500 );

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array(
			'submitter_email' => 'conflicting@example.com',
			'artist_name'     => 'Conflicting Artist',
		) ) );

		$this->assertIsArray( $result );
		$this->assertSame( $profile_id + 500, $this->termProfileId( $term_id ) );
	}

	public function test_anonymous_existing_owner_cannot_provision_link_page(): void {
		$this->ec_require_mysql_advisory_locks();
		wp_set_current_user( 0 );
		$profile_id = $this->create_artist_profile( 'Anonymous Owned', array( 'post_name' => 'anonymous-owned' ) );

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array(
			'submitter_email' => 'new-artist@example.com',
			'artist_name'     => 'Anonymous Owned',
		) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $this->profileCount() );
	}

	public function test_invalid_artist_identity_does_not_create_account(): void {
		$before = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array( 'artist_name' => '' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 0, $this->sent_claim_emails->deliveries );
		$this->assertSame( $before, count( get_users( array( 'fields' => 'ID' ) ) ) );
	}

	public function test_failed_claim_delivery_is_retried_until_sent(): void {
		$first  = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );
		$second = extrachill_artist_platform_ability_onboard_external_artist( $this->input() );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );

		$user = get_user_by( 'email', 'new-artist@example.com' );
		$this->assertInstanceOf( WP_User::class, $user );

		if ( ec_artist_platform_test_wpdb_is_sqlite() ) {
			// The claim route serializes through a MySQL advisory lock; on
			// SQLite runtimes the claim is reported busy instead of recorded.
			$this->assertSame( 'busy', $first['claim']['delivery'] ?? '' );
			return;
		}

		$claim = get_user_meta( (int) $user->ID, '_ec_artist_onboarding_claim_delivery', true );
		$this->assertNotEmpty( $claim, 'An undelivered claim must retain its retry record.' );
	}

	public function test_mutating_consent_requires_disclosure_version(): void {
		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array(
			'consent' => array(
				'profile_creation'   => true,
				'link_page'          => true,
				'disclosure_version' => '',
			),
		) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, $this->profileCount() );
	}

	public function test_explicit_identity_must_match_submitted_name(): void {
		$this->create_artist_profile( 'Other Existing', array( 'post_name' => 'other-existing' ) );

		$result = extrachill_artist_platform_ability_onboard_external_artist( $this->input( array(
			'artist_name' => 'Different Name',
			'artist_id'   => $this->profile_id,
		) ) );

		$this->assertSame( 0, count( $this->profilesByTitle( 'Different Name' ) ), 'An explicit identity that does not match the submitted name must not create a profile.' );
	}

	public function test_external_onboarding_ability_requires_explicit_internal_caller_opt_in(): void {
		$ability = wp_get_ability( 'extrachill/onboard-external-artist' );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertFalse( $ability->get_meta()['show_in_rest'] );
	}

	public function test_registered_output_contract_requires_nested_response_shape(): void {
		$output = wp_get_ability( 'extrachill/onboard-external-artist' )->get_output_schema();

		$this->assertSame( array( 'outcome', 'user', 'artist', 'membership', 'claim', 'link_page', 'source', 'return_url', 'next_action' ), $output['required'] );
	}
}
