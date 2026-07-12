<?php
/**
 * Tests for the private default event location setting.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify account settings delegate canonical event location resolution.
 */
class Test_User_Settings_Default_Event_Location extends WP_UnitTestCase {

	/**
	 * Register a controllable canonical location Ability.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['ec_users_test_location_result'] = null;
		if ( ! wp_get_ability( 'extrachill/events-locations' ) ) {
			wp_register_ability(
				'extrachill/events-locations',
				array(
					'label'               => 'Test Event Locations',
					'description'         => 'Test resolver.',
					'category'            => 'extrachill-users',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => static function ( $input ) {
						$GLOBALS['ec_users_test_location_input'] = $input;
						return $GLOBALS['ec_users_test_location_result'];
					},
				)
			);
		}
	}

	/**
	 * A canonical result is returned unchanged and an empty string clears it.
	 */
	public function test_update_delegates_resolution_and_clears_default_location(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$location                                 = array(
			'term_id'     => 1618,
			'name'        => 'Charleston',
			'slug'        => 'charleston',
			'archive_url' => 'https://events.example/location/charleston/',
			'coordinates' => array(
				'lat' => 32.7765,
				'lon' => -79.9311,
			),
			'hierarchy'   => array(
				'region' => 'United States',
				'state'  => 'South Carolina',
				'label'  => 'Charleston, South Carolina',
			),
		);
		$GLOBALS['ec_users_test_location_result'] = array(
			'locations' => array(),
			'location'  => $location,
		);

		$updated = extrachill_users_ability_update_settings( array( 'default_event_location' => 'Charleston' ) );

		$this->assertSame(
			array(
				'mode' => 'resolve',
				'slug' => 'charleston',
			),
			$GLOBALS['ec_users_test_location_input']
		);
		$this->assertSame( $location, $updated['default_event_location'] );
		$this->assertSame( 'charleston', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );

		$cleared = extrachill_users_ability_update_settings( array( 'default_event_location' => '' ) );
		$this->assertNull( $cleared['default_event_location'] );
		$this->assertSame( '', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );
	}

	/**
	 * Validation errors from the Events domain prevent persistence.
	 */
	public function test_update_propagates_location_validation_errors(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$GLOBALS['ec_users_test_location_result'] = new WP_Error( 'location_not_found', 'Not found.', array( 'status' => 404 ) );

		$result = extrachill_users_ability_update_settings( array( 'default_event_location' => 'nowhere' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'location_not_found', $result->get_error_code() );
		$this->assertSame( '', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );
	}

	/**
	 * Stored settings fail open when canonical resolution fails.
	 */
	public function test_read_returns_null_when_resolution_fails(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, 'charleston' );
		$GLOBALS['ec_users_test_location_result'] = new WP_Error( 'events_site_unavailable', 'Unavailable.', array( 'status' => 500 ) );

		$settings = extrachill_users_ability_get_settings();

		$this->assertNull( $settings['default_event_location'] );
		$this->assertSame( 'charleston', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );
	}

	/**
	 * A missing Events Ability fails open for reads and clearly rejects writes.
	 */
	public function test_missing_ability_returns_null_on_read_and_error_on_update(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, 'charleston' );
		wp_unregister_ability( 'extrachill/events-locations' );

		$settings = extrachill_users_ability_get_settings();
		$updated  = extrachill_users_ability_update_settings( array( 'default_event_location' => 'savannah' ) );

		$this->assertNull( $settings['default_event_location'] );
		$this->assertWPError( $updated );
		$this->assertSame( 'events_locations_unavailable', $updated->get_error_code() );
		$this->assertSame( 'charleston', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );
	}
}
