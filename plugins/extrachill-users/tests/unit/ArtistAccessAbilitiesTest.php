<?php
/**
 * Artist Platform access ability authorization tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify administrative abilities fail closed while requests remain self-only.
 */
class Test_Artist_Access_Abilities extends WP_UnitTestCase {

	/**
	 * Register a clean copy of the abilities for each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/core/abilities/artist-access.php';

		foreach ( $this->ability_names() as $ability_name ) {
			wp_unregister_ability( $ability_name );
		}
		extrachill_users_register_artist_access_abilities();
		wp_set_current_user( 0 );
	}

	/**
	 * Reset authentication state.
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Return ability names owned by the artist access surface.
	 *
	 * @return string[]
	 */
	private function ability_names(): array {
		return array(
			'extrachill/list-artist-access-requests',
			'extrachill/approve-artist-access',
			'extrachill/request-artist-access',
			'extrachill/reject-artist-access',
		);
	}

	/**
	 * Subscribers cannot execute any administrative ability.
	 */
	public function test_registered_administrative_abilities_deny_subscribers(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( array( 'list-artist-access-requests', 'approve-artist-access', 'reject-artist-access' ) as $name ) {
			$result = wp_get_ability( 'extrachill/' . $name )->execute( array() );
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		}
	}

	/**
	 * Direct callback invocation cannot bypass administrative authorization.
	 */
	public function test_administrative_execute_callbacks_deny_direct_subscriber_invocation(): void {
		$target_id = self::factory()->user->create();
		update_user_meta( $target_id, 'artist_access_request', array( 'type' => 'artist' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$results = array(
			extrachill_users_ability_list_artist_access_requests(),
			extrachill_users_ability_approve_artist_access(
				array(
					'user_id' => $target_id,
					'type'    => 'artist',
				)
			),
			extrachill_users_ability_reject_artist_access( array( 'user_id' => $target_id ) ),
		);

		foreach ( $results as $result ) {
			$this->assertWPError( $result );
			$this->assertSame( 'artist_access_forbidden', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
		$this->assertNotEmpty( get_user_meta( $target_id, 'artist_access_request', true ) );
		$this->assertEmpty( get_user_meta( $target_id, 'user_is_artist', true ) );
	}

	/**
	 * Network administrators can list and transition requests.
	 */
	public function test_network_admin_can_execute_administrative_abilities(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$approved_id = self::factory()->user->create();
		$rejected_id = self::factory()->user->create();
		update_user_meta( $approved_id, 'artist_access_request', array( 'type' => 'artist' ) );
		update_user_meta( $rejected_id, 'artist_access_request', array( 'type' => 'professional' ) );

		$list = wp_get_ability( 'extrachill/list-artist-access-requests' )->execute( array() );
		$this->assertFalse( is_wp_error( $list ) );
		$this->assertCount( 2, $list['requests'] );

		$approved = wp_get_ability( 'extrachill/approve-artist-access' )->execute(
			array(
				'user_id' => $approved_id,
				'type'    => 'artist',
			)
		);
		$this->assertFalse( is_wp_error( $approved ) );
		$this->assertSame( '1', get_user_meta( $approved_id, 'user_is_artist', true ) );

		$rejected = wp_get_ability( 'extrachill/reject-artist-access' )->execute( array( 'user_id' => $rejected_id ) );
		$this->assertFalse( is_wp_error( $rejected ) );
		$this->assertEmpty( get_user_meta( $rejected_id, 'artist_access_request', true ) );
	}

	/**
	 * The request ability continues to ignore supplied user IDs and use self.
	 */
	public function test_request_ability_remains_self_only(): void {
		$self_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create();
		wp_set_current_user( $self_id );

		$result = extrachill_users_ability_request_artist_access(
			array(
				'user_id' => $other_id,
				'type'    => 'artist',
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $self_id, $result['user_id'] );
		$this->assertNotEmpty( get_user_meta( $self_id, 'artist_access_request', true ) );
		$this->assertEmpty( get_user_meta( $other_id, 'artist_access_request', true ) );
	}

	/**
	 * Explicit WP-CLI execution retains administrative access.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_explicit_wp_cli_context_is_authorized(): void {
		define( 'WP_CLI', true );
		wp_set_current_user( 0 );

		$this->assertTrue( extrachill_users_artist_access_admin_permission() );
		$this->assertNotWPError( extrachill_users_ability_list_artist_access_requests() );
	}
}
