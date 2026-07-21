<?php
/**
 * Onboarding-origin artist access analytics tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify grants are emitted once for a completed access transition.
 */
class Test_Onboarding_Artist_Access_Grant extends WP_UnitTestCase {

	/**
	 * Whether this test registered the analytics fixture.
	 *
	 * @var bool
	 */
	private $registered_fake_analytics = false;

	/**
	 * Install a bounded analytics capture ability.
	 */
	protected function setUp(): void {
		parent::setUp();
		$constants = array(
			'EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED' => 'artist_access_granted',
			'EC_ANALYTICS_ARTIST_ACCESS_GRANTED_SOURCE_ONBOARDING' => 'onboarding',
			'EC_ANALYTICS_EVENT_ONBOARDING_COMPLETED'  => 'onboarding_completed',
			'EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_RECOVERED' => 'onboarding_reminder_recovered',
			'EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED' => 'onboarding_submission_failed',
		);
		foreach ( $constants as $constant => $value ) {
			if ( ! defined( $constant ) ) {
				define( $constant, $value );
			}
		}
		if ( ! defined( 'EC_ANALYTICS_ARTIST_ACCESS_GRANTED_METHODS' ) ) {
			define( 'EC_ANALYTICS_ARTIST_ACCESS_GRANTED_METHODS', array( 'artist', 'professional', 'artist_and_professional' ) );
		}
		$GLOBALS['ec_onboarding_grant_events']  = array();
		$GLOBALS['ec_onboarding_grant_failure'] = false;
		$locks                                  = &ec_users_onboarding_lock_registry();
		$locks                                  = array();

		if ( ! wp_has_ability( 'extrachill/track-analytics-event' ) ) {
			wp_register_ability(
				'extrachill/track-analytics-event',
				array(
					'label'               => 'Test analytics',
					'description'         => 'Captures onboarding grant events.',
					'category'            => 'extrachill-users',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'integer' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => static function ( $input ) {
						if ( ! empty( $GLOBALS['ec_onboarding_grant_failure'] ) && EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED === $input['event_type'] ) {
							return 0;
						}
						$GLOBALS['ec_onboarding_grant_events'][] = $input;
						return count( $GLOBALS['ec_onboarding_grant_events'] );
					},
				)
			);
			$this->registered_fake_analytics = true;
		}
	}

	/**
	 * Remove analytics fixtures.
	 */
	protected function tearDown(): void {
		$locks = &ec_users_onboarding_lock_registry();
		$locks = array();
		if ( $this->registered_fake_analytics ) {
			wp_unregister_ability( 'extrachill/track-analytics-event' );
		}
		unset( $GLOBALS['ec_onboarding_grant_events'], $GLOBALS['ec_onboarding_grant_failure'] );
		parent::tearDown();
	}

