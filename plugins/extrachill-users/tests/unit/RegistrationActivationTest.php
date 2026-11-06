<?php
/** Password signup and activation boundaries. */
class Test_RegistrationActivation extends WP_UnitTestCase {
	private $email;
	private $data;

	public function set_up() {
		parent::set_up();
		$this->email = 'activate-' . wp_rand() . '@example.com';
		$this->data  = array(
			'username' => 'activate' . wp_rand(),
			'email'    => $this->email,
			'password' => 'Chosen-password-123',
		);
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->delete( $wpdb->signups, array( 'user_email' => $this->email ) );
		remove_filter( 'pre_wp_mail', '__return_true' );
		parent::tear_down();
	}

	private function signup() {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->signups WHERE user_email = %s ORDER BY signup_id DESC LIMIT 1", $this->email ) );
	}

	public function test_registration_stores_only_hash_and_does_not_create_user_or_session() {
		$cookies  = 0;
		$callback = static function () use ( &$cookies ) { ++$cookies;
		};
		add_action( 'set_auth_cookie', $callback );
		$result = extrachill_users_create_pending_signup( $this->data, array( 'device_id' => 'device' ) );
		remove_action( 'set_auth_cookie', $callback );
		$this->assertSame( 'pending_activation', $result['status'] );
		$this->assertFalse( email_exists( $this->email ) );
		$this->assertSame( 0, $cookies );
		$this->assertArrayNotHasKey( 'access_token', $result );
		$this->assertArrayNotHasKey( 'refresh_token', $result );
		$row = $this->signup();
		$this->assertStringNotContainsString( $this->data['password'], $row->meta );
		$meta = maybe_unserialize( $row->meta );
		$this->assertTrue( wp_check_password( $this->data['password'], $meta['ec_activation']['password_hash'] ) );
	}

	public function test_duplicate_preserves_password_and_context_and_resend_is_throttled() {
		global $wpdb;
		extrachill_users_create_pending_signup( $this->data, array( 'newsletter_consent' => false ) );
		$before              = $this->signup();
		$changed             = $this->data;
		$changed['password'] = 'Attacker-replacement-123';
		extrachill_users_create_pending_signup( $changed, array( 'newsletter_consent' => true ) );
		$this->assertSame( $before->meta, $this->signup()->meta );
		$this->assertSame( $before->activation_key, $this->signup()->activation_key );
		$meta                             = maybe_unserialize( $before->meta );
		$meta['ec_activation']['sent_at'] = time() - 120;
		$wpdb->update( $wpdb->signups, array( 'meta' => maybe_serialize( $meta ) ), array( 'signup_id' => $before->signup_id ) );
		$mails    = 0;
		$callback = static function ( $result ) use ( &$mails ) { ++$mails;
			return $result;
		};
		add_filter( 'pre_wp_mail', $callback );
		extrachill_users_create_pending_signup( $changed );
		remove_filter( 'pre_wp_mail', $callback );
		$this->assertSame( 1, $mails );
		$this->assertSame( $before->activation_key, $this->signup()->activation_key );
	}

	public function test_notification_uses_branded_link() {
		$message = extrachill_users_activation_email( 'core', 'login', $this->email, 'key123', array( 'ec_activation' => true ) );
		$this->assertStringContainsString( '/login/?ec_activate=key123', $message );
		$this->assertStringNotContainsString( 'wp-activate.php', $message );
	}

	public function test_invalid_expired_and_replaced_keys_cannot_activate() {
		global $wpdb;
		$this->assertSame( 'invalid_key', extrachill_users_activate_password_signup( 'not-a-key' )->get_error_code() );
		extrachill_users_create_pending_signup( $this->data );
		$row                                 = $this->signup();
		$meta                                = maybe_unserialize( $row->meta );
		$meta['ec_activation']['expires_at'] = time() - 1;
		$wpdb->update( $wpdb->signups, array( 'meta' => maybe_serialize( $meta ) ), array( 'signup_id' => $row->signup_id ) );
		$this->assertSame( 'expired_key', extrachill_users_activate_password_signup( $row->activation_key )->get_error_code() );
		$this->assertFalse( email_exists( $this->email ) );
		extrachill_users_create_pending_signup( $this->data );
		$this->assertNotSame( $row->activation_key, $this->signup()->activation_key );
		$this->assertSame( 'invalid_key', extrachill_users_activate_password_signup( $row->activation_key )->get_error_code() );
	}

	public function test_activation_provisions_once_preserves_password_and_requires_signin() {
		extrachill_users_create_pending_signup( $this->data );
		$row      = $this->signup();
		$effects  = 0;
		$callback = static function () use ( &$effects ) { ++$effects;
		};
		add_action( 'extrachill_new_user_registered', $callback );
		$result = extrachill_users_activate_password_signup( $row->activation_key );
		$replay = extrachill_users_activate_password_signup( $row->activation_key );
		remove_action( 'extrachill_new_user_registered', $callback );
		$this->assertSame( 'activated', $result['status'] );
		$this->assertSame( 'already_active', $replay->get_error_code() );
		$this->assertSame( 1, $effects );
		$user = get_user_by( 'email', $this->email );
		$this->assertTrue( wp_check_password( $this->data['password'], $user->user_pass, $user->ID ) );
		$this->assertTrue( is_user_member_of_blog( $user->ID, ec_get_blog_id( 'community' ) ) );
		$this->assertEmpty( WP_Session_Tokens::get_instance( $user->ID )->get_all() );
		$this->assertEmpty( maybe_unserialize( $this->signup()->meta ) );
	}
}
