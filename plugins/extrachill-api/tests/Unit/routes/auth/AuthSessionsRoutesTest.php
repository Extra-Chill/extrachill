<?php
/**
 * REST transport tests for auth/sessions (Connected Apps).
 *
 * @package ExtraChill\API\Tests
 */

/**
 * Exercises the thin wp-native/auth-sessions and wp-native/auth-revoke-session
 * adapters: authentication, cross-user isolation, and canonical forwarding.
 */
class Auth_Sessions_Routes_Test extends WP_UnitTestCase {

	/**
	 * Session rows keyed by user ID.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private $sessions_by_user = array();

	/**
	 * Inputs received by the wp-native/auth-sessions ability.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $list_inputs = array();

	/**
	 * Inputs received by the wp-native/auth-revoke-session ability.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $revoke_inputs = array();

	/**
	 * Whether this test registered the controlled ability category.
	 *
	 * @var bool
	 */
	private $registered_category = false;

	/**
	 * Ability names registered by this test.
	 *
	 * @var string[]
	 */
	private $registered_abilities = array();

	/**
	 * Register controlled wp-native-auth ability doubles matching the
	 * documented (correct) output schema and rebuild REST routes.
	 */
	public function set_up() {
		parent::set_up();

		$this->sessions_by_user = array();
		$this->list_inputs      = array();
		$this->revoke_inputs    = array();

		if ( ! wp_has_ability_category( 'extrachill-api-auth-sessions-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-api-auth-sessions-tests',
				array(
					'label'       => 'Extra Chill API auth/sessions tests',
					'description' => 'Controlled wp-native-auth ability doubles for auth/sessions transport tests.',
				)
			);
			$this->registered_category = true;
		}

		$this->register_sessions_ability();
		$this->register_revoke_ability();

		do_action( 'rest_api_init' );
	}