	/**
	 * A real transition emits one exact privacy-safe payload.
	 */
	public function test_transition_emits_exact_bounded_payload(): void {
		$user_id = $this->create_onboarding_user( 'granttransition' );

		$result = $this->complete( $user_id, true, true );
		$events = $this->grant_events();

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $events );
		$this->assertSame(
			array(
				'user_id' => $user_id,
				'source'  => 'onboarding',
				'method'  => 'artist_and_professional',
			),
			$events[0]['event_data']
		);
		$this->assertSame( array( 'user_id', 'source', 'method' ), array_keys( $events[0]['event_data'] ) );
	}

	/**
	 * Replaying a completed request cannot duplicate conversion.
	 */
	public function test_completed_replay_does_not_duplicate_grant(): void {
		$user_id = $this->create_onboarding_user( 'grantreplay' );

		$this->complete( $user_id, true, false );
		$replay = $this->complete( $user_id, true, false );

		$this->assertWPError( $replay );
		$this->assertSame( 'already_completed', $replay->get_error_code() );
		$this->assertCount( 1, $this->grant_events() );
	}

	/**
	 * A confirmed delivery failure returns to pending and succeeds once on retry.
	 */
	public function test_pending_receipt_recovers_delivery_once_on_retry(): void {
		$user_id = $this->create_onboarding_user( 'grantdeliveryretry' );

		$GLOBALS['ec_onboarding_grant_failure'] = true;
		$failed                                 = $this->complete( $user_id, true, false );

		$GLOBALS['ec_onboarding_grant_failure'] = false;

		$this->assertWPError( $failed );
		$this->assertSame( 'onboarding_grant_delivery_failed', $failed->get_error_code() );
		$this->assertSame( 'retry', $failed->get_error_data()['classification'] );
		$this->assertSame( 'pending', get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true )['status'] );
		$this->assertCount( 0, $this->grant_events() );

		$retry = $this->complete( $user_id, true, false );
		$this->assertWPError( $retry );
		$this->assertSame( 'already_completed', $retry->get_error_code() );
		$this->assertSame( 'delivered', get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true )['status'] );
		$this->assertCount( 1, $this->grant_events() );
	}

	/**
	 * An ambiguous post-delivery receipt remains reserved and never re-emits.
	 */
	public function test_reserved_receipt_requires_repair_without_duplicate_delivery(): void {
		$user_id       = $this->create_onboarding_user( 'grantreserved' );
		$block_receipt = static function ( $check, $object_id, $meta_key, $meta_value ) use ( $user_id ) {
			if (
				$user_id === (int) $object_id
				&& EC_USERS_ONBOARDING_ARTIST_GRANT_META === $meta_key
				&& is_array( $meta_value )
				&& 'delivered' === ( $meta_value['status'] ?? '' )
			) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $block_receipt, 10, 4 );

		try {
			$result = $this->complete( $user_id, true, false );
		} finally {
			remove_filter( 'update_user_metadata', $block_receipt, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'onboarding_grant_repair_required', $result->get_error_code() );
		$this->assertSame( 'manual_repair', $result->get_error_data()['classification'] );
		$this->assertSame( 'reserved', get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true )['status'] );
		$this->assertCount( 1, $this->grant_events() );

		$retry = $this->complete( $user_id, true, false );
		$this->assertWPError( $retry );
		$this->assertSame( 'onboarding_grant_repair_required', $retry->get_error_code() );
		$this->assertCount( 1, $this->grant_events() );
	}

	/**
	 * An interrupted pre-persistence receipt adopts the current retry intent.
	 *
	 * @dataProvider changed_incomplete_intent_provider
	 *
	 * @param string $initial_method Initial stale method.
	 * @param bool   $artist         Retry artist intent.
	 * @param bool   $professional   Retry professional intent.
	 * @param string $expected       Expected emitted method, or empty for none.
	 */
	public function test_incomplete_pending_receipt_uses_current_retry_intent( $initial_method, $artist, $professional, $expected ): void {
		$user_id = $this->create_onboarding_user( 'grantintent' . str_replace( '_', '', $initial_method ) . $expected );
		update_user_meta(
			$user_id,
			EC_USERS_ONBOARDING_ARTIST_GRANT_META,
			array(
				'status'     => 'pending',
				'method'     => $initial_method,
				'created_at' => time(),
			)
		);

		$result = $this->complete( $user_id, $artist, $professional );
		$events = $this->grant_events();

		$this->assertNotWPError( $result );
		if ( '' === $expected ) {
			$this->assertCount( 0, $events );
			$this->assertFalse( metadata_exists( 'user', $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META ) );
			return;
		}
		$this->assertCount( 1, $events );
		$this->assertSame( $expected, $events[0]['event_data']['method'] );
		$this->assertSame( 'delivered', get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true )['status'] );
	}

	/**
	 * Changed incomplete intent cases.
	 *
	 * @return array<string,array{string,bool,bool,string}>
	 */
	public function changed_incomplete_intent_provider() {
		return array(
			'professional to artist' => array( 'professional', true, false, 'artist' ),
			'artist to professional' => array( 'artist', false, true, 'professional' ),
			'artist to both'         => array( 'artist', true, true, 'artist_and_professional' ),
			'both to neither'        => array( 'artist_and_professional', false, false, '' ),
		);
	}

	/**
	 * Persisted partial access plus changed intent requires reconciliation.
	 */
	public function test_incomplete_pending_receipt_rejects_changed_persisted_access(): void {
		$user_id = $this->create_onboarding_user( 'grantintentambiguous' );
		update_user_meta( $user_id, 'user_is_artist', '1' );
		update_user_meta(
			$user_id,
			EC_USERS_ONBOARDING_ARTIST_GRANT_META,
			array(
				'status'     => 'pending',
				'method'     => 'artist',
				'created_at' => time(),
			)
		);

		$result = $this->complete( $user_id, false, true );

		$this->assertWPError( $result );
		$this->assertSame( 'onboarding_grant_intent_repair_required', $result->get_error_code() );
		$this->assertSame( 'manual_repair', $result->get_error_data()['classification'] );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( 'pending', get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true )['status'] );
		$this->assertCount( 0, $this->grant_events() );
	}

	/**
	 * A completed pending receipt emits the method that actually persisted.
	 *
	 * @dataProvider changed_completed_intent_provider
	 *
	 * @param string $actual_method Actual persisted grant method.
	 * @param bool   $artist        Retry artist intent.
	 * @param bool   $professional  Retry professional intent.
	 */
	public function test_completed_pending_receipt_preserves_actual_transition( $actual_method, $artist, $professional ): void {
		$user_id = $this->create_onboarding_user( 'grantactual' . str_replace( '_', '', $actual_method ) );
		update_user_meta( $user_id, 'user_is_artist', in_array( $actual_method, array( 'artist', 'artist_and_professional' ), true ) ? '1' : '0' );
		update_user_meta( $user_id, 'user_is_professional', in_array( $actual_method, array( 'professional', 'artist_and_professional' ), true ) ? '1' : '0' );
		update_user_meta( $user_id, 'onboarding_completed', '1' );
		update_user_meta(
			$user_id,
			EC_USERS_ONBOARDING_ARTIST_GRANT_META,
			array(
				'status'     => 'pending',
				'method'     => $actual_method,
				'created_at' => time(),
			)
		);

		$result = $this->complete( $user_id, $artist, $professional );
		$events = $this->grant_events();

		$this->assertWPError( $result );
		$this->assertSame( 'already_completed', $result->get_error_code() );
		$this->assertCount( 1, $events );
		$this->assertSame( $actual_method, $events[0]['event_data']['method'] );
	}

	/**
	 * Changed post-persistence intent cases.
	 *
	 * @return array<string,array{string,bool,bool}>
	 */
	public function changed_completed_intent_provider() {
		return array(
			'artist then professional' => array( 'artist', false, true ),
			'professional then artist' => array( 'professional', true, false ),
			'both then artist'         => array( 'artist_and_professional', true, false ),
			'artist then neither'      => array( 'artist', false, false ),
		);
	}

	/**
	 * A same-request contender cannot reenter an owned transition lock.
	 */
	public function test_transition_lock_rejects_reentrant_owner(): void {
		$user_id = $this->create_onboarding_user( 'grantlock' );
		$lock    = ec_users_acquire_onboarding_lock( $user_id );
		$this->assertNotWPError( $lock );

		try {
			$contender = ec_users_acquire_onboarding_lock( $user_id );
		} finally {
			ec_users_release_onboarding_lock( $user_id, $lock );
		}

		$this->assertWPError( $contender );
		$this->assertSame( 'onboarding_transition_locked', $contender->get_error_code() );
		$this->assertSame( 'retry', $contender->get_error_data()['classification'] );
		$this->assertTrue( $contender->get_error_data()['retryable'] );
	}

	/**
	 * A reentrant completion loses the lock race and only the owner emits.
	 */
	public function test_reentrant_completion_has_one_transition_winner(): void {
		$user_id           = $this->create_onboarding_user( 'grantreentrant' );
		$inside_transition = false;
		$reentrant_result  = null;
		$reenter           = function ( $check, $object_id, $meta_key ) use ( $user_id, &$inside_transition, &$reentrant_result ) {
			if ( $user_id === (int) $object_id && 'user_is_artist' === $meta_key && ! $inside_transition ) {
				$inside_transition = true;
				$reentrant_result  = $this->complete( $user_id, true, false );
			}
			return $check;
		};
		add_filter( 'add_user_metadata', $reenter, 10, 3 );

		try {
			$result = $this->complete( $user_id, true, false );
		} finally {
			remove_filter( 'add_user_metadata', $reenter, 10 );
		}

		$this->assertNotWPError( $result );
		$this->assertWPError( $reentrant_result );
		$this->assertSame( 'onboarding_transition_locked', $reentrant_result->get_error_code() );
		$this->assertCount( 1, $this->grant_events() );
		$this->assertSame( 'delivered', get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true )['status'] );
	}

	/**
	 * Existing access, including a partial prior attempt, is not a conversion.
	 */
	public function test_already_granted_user_does_not_emit(): void {
		$user_id = $this->create_onboarding_user( 'alreadygranted' );
		update_user_meta( $user_id, 'user_is_artist', '1' );

		$result = $this->complete( $user_id, false, true );

		$this->assertNotWPError( $result );
		$this->assertSame( '1', get_user_meta( $user_id, 'user_is_professional', true ) );
		$this->assertCount( 0, $this->grant_events() );
	}

	/**
	 * A failed completion restores access state and emits no conversion.
	 */
	public function test_failed_completion_rolls_back_access_and_does_not_emit(): void {
		$user_id = $this->create_onboarding_user( 'grantrollback' );
		$block   = static function ( $check, $object_id, $meta_key, $meta_value ) use ( $user_id ) {
			if ( $user_id === (int) $object_id && 'onboarding_completed' === $meta_key && '1' === $meta_value ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $block, 10, 4 );

		try {
			$result = $this->complete( $user_id, true, false );
		} finally {
			remove_filter( 'update_user_metadata', $block, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'onboarding_state_update_failed', $result->get_error_code() );
		$this->assertSame( 'retry', $result->get_error_data()['classification'] );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertSame( '0', get_user_meta( $user_id, 'onboarding_completed', true ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'user_is_artist' ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'user_is_professional' ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META ) );
		$this->assertCount( 0, $this->grant_events() );
	}

	/**
	 * A failed rollback is classified for repair and cannot lose conversion.
	 */
	public function test_failed_rollback_requires_repair_and_blocks_silent_retry(): void {
		$user_id          = $this->create_onboarding_user( 'grantrollbackfailure' );
		$block_completion = static function ( $check, $object_id, $meta_key, $meta_value ) use ( $user_id ) {
			if ( $user_id === (int) $object_id && 'onboarding_completed' === $meta_key && '1' === $meta_value ) {
				return false;
			}
			return $check;
		};
		$block_rollback   = static function ( $check, $object_id, $meta_key ) use ( $user_id ) {
			if ( $user_id === (int) $object_id && 'user_is_artist' === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $block_completion, 10, 4 );
		add_filter( 'delete_user_metadata', $block_rollback, 10, 3 );

		try {
			$result = $this->complete( $user_id, true, false );
		} finally {
			remove_filter( 'update_user_metadata', $block_completion, 10 );
			remove_filter( 'delete_user_metadata', $block_rollback, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'onboarding_state_rollback_failed', $result->get_error_code() );
		$this->assertSame( 'manual_repair', $result->get_error_data()['classification'] );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( '1', get_user_meta( $user_id, 'user_is_artist', true ) );
		$this->assertSame( 'repair_required', get_user_meta( $user_id, EC_USERS_ONBOARDING_ARTIST_GRANT_META, true )['status'] );
		$this->assertCount( 0, $this->grant_events() );

		$retry = $this->complete( $user_id, true, false );
		$this->assertWPError( $retry );
		$this->assertSame( 'onboarding_grant_repair_required', $retry->get_error_code() );
		$this->assertSame( 'manual_repair', $retry->get_error_data()['classification'] );
		$this->assertCount( 0, $this->grant_events() );
	}

	/**
	 * Create an incomplete user fixture.
	 *
	 * @param string $login User login.
	 * @return int User ID.
	 */
	private function create_onboarding_user( $login ) {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.com',
			)
		);
		update_user_meta( $user_id, 'onboarding_completed', '0' );
		return $user_id;
	}

	/**
	 * Complete onboarding for a fixture.
	 *
	 * @param int  $user_id         User ID.
	 * @param bool $artist         Artist choice.
	 * @param bool $professional   Professional choice.
	 * @return array|WP_Error Result.
	 */
	private function complete( $user_id, $artist, $professional ) {
		return extrachill_users_ability_complete_onboarding(
			array(
				'user_id'              => $user_id,
				'username'             => get_userdata( $user_id )->user_login,
				'user_is_artist'       => $artist,
				'user_is_professional' => $professional,
			)
		);
	}

	/**
	 * Return only canonical grant events from all onboarding analytics.
	 *
	 * @return array[] Grant events.
	 */
	private function grant_events() {
		return array_values(
			array_filter(
				$GLOBALS['ec_onboarding_grant_events'],
				static function ( $event ) {
					return EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED === $event['event_type'];
				}
			)
		);
	}
}
