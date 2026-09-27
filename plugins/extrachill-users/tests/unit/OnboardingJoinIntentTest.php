<?php
/**
 * /join onboarding intents: one question decides the roles and reaches the
 * join destination filter.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify owner-registered join intents.
 */
class Test_Onboarding_Join_Intent extends WP_UnitTestCase {

	/**
	 * Intents fixture.
	 *
	 * @return array
	 */
	public static function intents() {
		return array(
			array(
				'id'    => 'artist',
				'label' => 'An artist or band',
				'roles' => array( 'artist' ),
			),
			array(
				'id'    => 'venue',
				'label' => 'A venue',
				'roles' => array( 'professional' ),
			),
			array(
				'id'    => '',
				'label' => 'Invalid, no id',
				'roles' => array( 'artist' ),
			),
			array(
				'id'    => 'bogus-role',
				'label' => 'Invalid role',
				'roles' => array( 'administrator' ),
			),
		);
	}

	/** Register fixtures. */
	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'EC_ANALYTICS_EVENT_ONBOARDING_COMPLETED', 'EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_RECOVERED', 'EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED', 'EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED', 'EC_ANALYTICS_ARTIST_ACCESS_GRANTED_SOURCE_ONBOARDING' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				define( $constant, strtolower( $constant ) );
			}
		}
		if ( ! defined( 'EC_ANALYTICS_ARTIST_ACCESS_GRANTED_METHODS' ) ) {
			define( 'EC_ANALYTICS_ARTIST_ACCESS_GRANTED_METHODS', array( 'artist', 'professional', 'artist_and_professional' ) );
		}
		add_filter( 'ec_onboarding_join_intents', array( __CLASS__, 'intents' ) );
	}

	/** Remove fixtures. */
	protected function tearDown(): void {
		remove_filter( 'ec_onboarding_join_intents', array( __CLASS__, 'intents' ) );
		remove_all_filters( 'ec_onboarding_join_destination' );
		parent::tearDown();
	}

	/** Invalid intents are dropped; valid ones keep order. */
	public function test_only_valid_intents_are_offered(): void {
		$this->assertSame( array( 'artist', 'venue' ), array_column( ec_users_get_onboarding_join_intents(), 'id' ) );
		$this->assertNull( ec_users_get_onboarding_join_intent( 'bogus-role' ) );
	}

	/** Picking "venue" sets the professional role and reaches the destination filter. */
	public function test_intent_sets_roles_and_reaches_destination(): void {
		$user_id = $this->join_user( 'joinintentvenue' );
		$seen    = array();
		add_filter(
			'ec_onboarding_join_destination',
			static function ( $url, $uid, $roles, $intent ) use ( &$seen ) {
				$seen = array( $roles, $intent );
				return 'https://venue.example.test/claim/';
			},
			10,
			4
		);

		$result = extrachill_users_ability_complete_onboarding(
			array(
				'user_id'     => $user_id,
				'username'    => 'joinintentvenue',
				'join_intent' => 'venue',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['user']['user_is_artist'] );
		$this->assertTrue( $result['user']['user_is_professional'] );
		$this->assertSame( 'venue', get_user_meta( $user_id, 'onboarding_join_intent', true ) );
		$this->assertSame( array( array( 'professional' ), 'venue' ), $seen );
		$this->assertSame( 'https://venue.example.test/claim/', $result['redirect_url'] );
	}

	/** An unknown intent is rejected instead of silently completing with no role. */
	public function test_unknown_intent_is_rejected(): void {
		$user_id = $this->join_user( 'joinintentbad' );
		$result  = extrachill_users_ability_complete_onboarding(
			array(
				'user_id'     => $user_id,
				'username'    => 'joinintentbad',
				'join_intent' => 'nope',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_join_intent', $result->get_error_code() );
	}

	/**
	 * A /join member who has not onboarded yet.
	 *
	 * @param string $login Login.
	 * @return int User ID.
	 */
	private function join_user( $login ) {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.com',
			)
		);
		update_user_meta( $user_id, 'onboarding_completed', '0' );
		update_user_meta( $user_id, 'onboarding_from_join', '1' );
		return $user_id;
	}
}
