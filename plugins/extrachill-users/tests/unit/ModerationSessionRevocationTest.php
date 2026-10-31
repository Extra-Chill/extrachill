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
	 * Guard against leaking storage-cutover overrides between tests.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['test_link_page_storage_blog_id'], $GLOBALS['test_link_page_post_type'] );
		parent::tear_down();
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
	 * Owned artist profiles and link pages are hidden across the network.
	 */
	public function test_moderation_hides_owned_artist_content_cross_site(): void {
		if ( ! function_exists( 'ec_get_blog_id' ) ) {
			$this->markTestSkipped( 'The canonical artist-site map is unavailable.' );
		}

		$user_id        = self::factory()->user->create();
		$artist_blog_id = (int) ec_get_blog_id( 'artist' );
		if ( $artist_blog_id <= 0 ) {
			$this->markTestSkipped( 'The canonical artist site is unavailable.' );
		}

		switch_to_blog( $artist_blog_id );
		register_post_type( 'artist_profile', array( 'public' => true ) );
		register_post_type( 'artist_link_page', array( 'public' => true ) );
		$artist_id = self::factory()->post->create( array( 'post_type' => 'artist_profile' ) );
		$link_id   = self::factory()->post->create( array( 'post_type' => 'artist_link_page' ) );
		update_post_meta( $link_id, '_associated_artist_profile_id', (string) $artist_id );
		restore_current_blog();
		update_user_meta( $user_id, '_artist_profile_ids', array( $artist_id ) );

		$result = extrachill_users_apply_moderation_action( $user_id, array( 'reason_key' => 'spam' ) );

		$this->assertNotWPError( $result );
		switch_to_blog( $artist_blog_id );
		$this->assertSame( 'draft', get_post_status( $artist_id ) );
		$this->assertSame( 'draft', get_post_status( $link_id ) );
		restore_current_blog();
	}

	/**
	 * Owned link pages are hidden through the storage helper when the Link
	 * Page storage cutover has moved them to a dedicated site under the
	 * `ec_link_page` post type, distinct from the artist blog.
	 */
	public function test_moderation_hides_owned_link_pages_from_dedicated_storage_site(): void {
		if ( ! function_exists( 'ec_get_blog_id' ) ) {
			$this->markTestSkipped( 'The canonical artist-site map is unavailable.' );
		}

		$this->install_link_page_storage_stubs();

		$user_id         = self::factory()->user->create();
		$artist_blog_id  = (int) ec_get_blog_id( 'artist' );
		$storage_blog_id = $this->get_dedicated_link_page_storage_blog();

		if ( $artist_blog_id <= 0 || null === $storage_blog_id ) {
			$this->markTestSkipped( 'The canonical artist site or a dedicated storage blog is unavailable.' );
		}

		switch_to_blog( $artist_blog_id );
		register_post_type( 'artist_profile', array( 'public' => true ) );
		$artist_id = self::factory()->post->create( array( 'post_type' => 'artist_profile' ) );
		restore_current_blog();

		switch_to_blog( $storage_blog_id );
		register_post_type( 'ec_link_page', array( 'public' => true ) );
		$link_id = self::factory()->post->create( array( 'post_type' => 'ec_link_page' ) );
		update_post_meta( $link_id, '_associated_artist_profile_id', (string) $artist_id );
		restore_current_blog();

		update_user_meta( $user_id, '_artist_profile_ids', array( $artist_id ) );

		$GLOBALS['test_link_page_storage_blog_id'] = $storage_blog_id;
		$GLOBALS['test_link_page_post_type']       = 'ec_link_page';

		$result = extrachill_users_apply_moderation_action( $user_id, array( 'reason_key' => 'spam' ) );

		$this->assertNotWPError( $result );

		switch_to_blog( $artist_blog_id );
		$this->assertSame( 'draft', get_post_status( $artist_id ), 'The artist profile was not hidden.' );
		restore_current_blog();

		switch_to_blog( $storage_blog_id );
		$this->assertSame( 'draft', get_post_status( $link_id ), 'The dedicated-site link page was not hidden.' );
		restore_current_blog();
	}

	/**
	 * Create (or reuse) a dedicated blog simulating post-cutover Link Page
	 * storage, distinct from the artist blog.
	 *
	 * @return int|null Blog ID, or null when multisite blog creation is
	 *                   unavailable.
	 */
	private function get_dedicated_link_page_storage_blog(): ?int {
		if ( ! is_multisite() || ! function_exists( 'wpmu_create_blog' ) ) {
			return null;
		}

		$domain   = 'linkpagestoragetest.example.org';
		$existing = get_sites(
			array(
				'domain' => $domain,
				'path'   => '/',
				'number' => 1,
				'fields' => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return (int) reset( $existing );
		}

		$blog_id = wpmu_create_blog( $domain, '/', 'Link Page Storage Test', 1, array( 'public' => 1 ), 1 );

		return is_wp_error( $blog_id ) ? null : (int) $blog_id;
	}

	/**
	 * Define the Link Page storage-helper functions that moderation guards
	 * on with function_exists(), for the (common) case where
	 * extrachill-link-pages is not loaded in this test process.
	 *
	 * With no $GLOBALS overrides set, these stubs reproduce today's
	 * gate-off behavior exactly (storage blog resolves to the artist blog,
	 * post type resolves to `artist_link_page`), so installing them never
	 * changes the outcome of the legacy-storage test above — the two tests
	 * are safe regardless of PHPUnit execution order.
	 */
	private function install_link_page_storage_stubs(): void {
		// PHP allows a function declaration inside a conditional to define
		// that function globally, exactly once, the first time this branch
		// executes — no eval() needed.
		if ( ! function_exists( 'ec_get_link_page_storage_blog_id' ) ) {
			function ec_get_link_page_storage_blog_id() {
				if ( isset( $GLOBALS['test_link_page_storage_blog_id'] ) ) {
					return (int) $GLOBALS['test_link_page_storage_blog_id'];
				}

				return function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
			}
		}

		if ( ! function_exists( 'ec_link_page_post_type' ) ) {
			function ec_link_page_post_type( $blog_id = null ) {
				unset( $blog_id );

				return $GLOBALS['test_link_page_post_type'] ?? 'artist_link_page';
			}
		}

		if ( ! function_exists( 'ec_with_link_page_storage_blog' ) ) {
			function ec_with_link_page_storage_blog( $callback ) {
				if ( ! is_callable( $callback ) ) {
					return new WP_Error( 'invalid_link_page_storage_callback', 'The Link Page storage callback is invalid.' );
				}

				$storage_blog_id = ec_get_link_page_storage_blog_id();
				if ( ! $storage_blog_id ) {
					return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
				}

				$entry_blog_id = get_current_blog_id();
				$switched      = $entry_blog_id !== $storage_blog_id;
				if ( $switched ) {
					switch_to_blog( $storage_blog_id );
				}

				try {
					return call_user_func( $callback, $storage_blog_id );
				} finally {
					if ( $switched ) {
						restore_current_blog();
					}
				}
			}
		}
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
