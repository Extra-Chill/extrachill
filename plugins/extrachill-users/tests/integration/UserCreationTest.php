<?php
/**
 * Integration tests for user creation.
 */

class Test_User_Creation extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( function_exists( 'extrachill_users_register_create_user_ability' ) ) {
			extrachill_users_register_create_user_ability();
		}
	}

	private function create_user( array $overrides = array() ) {
		$data = array_merge(
			array(
				'username' => 'newuser',
				'password' => 'securepassword',
				'email'    => 'newuser@example.com',
			),
			$overrides
		);

		return extrachill_users_ability_create_user( $data );
	}

	public function test_create_user_minimal_data(): void {
		$user_id = $this->create_user();

		$this->assertIsInt( $user_id );
		$this->assertGreaterThan( 0, $user_id );

		$user = get_userdata( $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'newuser', $user->user_login );
		$this->assertSame( 'newuser@example.com', $user->user_email );
	}

	public function test_create_user_full_data(): void {
		$user_id = $this->create_user(
			array(
				'username'            => 'fulluser',
				'email'               => 'fulluser@example.com',
				'from_join'           => true,
				'registration_page'   => 'https://artist.extrachill.com/join/',
				'registration_source' => 'web',
				'registration_method' => 'standard',
			)
		);

		$this->assertSame( 'https://artist.extrachill.com/join/', get_user_meta( $user_id, 'registration_page', true ) );
		$this->assertSame( 'web', get_user_meta( $user_id, 'registration_source', true ) );
		$this->assertSame( 'standard', get_user_meta( $user_id, 'registration_method', true ) );
	}

	public function test_create_user_sets_onboarding_meta(): void {
		$user_id = $this->create_user(
			array(
				'username' => 'onboardinguser',
				'email'    => 'onboard@example.com',
			)
		);

		$this->assertSame( '0', get_user_meta( $user_id, 'onboarding_completed', true ) );
		$this->assertSame( '0', get_user_meta( $user_id, 'onboarding_from_join', true ) );
	}

	public function test_create_user_sets_explicit_private_concert_defaults(): void {
		$user_id = $this->create_user(
			array(
				'username' => 'privateconcertuser',
				'email'    => 'privateconcert@example.com',
			)
		);

		$this->assertSame( 'private', get_user_meta( $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, true ) );
		$this->assertSame( 'private', get_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, true ) );
	}

	public function test_core_user_registration_sets_private_concert_defaults(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'coreprivateconcertuser',
				'user_pass'  => 'securepassword',
				'user_email' => 'coreprivateconcert@example.com',
			)
		);

		$this->assertIsInt( $user_id );
		$this->assertSame( 'private', get_user_meta( $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, true ) );
		$this->assertSame( 'private', get_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, true ) );
	}

	public function test_core_user_registration_preserves_explicit_visibility_meta_input(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'corepublicconcertuser',
				'user_pass'  => 'securepassword',
				'user_email' => 'corepublicconcert@example.com',
				'meta_input' => array(
					EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY  => 'public',
					EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY => 'public',
				),
			)
		);

		$this->assertIsInt( $user_id );
		$this->assertSame( 'public', get_user_meta( $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, true ) );
		$this->assertSame( 'public', get_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, true ) );
	}

	public function test_create_user_from_join_flag(): void {
		$user_id = $this->create_user(
			array(
				'username'  => 'joinuser',
				'email'     => 'join@example.com',
				'from_join' => true,
			)
		);

		$this->assertSame( '1', get_user_meta( $user_id, 'onboarding_from_join', true ) );
	}

	public function test_create_user_missing_fields(): void {
		$result = extrachill_users_ability_create_user(
			array(
				'email' => 'nouser@example.com',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_fields', $result->get_error_code() );
	}

	public function test_create_user_duplicate_username(): void {
		self::factory()->user->create(
			array(
				'user_login' => 'existinguser',
				'user_email' => 'existing@example.com',
			)
		);

		$result = $this->create_user(
			array(
				'username' => 'existinguser',
				'email'    => 'new@example.com',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'existing_user_login', $result->get_error_code() );
	}

	public function test_create_user_duplicate_email(): void {
		self::factory()->user->create(
			array(
				'user_login' => 'firstuser',
				'user_email' => 'duplicate@example.com',
			)
		);

		$result = $this->create_user(
			array(
				'username' => 'seconduser',
				'email'    => 'duplicate@example.com',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'existing_user_email', $result->get_error_code() );
	}

	public function test_create_user_fires_action(): void {
		$this->create_user(
			array(
				'username'            => 'actionuser',
				'email'               => 'action@example.com',
				'registration_page'   => 'https://test.com/',
				'registration_source' => 'test',
				'registration_method' => 'test',
			)
		);

		$this->assertSame( 1, did_action( 'extrachill_new_user_registered' ) );
	}

	public function test_create_user_persists_referrer_and_utm(): void {
		$user_id = $this->create_user(
			array(
				'username' => 'attruser',
				'email'    => 'attr@example.com',
				'referrer' => 'https://www.reddit.com/r/Charleston/',
				'utm'      => array(
					'source'   => 'reddit',
					'medium'   => 'social',
					'campaign' => 'festival-wire',
				),
			)
		);

		$this->assertIsInt( $user_id );
		$this->assertSame( 'https://www.reddit.com/r/Charleston/', get_user_meta( $user_id, 'registration_referrer', true ) );

		$stored_utm = get_user_meta( $user_id, 'registration_utm', true );
		$this->assertSame(
			array(
				'source'   => 'reddit',
				'medium'   => 'social',
				'campaign' => 'festival-wire',
			),
			$stored_utm
		);
	}

	public function test_create_user_without_attribution_stores_nothing(): void {
		$user_id = $this->create_user(
			array(
				'username' => 'noattruser',
				'email'    => 'noattr@example.com',
			)
		);

		$this->assertSame( '', get_user_meta( $user_id, 'registration_referrer', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'registration_utm', true ) );
	}

	public function test_sanitize_utm_filters_to_canonical_keys(): void {
		$clean = extrachill_users_sanitize_utm(
			array(
				'source'    => 'reddit',
				'medium'    => '',
				'gclid'     => 'should-be-dropped',
				'campaign'  => 'spring',
			)
		);

		// Empty medium is dropped; unknown gclid is dropped.
		$this->assertSame(
			array(
				'source'   => 'reddit',
				'campaign' => 'spring',
			),
			$clean
		);
	}

	public function test_sanitize_utm_accepts_full_utm_prefixed_keys(): void {
		$clean = extrachill_users_sanitize_utm(
			array(
				'utm_source'   => 'newsletter',
				'utm_campaign' => 'sunday-chill',
			)
		);

		$this->assertSame(
			array(
				'source'   => 'newsletter',
				'campaign' => 'sunday-chill',
			),
			$clean
		);
	}

	public function test_sanitize_utm_non_array_yields_empty(): void {
		$this->assertSame( array(), extrachill_users_sanitize_utm( 'not-an-array' ) );
		$this->assertSame( array(), extrachill_users_sanitize_utm( null ) );
	}

	public function test_create_user_via_filter(): void {
		$user_id = apply_filters(
			'extrachill_create_community_user',
			false,
			array(
				'username' => 'filteruser',
				'password' => 'securepassword',
				'email'    => 'filter@example.com',
			)
		);

		$this->assertIsInt( $user_id );
		$this->assertGreaterThan( 0, $user_id );
	}
}
