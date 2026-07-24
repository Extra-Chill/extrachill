<?php
/**
 * Tests for moderation session revocation.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify blocking moderation actions invalidate existing sessions.
 */
class Test_Moderation_Session_Revocation extends WP_UnitTestCase {

	/**
	 * Ensure the optional native-auth integration can be exercised safely.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( function_exists( 'wp_native_auth_install_refresh_tokens_table' ) ) {
			wp_native_auth_install_refresh_tokens_table();
		}
	}

	/**
	 * A ban destroys every existing WordPress session for the user.
	 */
	public function test_ban_revokes_existing_sessions(): void {
		$user_id  = self::factory()->user->create();
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->create( time() + HOUR_IN_SECONDS );

		$this->assertCount( 1, $sessions->get_all() );

		$result = extrachill_users_apply_moderation_action(
			$user_id,
			array(
				'reason_key' => 'other',
				'source'     => 'phpunit',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'banned', $result['state'] );
		$this->assertSame( array(), WP_Session_Tokens::get_instance( $user_id )->get_all() );
	}

	/**
	 * Native refresh tokens issued before moderation stay invalid after unban.
	 */
	public function test_ban_revokes_native_refresh_sessions_for_only_the_moderated_user(): void {
		if ( ! function_exists( 'wp_native_auth_revoke_user_refresh_tokens' ) ) {
			$this->markTestSkipped( 'wp-native-auth is unavailable.' );
		}

		$user_id       = self::factory()->user->create();
		$other_user_id = self::factory()->user->create();
		$device_id     = '11111111-1111-4111-8111-111111111111';
		$other_device  = '22222222-2222-4222-8222-222222222222';
		$token         = wp_native_auth_issue_refresh_token( $user_id, $device_id, 'Moderated Device' );
		$other_token   = wp_native_auth_issue_refresh_token( $other_user_id, $other_device, 'Other Device' );

		$result = extrachill_users_apply_moderation_action(
			$user_id,
			array(
				'reason_key' => 'other',
				'source'     => 'phpunit',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertNotWPError( extrachill_users_clear_moderation_action( $user_id ) );
		$this->assert_refresh_rejected( $token['token'], $device_id );
		$this->assertNotWPError( wp_native_auth_refresh_tokens( $other_token['token'], $other_device ) );
	}

	/**
	 * Reapplying moderation safely repeats native revocation.
	 */
	public function test_repeated_native_session_revocation_is_safe(): void {
		if ( ! function_exists( 'wp_native_auth_revoke_user_refresh_tokens' ) ) {
			$this->markTestSkipped( 'wp-native-auth is unavailable.' );
		}

		$user_id = self::factory()->user->create();
		wp_native_auth_issue_refresh_token( $user_id, '33333333-3333-4333-8333-333333333333', 'Test Device' );

		$this->assertNotWPError( extrachill_users_apply_moderation_action( $user_id, array( 'reason_key' => 'other' ) ) );
		$this->assertNotWPError( extrachill_users_apply_moderation_action( $user_id, array( 'reason_key' => 'other' ) ) );
	}

	/**
	 * Native storage failures are returned instead of reporting success.
	 */
	public function test_native_session_revocation_failure_is_returned(): void {
		global $wpdb;

		if ( ! function_exists( 'wp_native_auth_revoke_user_refresh_tokens' ) ) {
			$this->markTestSkipped( 'wp-native-auth is unavailable.' );
		}

		$user_id    = self::factory()->user->create();
		$table_name = wp_native_auth_refresh_tokens_table_name();
		$fail_query = static function ( string $query ) use ( $table_name ): string {
			if ( str_contains( $query, "UPDATE {$table_name} SET revoked_at" ) ) {
				return 'INVALID MODERATION REVOCATION QUERY';
			}

			return $query;
		};

		wp_native_auth_issue_refresh_token( $user_id, '44444444-4444-4444-8444-444444444444', 'Test Device' );
		add_filter( 'query', $fail_query );
		$previous_suppression = $wpdb->suppress_errors( true );

		$result = extrachill_users_apply_moderation_action( $user_id, array( 'reason_key' => 'other' ) );

		$wpdb->suppress_errors( $previous_suppression );
		remove_filter( 'query', $fail_query );

		$this->assertWPError( $result );
		$this->assertSame( 'refresh_session_revocation_failed', $result->get_error_code() );
		$this->assertTrue( extrachill_users_is_blocked( $user_id ) );
	}

	/**
	 * Moderation remains available when the optional native plugin is absent.
	 */
	public function test_moderation_without_native_auth_remains_supported(): void {
		if ( function_exists( 'wp_native_auth_revoke_user_refresh_tokens' ) ) {
			$this->markTestSkipped( 'wp-native-auth is available.' );
		}

		$user_id = self::factory()->user->create();
		$result  = extrachill_users_apply_moderation_action( $user_id, array( 'reason_key' => 'other' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'banned', $result['state'] );
	}

	/**
	 * Assert that a refresh token can no longer rotate.
	 *
	 * @param string $token     Refresh token.
	 * @param string $device_id Device UUID.
	 */
	private function assert_refresh_rejected( string $token, string $device_id ): void {
		delete_transient( 'wp_native_auth_refresh_' . md5( $device_id ) );
		$result = wp_native_auth_refresh_tokens( $token, $device_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_refresh_token', $result->get_error_code() );
	}
}
