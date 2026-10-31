<?php
/**
 * Tests for self-service account deletion.
 *
 * @package ExtraChill\Users
 */

class Test_Delete_My_Account_Ability extends WP_UnitTestCase {

	/**
	 * Captured `extrachill_user_self_deleted` calls.
	 *
	 * @var array
	 */
	private $fired = array();

	/**
	 * Captured outbound HTTP requests.
	 *
	 * @var array
	 */
	private $requests = array();

	public function set_up(): void {
		parent::set_up();
		$this->fired    = array();
		$this->requests = array();

		if ( function_exists( 'wp_native_auth_install_refresh_tokens_table' ) ) {
			wp_native_auth_install_refresh_tokens_table();
		}

		add_filter( 'pre_http_request', array( $this, 'capture_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_all_filters( 'extrachill_users_apple_revocation_configured' );
		remove_all_actions( 'extrachill_user_self_deleted' );
		remove_filter( 'pre_http_request', array( $this, 'capture_http_request' ), 10 );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Block and record outbound HTTP.
	 *
	 * @param mixed  $pre  Pre-empted response.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array
	 */
	public function capture_http_request( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function create_current_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	private function confirm(): array {
		return array( 'confirm' => 'DELETE' );
	}

	public function test_ability_is_registered_for_rest(): void {
		$ability = wp_get_ability( 'extrachill/delete-my-account' );

		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
	}

	public function test_unauthenticated_request_is_refused(): void {
		wp_set_current_user( 0 );

		$this->assertNotTrue( wp_get_ability( 'extrachill/delete-my-account' )->check_permissions( $this->confirm() ) );

		$result = extrachill_users_ability_delete_my_account( $this->confirm() );
		$this->assertWPError( $result );
		$this->assertSame( 'not_authenticated', $result->get_error_code() );
	}

	public function test_confirm_guard_requires_exact_phrase(): void {
		$user_id = $this->create_current_user();

		foreach ( array( array(), array( 'confirm' => 'delete' ), array( 'confirm' => 'DELETE ' ), array( 'confirm' => true ) ) as $input ) {
			$result = extrachill_users_ability_delete_my_account( $input );
			$this->assertWPError( $result );
			$this->assertSame( 'confirmation_required', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}

		$this->assertInstanceOf( WP_User::class, get_userdata( $user_id ) );
		$this->assertSame( array(), $this->fired );
	}

	public function test_administrator_on_current_site_is_refused(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assert_refused( $user_id );
	}

	public function test_administrator_on_another_site_is_refused(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Cross-site administrator refusal requires multisite.' );
		}

		$user_id = $this->create_current_user();
		$blog_id = self::factory()->blog->create();
		add_user_to_blog( $blog_id, $user_id, 'administrator' );

		$this->assert_refused( $user_id );
	}

	public function test_team_member_is_refused(): void {
		global $wpdb;

		$user_id = $this->create_current_user();
		update_user_meta( $user_id, $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities', array( 'extra_chill_team' => true ) );
		clean_user_cache( $user_id );

		$this->assert_refused( $user_id );
	}

	public function test_super_admin_is_refused(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Super admins require multisite.' );
		}

		$user_id = $this->create_current_user();
		grant_super_admin( $user_id );

		try {
			$this->assert_refused( $user_id );
		} finally {
			revoke_super_admin( $user_id );
		}
	}

	public function test_self_deletion_removes_user_network_wide(): void {
		global $wpdb;

		$user_id = $this->create_current_user();
		$other   = self::factory()->user->create();
		update_user_meta( $user_id, 'extrachill_test_meta', 'value' );

		$post_blog_id = get_current_blog_id();
		if ( is_multisite() ) {
			$post_blog_id = self::factory()->blog->create();
			add_user_to_blog( $post_blog_id, $user_id, 'subscriber' );
		}

		switch_to_blog( $post_blog_id );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		restore_current_blog();

		$result = extrachill_users_ability_delete_my_account(
			array(
				'confirm' => 'DELETE',
				'user_id' => $other,
			)
		);

		$this->assertSame( array( 'deleted' => true ), $result );
		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID = %d", $user_id ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $user_id ) ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertInstanceOf( WP_User::class, get_userdata( $other ), 'Injected user_id must be ignored.' );

		switch_to_blog( $post_blog_id );
		$status = get_post_status( $post_id );
		restore_current_blog();
		$this->assertContains( $status, array( false, 'trash' ), 'Authored posts are removed by core, not reassigned.' );
	}

	public function test_ability_execute_path_deletes_current_user(): void {
		$user_id = $this->create_current_user();

		$result = wp_get_ability( 'extrachill/delete-my-account' )->execute( $this->confirm() );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertFalse( get_userdata( $user_id ) );
	}

	public function test_sessions_destroyed_and_action_fired_before_deletion(): void {
		$user_id  = $this->create_current_user();
		$email    = get_userdata( $user_id )->user_email;
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->create( time() + HOUR_IN_SECONDS );
		$sessions->create( time() + HOUR_IN_SECONDS );
		$this->assertCount( 2, $sessions->get_all() );

		$native = function_exists( 'wp_native_auth_issue_refresh_token' ) && function_exists( 'wp_native_auth_list_user_sessions' );
		if ( $native ) {
			wp_native_auth_issue_refresh_token( $user_id, wp_generate_uuid4(), 'phpunit' );
			$this->assertNotEmpty( wp_native_auth_list_user_sessions( $user_id ) );
		}

		add_action(
			'extrachill_user_self_deleted',
			function ( $id, $mail ) use ( $native ) {
				$this->fired[] = array(
					'user_id'         => $id,
					'email'           => $mail,
					'user_exists'     => false !== get_userdata( $id ),
					'sessions'        => WP_Session_Tokens::get_instance( $id )->get_all(),
					'native_sessions' => $native ? wp_native_auth_list_user_sessions( $id ) : array(),
				);
			},
			10,
			2
		);

		$result = extrachill_users_ability_delete_my_account( $this->confirm() );

		$this->assertSame( array( 'deleted' => true ), $result );
		$this->assertCount( 1, $this->fired );
		$this->assertSame( $user_id, $this->fired[0]['user_id'] );
		$this->assertSame( $email, $this->fired[0]['email'] );
		$this->assertTrue( $this->fired[0]['user_exists'], 'Action fires before the user row is removed.' );
		$this->assertSame( array(), $this->fired[0]['sessions'] );
		$this->assertSame( array(), $this->fired[0]['native_sessions'] );
	}

	public function test_apple_revocation_skipped_when_not_configured(): void {
		add_filter( 'extrachill_users_apple_revocation_configured', '__return_false' );

		$user_id = $this->create_current_user();
		update_user_meta( $user_id, 'apple_user_id', '001234.abcdef.0001' );
		update_user_meta( $user_id, 'apple_refresh_token', 'r.refresh-token' );

		$this->assertSame( 'not_configured', extrachill_users_revoke_apple_tokens_for_user( $user_id ) );

		$result = extrachill_users_ability_delete_my_account( $this->confirm() );

		$this->assertSame( array( 'deleted' => true ), $result );
		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertSame( array(), $this->apple_requests() );
	}

	public function test_apple_revocation_skipped_without_stored_refresh_token(): void {
		add_filter( 'extrachill_users_apple_revocation_configured', '__return_true' );

		$user_id = $this->create_current_user();
		update_user_meta( $user_id, 'apple_user_id', '001234.abcdef.0001' );

		$this->assertSame( 'no_refresh_token', extrachill_users_revoke_apple_tokens_for_user( $user_id ) );
		$this->assertSame( array(), $this->apple_requests() );
	}

	public function test_apple_revocation_posts_signed_client_secret(): void {
		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $key ) {
			$this->markTestSkipped( 'OpenSSL EC key generation is unavailable.' );
		}
		openssl_pkey_export( $key, $pem );

		update_site_option( 'extrachill_apple_client_id', 'com.extrachill.app' );
		update_site_option( 'extrachill_apple_team_id', 'TEAMID1234' );
		update_site_option( 'extrachill_apple_key_id', 'KEYID12345' );
		update_site_option( 'extrachill_apple_private_key', $pem );
		add_filter( 'extrachill_users_apple_revocation_configured', '__return_true' );

		$user_id = $this->create_current_user();
		update_user_meta( $user_id, 'apple_user_id', '001234.abcdef.0001' );
		update_user_meta( $user_id, 'apple_refresh_token', 'r.refresh-token' );

		$result = extrachill_users_ability_delete_my_account( $this->confirm() );
		$this->assertSame( array( 'deleted' => true ), $result );

		$requests = $this->apple_requests();
		$this->assertCount( 1, $requests );
		$body = $requests[0]['args']['body'];
		$this->assertSame( 'com.extrachill.app', $body['client_id'] );
		$this->assertSame( 'r.refresh-token', $body['token'] );
		$this->assertSame( 'refresh_token', $body['token_type_hint'] );

		$parts = explode( '.', $body['client_secret'] );
		$this->assertCount( 3, $parts );
		$decode  = static function ( $segment ) {
			return base64_decode( strtr( $segment, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $segment ) % 4 ) % 4 ) );
		};
		$header  = json_decode( $decode( $parts[0] ), true );
		$payload = json_decode( $decode( $parts[1] ), true );
		$this->assertSame( array( 'alg' => 'ES256', 'kid' => 'KEYID12345' ), $header );
		$this->assertSame( 'TEAMID1234', $payload['iss'] );
		$this->assertSame( 'https://appleid.apple.com', $payload['aud'] );
		$this->assertSame( 'com.extrachill.app', $payload['sub'] );

		// Verify the raw R||S signature against the public key.
		$raw = $decode( $parts[2] );
		$this->assertSame( 64, strlen( $raw ) );
		$public = openssl_pkey_get_details( $key )['key'];
		$this->assertSame( 1, openssl_verify( $parts[0] . '.' . $parts[1], $this->raw_to_der( $raw ), $public, OPENSSL_ALGO_SHA256 ) );
	}

	public function test_apple_failure_does_not_block_deletion(): void {
		add_filter( 'extrachill_users_apple_revocation_configured', '__return_true' );
		update_site_option( 'extrachill_apple_private_key', 'not-a-key' );
		update_site_option( 'extrachill_apple_client_id', 'com.extrachill.app' );
		update_site_option( 'extrachill_apple_team_id', 'TEAMID1234' );
		update_site_option( 'extrachill_apple_key_id', 'KEYID12345' );

		$user_id = $this->create_current_user();
		update_user_meta( $user_id, 'apple_user_id', '001234.abcdef.0001' );
		update_user_meta( $user_id, 'apple_refresh_token', 'r.refresh-token' );

		$result = extrachill_users_ability_delete_my_account( $this->confirm() );

		$this->assertSame( array( 'deleted' => true ), $result );
		$this->assertFalse( get_userdata( $user_id ) );
	}

	/**
	 * Assert the ability refuses the user without deleting anything.
	 *
	 * @param int $user_id User ID.
	 */
	private function assert_refused( int $user_id ): void {
		$permission = wp_get_ability( 'extrachill/delete-my-account' )->check_permissions( $this->confirm() );
		$this->assertWPError( $permission );
		$this->assertSame( 403, $permission->get_error_data()['status'] );

		$result = extrachill_users_ability_delete_my_account( $this->confirm() );
		$this->assertWPError( $result );
		$this->assertSame( 'account_deletion_refused', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertInstanceOf( WP_User::class, get_userdata( $user_id ) );
	}

	private function apple_requests(): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) {
					return 0 === strpos( $request['url'], 'https://appleid.apple.com/' );
				}
			)
		);
	}

	/**
	 * Convert a raw 64-byte P-256 signature back to DER for openssl_verify().
	 *
	 * @param string $raw Raw R||S.
	 * @return string
	 */
	private function raw_to_der( string $raw ): string {
		$encode = static function ( $int ) {
			$int = ltrim( $int, "\x00" );
			if ( '' === $int || ord( $int[0] ) > 0x7f ) {
				$int = "\x00" . $int;
			}
			return "\x02" . chr( strlen( $int ) ) . $int;
		};
		$seq    = $encode( substr( $raw, 0, 32 ) ) . $encode( substr( $raw, 32, 32 ) );

		return "\x30" . chr( strlen( $seq ) ) . $seq;
	}
}
