<?php
/**
 * REST transport tests for Users-owned concert privacy settings.
 *
 * @package ExtraChill\API\Tests
 */

/**
 * Exercises schema, authentication, and canonical ability forwarding.
 */
class User_Settings_Privacy_RoutesTest extends WP_UnitTestCase {

	/**
	 * Canonical settings state by user ID.
	 *
	 * @var array<int, array<string, string>>
	 */
	private $settings_by_user = array();

	/**
	 * Inputs received by the get-settings ability.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $get_inputs = array();

	/**
	 * Inputs received by the update-settings ability.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $update_inputs = array();

	/**
	 * Whether the test registered its ability category.
	 *
	 * @var bool
	 */
	private $registered_category = false;

	/**
	 * Ability names registered by the test.
	 *
	 * @var string[]
	 */
	private $registered_abilities = array();

	/**
	 * Register controlled canonical settings abilities.
	 */
	public function set_up() {
		parent::set_up();

		$category_registry = WP_Ability_Categories_Registry::get_instance();
		if ( ! wp_has_ability_category( 'extrachill-api-settings-tests' ) ) {
			$category_registry->register(
				'extrachill-api-settings-tests',
				array(
					'label'       => 'Extra Chill API Settings Tests',
					'description' => 'Controlled abilities for settings adapter tests.',
				)
			);
			$this->registered_category = true;
		}

		$properties = array(
			'concert_history_visibility'  => array(
				'type' => 'string',
				'enum' => array( 'public', 'private' ),
			),
			'event_attendance_visibility' => array(
				'type' => 'string',
				'enum' => array( 'public', 'private' ),
			),
		);
		$this->register_test_ability( 'extrachill/get-user-settings', array(), array( $this, 'execute_get_settings' ) );
		$this->register_test_ability( 'extrachill/update-user-settings', $properties, array( $this, 'execute_update_settings' ) );

		do_action( 'rest_api_init' );
	}

	/**
	 * Remove test registry entries and authentication state.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		foreach ( $this->registered_abilities as $ability_name ) {
			wp_unregister_ability( $ability_name );
		}
		if ( $this->registered_category ) {
			wp_unregister_ability_category( 'extrachill-api-settings-tests' );
		}

		parent::tear_down();
	}

	/**
	 * Both settings round-trip through POST and GET route dispatch.
	 */
	public function test_privacy_settings_round_trip() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$update = $this->dispatch(
			'POST',
			array(
				'concert_history_visibility'  => 'private',
				'event_attendance_visibility' => 'private',
			)
		);
		$this->assertSame( 200, $update->get_status() );
		$this->assertSame( $user_id, $update->get_data()['user_id'] );
		$this->assertSame( 'private', $update->get_data()['concert_history_visibility'] );
		$this->assertSame( 'private', $update->get_data()['event_attendance_visibility'] );

