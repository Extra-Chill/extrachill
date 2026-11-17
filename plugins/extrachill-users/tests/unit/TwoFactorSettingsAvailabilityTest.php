<?php
/**
 * Settings → Security shows two-step sign-in only when Two Factor's
 * management routes (WordPress/two-factor#935 and #1045) are registered.
 *
 * Runs against the Two Factor double, which declares the #935/#1045 route
 * registration methods but registers no routes itself.
 */

class Test_Two_Factor_Settings_Availability extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/support/two-factor-core-double.php';

		if ( ! defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real Two Factor plugin is loaded.' );
		}

		$this->reset_rest_server();
	}

	protected function tearDown(): void {
		$this->reset_rest_server();
		parent::tearDown();
	}

	private function reset_rest_server(): void {
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Register Two Factor-shaped routes on the next REST server boot.
	 *
	 * @param list<string> $skip Route patterns to leave out.
	 */
	private function register_routes( array $skip = array() ): void {
		add_action(
			'rest_api_init',
			static function () use ( $skip ) {
				foreach ( extrachill_users_two_factor_settings_routes() as $route ) {
					if ( in_array( $route, $skip, true ) ) {
						continue;
					}
					register_rest_route(
						'two-factor/1.0',
						substr( $route, strlen( '/two-factor/1.0' ) ),
						array(
							'methods'             => 'POST',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
						)
					);
				}
			}
		);
	}

	public function test_lists_the_upstream_management_routes(): void {
		$this->assertSame(
			array(
				'/two-factor/1.0/users/(?P<user_id>[\d]+)/providers',
				'/two-factor/1.0/totp/enrollment',
				'/two-factor/1.0/totp',
				'/two-factor/1.0/generate-backup-codes',
				'/two-factor/1.0/revalidate',
			),
			extrachill_users_two_factor_settings_routes()
		);
	}

	public function test_unavailable_when_routes_are_not_registered(): void {
		$this->assertTrue( extrachill_users_two_factor_settings_api_present() );
		$this->assertFalse( extrachill_users_two_factor_settings_available() );
	}

	public function test_available_when_every_route_is_registered(): void {
		$this->register_routes();

		$this->assertTrue( extrachill_users_two_factor_settings_routes_registered() );
		$this->assertTrue( extrachill_users_two_factor_settings_available() );
	}

	/**
	 * Two Factor 0.16.0 registers only the TOTP and backup-code routes.
	 */
	public function test_unavailable_on_released_two_factor_routes(): void {
		$this->register_routes(
			array(
				'/two-factor/1.0/users/(?P<user_id>[\d]+)/providers',
				'/two-factor/1.0/totp/enrollment',
				'/two-factor/1.0/revalidate',
			)
		);

		$this->assertFalse( extrachill_users_two_factor_settings_available() );
	}

	/**
	 * A filtered-out authenticator provider leaves its enrollment route unregistered.
	 */
	public function test_unavailable_when_one_route_is_missing(): void {
		$this->register_routes( array( '/two-factor/1.0/totp/enrollment' ) );

		$this->assertFalse( extrachill_users_two_factor_settings_available() );
	}

	public function test_filter_can_turn_the_section_off(): void {
		$this->register_routes();
		add_filter( 'extrachill_users_two_factor_settings_available', '__return_false' );

		$this->assertFalse( extrachill_users_two_factor_settings_available() );
	}

	public function test_security_settings_url_points_at_the_security_tab(): void {
		$url = extrachill_users_security_settings_url();

		$this->assertStringEndsWith( '/settings#tab-security', $url );
		$this->assertStringNotContainsString( 'wp-admin', $url );
		$this->assertStringNotContainsString( 'profile.php', $url );
	}
}
