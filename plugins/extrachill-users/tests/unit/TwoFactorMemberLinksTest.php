<?php
/**
 * Two Factor links sent to members land on Extra Chill pages, not wp-admin
 * or the stock wp-login.php forms.
 */

class Test_Two_Factor_Member_Links extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private $original_server;

	/** @var array<string,mixed> */
	private $original_get;

	protected function setUp(): void {
		parent::setUp();
		$this->original_server = $_SERVER;
		$this->original_get    = $_GET;
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		$_GET    = $this->original_get;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @param string $location Redirect target.
	 * @return never
	 */
	public function stop_redirect( $location ) {
		throw new RuntimeException( (string) $location );
	}

	private function member(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		return $user_id;
	}

	public function test_member_edit_profile_links_go_to_settings(): void {
		$member = $this->member();

		$this->assertSame( extrachill_users_settings_url(), get_edit_profile_url( $member ) );
		// Two Factor's recovery-code email and post-login redirect append an anchor.
		$this->assertStringStartsWith( extrachill_users_settings_url(), get_edit_profile_url( $member ) . '#two-factor-backup-codes' );
	}

	public function test_wp_admin_users_keep_the_profile_screen(): void {
		$this->assertStringContainsString( 'profile.php', get_edit_profile_url( $this->admin() ) );
	}

	/**
	 * @param string $url Link in the message.
	 * @return array<string,string>
	 */
	private function settings_changed_email( string $url ): array {
		return array(
			'to'      => 'member@example.com',
			'subject' => '[Extra Chill] Your two-factor authentication settings have changed',
			'message' => "If you did NOT make this change, please review your security settings at {$url} and change your password immediately.\n\nhttps://extrachill.com",
			'headers' => '',
		);
	}

	public function test_settings_changed_email_links_members_to_settings(): void {
		$member = get_userdata( $this->member() );
		$email  = apply_filters( 'two_factor_settings_changed_email', $this->settings_changed_email( admin_url( 'profile.php' ) ), $member, array(), array() );

		$this->assertStringNotContainsString( 'wp-admin', $email['message'] );
		$this->assertStringContainsString( 'settings at ' . extrachill_users_settings_url() . ' and change', $email['message'] );
		$this->assertStringEndsWith( "\n\nhttps://extrachill.com", $email['message'] );
		$this->assertSame( 'member@example.com', $email['to'] );
	}

	public function test_settings_changed_email_is_unchanged_for_wp_admin_users(): void {
		$admin = get_userdata( $this->admin() );
		$email = $this->settings_changed_email( admin_url( 'profile.php' ) );

		$this->assertSame( $email, apply_filters( 'two_factor_settings_changed_email', $email, $admin, array(), array() ) );
	}

	public function test_create_new_password_link_goes_to_the_reset_page(): void {
		$_SERVER['REQUEST_URI']    = '/wp-login.php?action=lostpassword';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'action' => 'lostpassword' );

		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		$location = '';
		try {
			extrachill_redirect_wp_login_access();
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		}

		$this->assertSame( wp_lostpassword_url(), $location );
		$this->assertStringNotContainsString( 'wp-login.php', $location );
	}

	public function test_two_factor_challenge_pages_still_pass_through(): void {
		$_SERVER['REQUEST_URI']    = '/wp-login.php?action=validate_2fa';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'action' => 'validate_2fa' );

		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		try {
			extrachill_redirect_wp_login_access();
		} finally {
			remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		}

		$this->addToAssertionCount( 1 );
	}
}
