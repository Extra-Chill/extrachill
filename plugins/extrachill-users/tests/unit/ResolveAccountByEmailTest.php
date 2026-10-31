<?php
/**
 * Tests for the extrachill/resolve-account-by-email primitive.
 *
 * @package ExtraChill\Users
 */

class Test_Resolve_Account_By_Email extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! wp_has_ability_category( 'extrachill-users' ) ) {
			do_action( 'wp_abilities_api_categories_init' );
		}
		if ( ! wp_has_ability( 'extrachill/resolve-account-by-email' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
	}

	private function resolve( string $email, array $extra = array() ) {
		return extrachill_users_ability_resolve_account_by_email(
			array_merge(
				array(
					'email'               => $email,
					'registration_source' => 'event_rsvp',
				),
				$extra
			)
		);
	}

	/**
	 * Parse the key out of a claim URL.
	 */
	private function claim_key( string $url ): string {
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) ( $query['key'] ?? '' );
	}

	public function test_ability_is_registered_and_hidden_from_rest(): void {
		$ability = wp_get_ability( 'extrachill/resolve-account-by-email' );

		$this->assertNotNull( $ability );
		$this->assertFalse( (bool) ( $ability->get_meta()['show_in_rest'] ?? false ) );
	}

	public function test_new_email_creates_unclaimed_subscriber_with_claim_url(): void {
		$result = $this->resolve( 'guest-new@example.com', array( 'utm' => array( 'source' => 'ig' ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created'] );
		$this->assertTrue( $result['unclaimed'] );
		$this->assertFalse( $result['claim_throttled'] );

		$user = get_userdata( $result['user_id'] );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'guest-new@example.com', $user->user_email );
		$this->assertSame( '1', (string) get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertSame( 'event_rsvp', get_user_meta( $user->ID, 'registration_source', true ) );

		$this->assertStringContainsString( '/reset-password/', $result['claim_url'] );
		$this->assertStringContainsString( 'action=reset', $result['claim_url'] );
		$this->assertStringContainsString( 'login=' . rawurlencode( $user->user_login ), $result['claim_url'] );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( $this->claim_key( $result['claim_url'] ), $user->user_login ) );
	}

	public function test_existing_unclaimed_account_is_reused_with_claim_url(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'guest-unclaimed@example.com' ) );
		update_user_meta( $user_id, 'ec_unclaimed', 1 );

		$result = $this->resolve( 'guest-unclaimed@example.com' );

		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertFalse( $result['created'] );
		$this->assertTrue( $result['unclaimed'] );
		$this->assertNotSame( '', $result['claim_url'] );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = %s", 'guest-unclaimed@example.com' ) ) );
	}

	public function test_existing_claimed_account_is_reused_without_minting_a_key(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'guest-claimed@example.com' ) );
		$before  = get_userdata( $user_id )->user_activation_key;

		$result = $this->resolve( 'GUEST-claimed@example.com' );

		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertFalse( $result['created'] );
		$this->assertFalse( $result['unclaimed'] );
		$this->assertSame( '', $result['claim_url'] );
		$this->assertFalse( $result['claim_throttled'] );
		$this->assertSame( $before, get_userdata( $user_id )->user_activation_key );
	}

	public function test_invalid_email_is_rejected(): void {
		$result = $this->resolve( 'not-an-email' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_missing_registration_source_is_rejected(): void {
		$result = extrachill_users_ability_resolve_account_by_email( array( 'email' => 'guest-nosource@example.com' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_registration_source', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'guest-nosource@example.com' ) );
	}

	public function test_repeat_calls_are_throttled_and_do_not_rotate_the_key(): void {
		$first = $this->resolve( 'guest-repeat@example.com' );
		$user  = get_userdata( $first['user_id'] );
		$key   = $this->claim_key( $first['claim_url'] );

		$second = $this->resolve( 'guest-repeat@example.com' );

		$this->assertSame( $first['user_id'], $second['user_id'] );
		$this->assertFalse( $second['created'] );
		$this->assertTrue( $second['claim_throttled'] );
		$this->assertSame( '', $second['claim_url'] );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( $key, $user->user_login ) );
	}

	public function test_claim_url_is_minted_again_after_the_throttle_window(): void {
		$first   = $this->resolve( 'guest-window@example.com' );
		$user_id = $first['user_id'];
		update_user_meta( $user_id, EXTRACHILL_USERS_CLAIM_MINTED_AT_META, time() - HOUR_IN_SECONDS );

		$second = $this->resolve( 'guest-window@example.com' );

		$this->assertFalse( $second['claim_throttled'] );
		$this->assertNotSame( '', $second['claim_url'] );
		$this->assertNotSame( $this->claim_key( $first['claim_url'] ), $this->claim_key( $second['claim_url'] ) );
	}

	public function test_build_claim_url_helper_returns_valid_reset_link(): void {
		$user = get_userdata( self::factory()->user->create() );
		$url  = ec_users_build_account_claim_url( $user );

		$this->assertStringContainsString( '/reset-password/', $url );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( $this->claim_key( $url ), $user->user_login ) );
	}
}
