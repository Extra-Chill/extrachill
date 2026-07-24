<?php
/**
 * Tests for progressive onboarding after Google authentication.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify ordinary registrations resume their intent while /join stays gated.
 */
class Test_Google_Registration_Redirect extends WP_UnitTestCase {

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

		$this->assertSame( $calendar_url, ec_google_post_auth_redirect_url( false, false, $calendar_url ) );
	}

	public function test_ordinary_google_registration_rejects_external_return_url(): void {
		$redirect_url = ec_google_post_auth_redirect_url( false, false, 'https://attacker.example/calendar/' );
		$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url();

		$this->assertSame( $community_url, $redirect_url );
	}

	public function test_join_google_registration_still_requires_onboarding(): void {
		$redirect_url = ec_google_post_auth_redirect_url( true, false, 'https://artist.extrachill.com/login/' );
		$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url();

		$this->assertSame( untrailingslashit( $community_url ) . '/onboarding/', $redirect_url );
	}

	public function test_completed_join_user_returns_to_artist_flow(): void {
		$login_url    = 'https://artist.extrachill.com/login/';
		$redirect_url = ec_google_post_auth_redirect_url( true, true, $login_url );
		parse_str( (string) wp_parse_url( $redirect_url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( $login_url, $redirect_url );
		$this->assertSame( 'true', $query['from_join'] ?? '' );
	}

	public function test_new_account_redirect_carries_one_time_confirmation(): void {
		$calendar_url = 'https://events.extrachill.com/calendar/?date=2026-07-24';
		$redirect_url = ec_google_post_auth_redirect_url( false, false, $calendar_url, 'test-confirmation' );
		parse_str( (string) wp_parse_url( $redirect_url, PHP_URL_QUERY ), $query );

		$this->assertSame( '2026-07-24', $query['date'] ?? '' );
		$this->assertSame( 'test-confirmation', $query[ EC_USERS_GOOGLE_ACCOUNT_CREATED_PARAM ] ?? '' );
	}
}