		$read = $this->dispatch( 'GET', array() );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( $update->get_data(), $read->get_data() );
	}

	/**
	 * REST schema rejects invalid visibility before canonical execution.
	 *
	 * @dataProvider invalid_visibility_provider
	 * @param string $field Settings field.
	 */
	public function test_invalid_visibility_is_rejected( $field ) {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$response = $this->dispatch( 'POST', array( $field => 'friends' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 'public', $this->get_user_settings( $user_id )[ $field ] );
		$this->assertEmpty( $this->update_inputs );
	}

	/**
	 * A supplied user ID cannot redirect an update to another account.
	 */
	public function test_supplied_user_id_cannot_modify_another_account() {
		$actor_id  = self::factory()->user->create();
		$target_id = self::factory()->user->create();
		wp_set_current_user( $actor_id );

		$response = $this->dispatch(
			'POST',
			array(
				'user_id'                    => $target_id,
				'concert_history_visibility' => 'private',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $actor_id, $response->get_data()['user_id'] );
		$this->assertSame(
			array( 'concert_history_visibility' => 'private' ),
			end( $this->update_inputs )
		);
		$this->assertArrayNotHasKey( 'user_id', end( $this->update_inputs ) );
		$this->assertSame( 'private', $this->get_user_settings( $actor_id )['concert_history_visibility'] );
		$this->assertSame( 'public', $this->get_user_settings( $target_id )['concert_history_visibility'] );
	}

	/**
	 * A supplied user ID cannot redirect a settings read to another account.
	 */
	public function test_supplied_user_id_cannot_read_another_account() {
		$private_user_id = self::factory()->user->create();
		$actor_id        = self::factory()->user->create();

		$this->settings_by_user[ $private_user_id ] = array(
			'concert_history_visibility'  => 'private',
			'event_attendance_visibility' => 'private',
		);
		wp_set_current_user( $actor_id );

		$response = $this->dispatch( 'GET', array( 'user_id' => $private_user_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $actor_id, $response->get_data()['user_id'] );
		$this->assertSame( 'public', $response->get_data()['concert_history_visibility'] );
		$this->assertSame( 'public', $response->get_data()['event_attendance_visibility'] );
		$this->assertSame( array(), end( $this->get_inputs ) );
	}

	/**
	 * Visibility fields rejected by schema.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_visibility_provider() {
		return array(
			'concert history'  => array( 'concert_history_visibility' ),
			'event attendance' => array( 'event_attendance_visibility' ),
		);
	}

	/**
	 * Logged-out callers cannot read or update account settings.
	 *
	 * @dataProvider settings_method_provider
	 * @param string $method HTTP method.
	 */
	public function test_settings_require_authentication( $method ) {
		$response = $this->dispatch( $method, array( 'concert_history_visibility' => 'private' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * Settings route methods.
	 *
	 * @return array<string, array{string}>
	 */
	public function settings_method_provider() {
		return array(
			'get'  => array( 'GET' ),
			'post' => array( 'POST' ),
		);
	}

	/**
	 * Return controlled settings.
	 *
	 * @param array $input Ability input.
	 * @return array<string, int|string>
	 */
	public function execute_get_settings( array $input ) {
		$this->get_inputs[] = $input;

		return array_merge(
			array( 'user_id' => get_current_user_id() ),
			$this->get_user_settings( get_current_user_id() )
		);
	}

	/**
	 * Update controlled settings and return their canonical representation.
	 *
	 * @param array $input Ability input.
	 * @return array<string, int|string>
	 */
	public function execute_update_settings( array $input ) {
		$this->update_inputs[] = $input;
		$user_id               = get_current_user_id();
		$settings              = $this->get_user_settings( $user_id );
		foreach ( array_keys( $settings ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$settings[ $field ] = $input[ $field ];
			}
		}
		$this->settings_by_user[ $user_id ] = $settings;

		return array_merge( array( 'user_id' => $user_id ), $settings );
	}

	/**
	 * Get deterministic canonical settings for a test user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, string>
	 */
	private function get_user_settings( $user_id ) {
		if ( ! isset( $this->settings_by_user[ $user_id ] ) ) {
			$this->settings_by_user[ $user_id ] = array(
				'concert_history_visibility'  => 'public',
				'event_attendance_visibility' => 'public',
			);
		}

		return $this->settings_by_user[ $user_id ];
	}

	/**
	 * Register a controlled ability directly in the initialized registry.
	 *
	 * @param string   $name Ability name.
	 * @param array    $properties Input properties.
	 * @param callable $callback Execute callback.
	 */
	private function register_test_ability( $name, array $properties, $callback ) {
		if ( wp_has_ability( $name ) ) {
			$this->fail( 'Unexpected ability dependency already registered: ' . $name );
		}

		$ability = WP_Abilities_Registry::get_instance()->register(
			$name,
			array(
				'label'               => 'Test ' . $name,
				'description'         => 'Controlled ability for API settings transport tests.',
				'category'            => 'extrachill-api-settings-tests',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
				),
				'execute_callback'    => $callback,
				'permission_callback' => '__return_true',
			)
		);

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->registered_abilities[] = $name;
	}

	/**
	 * Dispatch a settings request through the actual REST namespace.
	 *
	 * @param string $method HTTP method.
	 * @param array  $params Request body parameters.
	 * @return WP_REST_Response
	 */
	private function dispatch( $method, array $params ) {
		$request = new WP_REST_Request( $method, '/extrachill/v1/users/me/settings' );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_do_request( $request );
	}
}
