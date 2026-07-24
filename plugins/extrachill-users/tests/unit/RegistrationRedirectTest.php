<?php
/**
 * Tests for progressive onboarding after registration.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify ordinary registrations resume their intent while /join stays gated.
 */
class Test_Registration_Redirect extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_extra_chill_test_hosts' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'allowed_redirect_hosts', array( $this, 'allow_extra_chill_test_hosts' ) );
		parent::tearDown();
	}

	public function allow_extra_chill_test_hosts( $hosts ): array {
		$hosts[] = 'events.extrachill.com';
		$hosts[] = 'artist.extrachill.com';
		return $hosts;
	}

	public function test_ordinary_google_registration_returns_to_requested_feature(): void {
		$calendar_url = 'https://events.extrachill.com/calendar/?date=2026-07-24';

		$this->assertSame( $calendar_url, ec_users_post_registration_redirect_url( false, false, $calendar_url ) );
	}

	public function test_ordinary_google_registration_rejects_external_return_url(): void {
		$redirect_url = ec_users_post_registration_redirect_url( false, false, 'https://attacker.example/calendar/' );
		$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url();

		$this->assertSame( $community_url, $redirect_url );
	}

	public function test_join_google_registration_still_requires_onboarding(): void {
		$redirect_url = ec_users_post_registration_redirect_url( true, false, 'https://artist.extrachill.com/login/' );
		$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url();

		$this->assertSame( untrailingslashit( $community_url ) . '/onboarding/', $redirect_url );
	}

	public function test_completed_join_user_returns_to_artist_flow(): void {
		$login_url    = 'https://artist.extrachill.com/login/';
		$redirect_url = ec_users_post_registration_redirect_url( true, true, $login_url );
		parse_str( (string) wp_parse_url( $redirect_url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( $login_url, $redirect_url );
		$this->assertSame( 'true', $query['from_join'] ?? '' );
	}

	public function test_new_account_redirect_carries_one_time_confirmation(): void {
		$calendar_url = 'https://events.extrachill.com/calendar/?date=2026-07-24';
		$redirect_url = ec_users_post_registration_redirect_url( false, false, $calendar_url, 'test-confirmation' );
		parse_str( (string) wp_parse_url( $redirect_url, PHP_URL_QUERY ), $query );

		$this->assertSame( '2026-07-24', $query['date'] ?? '' );
		$this->assertSame( 'test-confirmation', $query[ EC_USERS_ACCOUNT_CREATED_PARAM ] ?? '' );
	}

	public function test_browser_registration_resumes_requested_feature(): void {
		$user_id      = self::factory()->user->create();
		$calendar_url = 'https://events.extrachill.com/calendar/';
		$redirect     = $this->capture_browser_registration_redirect( $user_id, $calendar_url );

		$this->assertStringStartsWith( $calendar_url, $redirect );
		$this->assertStringNotContainsString( '/onboarding/', $redirect );
		$this->assertStringContainsString( EC_USERS_ACCOUNT_CREATED_PARAM, $redirect );
	}

	public function test_join_browser_registration_still_requires_onboarding(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'onboarding_from_join', '1' );

		$redirect = $this->capture_browser_registration_redirect( $user_id, 'https://artist.extrachill.com/login/' );

		$this->assertStringContainsString( '/onboarding/', $redirect );
	}

	private function capture_browser_registration_redirect( int $user_id, string $success_redirect_url ): string {
		$redirect = new class( 'https://community.extrachill.com/register/' ) extends EC_Redirect_Handler {
			public string $captured_url = '';

			public function redirect_to( string $url ): void {
				$this->captured_url = $url;
			}
		};

		extrachill_auto_login_new_user( $user_id, $redirect, null, $success_redirect_url );

		return $redirect->captured_url;
	}
}