	/**
	 * Remove controlled abilities and reset authentication state.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );

		foreach ( $this->registered_abilities as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}
		if ( $this->registered_category && wp_has_ability_category( 'extrachill-api-auth-sessions-tests' ) ) {
			wp_unregister_ability_category( 'extrachill-api-auth-sessions-tests' );
		}

		parent::tear_down();
	}

	/** Authenticated user lists its own sessions. */
	public function test_user_lists_own_sessions() {
		$user_id = self::factory()->user->create();
		$this->sessions_by_user[ $user_id ] = array( $this->session_row( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' ) );
		wp_set_current_user( $user_id );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/extrachill/v1/auth/sessions' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'sessions' => $this->sessions_by_user[ $user_id ] ),
			$response->get_data()
		);
		// The route never forwards anything: the list ability takes no input.
		$this->assertSame( array( array() ), $this->list_inputs );
	}

	/** A client-supplied user id cannot redirect the listing to another account. */
	public function test_supplied_user_id_cannot_list_another_users_sessions() {
		$actor_id  = self::factory()->user->create();
		$target_id = self::factory()->user->create();
		$this->sessions_by_user[ $target_id ] = array( $this->session_row( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) );
		$this->sessions_by_user[ $actor_id ]  = array();
		wp_set_current_user( $actor_id );

		$request = new WP_REST_Request( 'GET', '/extrachill/v1/auth/sessions' );
		$request->set_query_params( array( 'user_id' => $target_id ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'sessions' => array() ), $response->get_data() );
		// The spoofed user_id never reaches the ability call.
		$this->assertSame( array( array() ), $this->list_inputs );
	}

	/** Authenticated user revokes one of its own sessions by device_id. */
	public function test_user_revokes_own_session() {
		$user_id   = self::factory()->user->create();
		$device_id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
		$this->sessions_by_user[ $user_id ] = array( $this->session_row( $device_id ) );
		wp_set_current_user( $user_id );

		$response = rest_do_request( new WP_REST_Request( 'DELETE', '/extrachill/v1/auth/sessions/' . $device_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'revoked' => true ), $response->get_data() );
		$this->assertSame( array( array( 'device_id' => $device_id ) ), $this->revoke_inputs );
		$this->assertSame( array(), $this->sessions_by_user[ $user_id ] );
	}

	/** A client-supplied user id cannot redirect a revoke to another account. */
	public function test_supplied_user_id_cannot_revoke_another_users_session() {
		$actor_id  = self::factory()->user->create();
		$target_id = self::factory()->user->create();
		$device_id = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
		$this->sessions_by_user[ $target_id ] = array( $this->session_row( $device_id ) );
		wp_set_current_user( $actor_id );

		$request = new WP_REST_Request( 'DELETE', '/extrachill/v1/auth/sessions/' . $device_id );
		$request->set_query_params( array( 'user_id' => $target_id ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		// The ability double resolves "revoked" against the *acting* user's
		// own session set — the actor has no matching device_id, so no row
		// is removed anywhere, least of all the target's.
		$this->assertSame( array( 'revoked' => false ), $response->get_data() );
		$this->assertSame( array( array( 'device_id' => $device_id ) ), $this->revoke_inputs );
		$this->assertArrayNotHasKey( 'user_id', end( $this->revoke_inputs ) );
		$this->assertCount( 1, $this->sessions_by_user[ $target_id ] );
	}

	/** Malformed device_id is rejected by the route's own schema, before the ability runs. */
	public function test_malformed_device_id_is_rejected_before_ability_runs() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		// Correct length (matches the URL regex) but not a valid UUID v4 —
		// must fail the route's `pattern` arg constraint, not URL routing,
		// and must never reach the ability.
		$response = rest_do_request( new WP_REST_Request( 'DELETE', '/extrachill/v1/auth/sessions/00000000-0000-0000-0000-000000000000' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $this->revoke_inputs );
	}

	/** Logged-out callers are rejected for both list and revoke. */
	public function test_unauthenticated_requests_are_rejected() {
		$list = rest_do_request( new WP_REST_Request( 'GET', '/extrachill/v1/auth/sessions' ) );
		$this->assertSame( 401, $list->get_status() );

		$device_id = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
		$revoke    = rest_do_request( new WP_REST_Request( 'DELETE', '/extrachill/v1/auth/sessions/' . $device_id ) );
		$this->assertSame( 401, $revoke->get_status() );

		$this->assertSame( array(), $this->list_inputs );
		$this->assertSame( array(), $this->revoke_inputs );
	}

	/** Ability errors are preserved unchanged, not coerced into generic failures. */
	public function test_ability_error_is_preserved() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		wp_unregister_ability( 'wp-native/auth-sessions' );
		$this->register_sessions_ability(
			new WP_Error( 'token_service_unavailable', 'The wp-native-auth token service is not available.', array( 'status' => 500 ) )
		);
		do_action( 'rest_api_init' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/extrachill/v1/auth/sessions' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'token_service_unavailable', $response->get_data()['code'] );
	}

	/** The route fails closed with a clear error when wp-native-auth is absent. */
	public function test_missing_ability_dependency_fails_closed() {
		wp_unregister_ability( 'wp-native/auth-sessions' );

		$result = extrachill_api_auth_sessions_list_handler( new WP_REST_Request( 'GET' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_not_found', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );

		// Re-register so tear_down's unregister loop does not warn on a
		// missing ability.
		$this->register_sessions_ability();
	}

	/**
	 * Build one deterministic session row fixture.
	 *
	 * @param string $device_id Device ID.
	 * @return array<string, mixed>
	 */
	private function session_row( $device_id ) {
		return array(
			'device_id'         => $device_id,
			'device_name'       => 'Test Device',
			'created_at'        => '2026-01-01T00:00:00+00:00',
			'last_used_at'      => '2026-01-02T00:00:00+00:00',
			'expires_at'        => '2026-02-01T00:00:00+00:00',
			'current'           => false,
			'oauth_client_id'   => null,
			'oauth_client_name' => null,
		);
	}

	/**
	 * Register the controlled wp-native/auth-sessions ability double.
	 *
	 * @param WP_Error|null $error Optional forced error result.
	 */
	private function register_sessions_ability( $error = null ) {
		$name = 'wp-native/auth-sessions';
		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			$name,
			array(
				'label'               => 'Test wp-native/auth-sessions',
				'description'         => 'Controlled double matching the documented auth.sessions output shape.',
				'category'            => 'extrachill-api-auth-sessions-tests',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( array $input ) use ( $test, $error ) {
					$test->list_inputs[] = $input;
					if ( $error instanceof WP_Error ) {
						return $error;
					}
					$user_id = get_current_user_id();
					return array( 'sessions' => $test->sessions_by_user[ $user_id ] ?? array() );
				},
			)
		);
		if ( ! in_array( $name, $this->registered_abilities, true ) ) {
			$this->registered_abilities[] = $name;
		}
	}

	/**
	 * Register the controlled wp-native/auth-revoke-session ability double.
	 */
	private function register_revoke_ability() {
		$name = 'wp-native/auth-revoke-session';
		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			$name,
			array(
				'label'               => 'Test wp-native/auth-revoke-session',
				'description'         => 'Controlled double matching the documented auth.revoke-session output shape.',
				'category'            => 'extrachill-api-auth-sessions-tests',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'device_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'device_id' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( array $input ) use ( $test ) {
					$test->revoke_inputs[] = $input;
					$user_id   = get_current_user_id();
					$device_id = $input['device_id'] ?? '';
					$sessions  = $test->sessions_by_user[ $user_id ] ?? array();
					$remaining = array_values(
						array_filter(
							$sessions,
							static fn( $row ) => $row['device_id'] !== $device_id
						)
					);
					$revoked                            = count( $remaining ) !== count( $sessions );
					$test->sessions_by_user[ $user_id ] = $remaining;
					return array( 'revoked' => $revoked );
				},
			)
		);
		$this->registered_abilities[] = $name;
	}
}
