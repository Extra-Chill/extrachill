<?php
/** Old invitation payloads must not participate in registration. */
class Test_Artist_Invitation_Registration extends WP_UnitTestCase {
	protected function tearDown(): void {
		remove_all_filters( 'extrachill_users_registration_turnstile_verifier' );
		remove_all_filters( 'extrachill_users_registration_admitter' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Registration joins users to the community site under its fixed blog ID.
	 * The test network may not have that site, so provision it inside the
	 * test transaction when it is missing.
	 */
	private function ensure_community_site(): void {
		global $wpdb;
		$blog_id = (int) ec_get_blog_id( 'community' );
		if ( $blog_id <= 0 || get_site( $blog_id ) ) {
			return;
		}
		$network = get_network();
		$now     = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->blogs,
			array(
				'blog_id'      => $blog_id,
				'site_id'      => (int) $network->id,
				'domain'       => $network->domain,
				'path'         => '/community-fixture/',
				'registered'   => $now,
				'last_updated' => $now,
			)
		);
		clean_blog_cache( $blog_id );
		$initialized = get_site( $blog_id ) ? wp_initialize_site( $blog_id, array( 'title' => 'Community' ) ) : false;
		if ( true !== $initialized && ! get_blog_option( $blog_id, 'default_role' ) ) {
			$this->markTestSkipped( 'This test runtime cannot provision the community site registration joins users to.' );
		}
	}

	public function test_old_invite_payload_is_silently_ignored(): void {
		if ( ! function_exists( 'wp_native_auth_generate_access_token' ) ) {
			$this->markTestSkipped( 'wp-native-auth is unavailable; registration cannot mint tokens.' );
		}
		$this->ensure_community_site();
		// Both filters return the callable to run, not its result.
		add_filter( 'extrachill_users_registration_turnstile_verifier', static fn() => '__return_true' );
		add_filter( 'extrachill_users_registration_admitter', static fn() => '__return_true' );
		$requests = 0;
		add_filter( 'pre_http_request', static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		} );
		$email = 'ignored-invite@example.com';
		$result = extrachill_users_register_with_tokens( array(
			'email'            => $email,
			'password'         => 'secure-password',
			'password_confirm' => 'secure-password',
			'device_id'        => '123e4567-e89b-42d3-a456-426614174000',
			'invite_token'     => 'obsolete-token',
			'invite_artist_id' => 20,
		) );
		$this->assertIsArray( $result );
		$this->assertNotFalse( email_exists( $email ) );
		$this->assertArrayNotHasKey( 'artist_invitation_status', $result );
		$this->assertArrayNotHasKey( 'invite_artist_id', $result );
		$this->assertSame( 0, $requests );
	}
}
