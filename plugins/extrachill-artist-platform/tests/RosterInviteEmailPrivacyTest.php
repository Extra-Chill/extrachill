<?php
/**
 * Roster invites by picked user withhold the invitee's email address.
 *
 * The artist-manager picker selects members from users-search, which no
 * longer returns email to non-admins. Picked members are invited by user ID
 * through the network team invitation flow; the address is resolved
 * server-side and must not be echoed back through the invite response or the
 * roster listing. Addresses the inviter typed for a new account stay visible.
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';
require_once __DIR__ . '/support/action-scheduler-recorder.php';

final class RosterInviteEmailPrivacyTest extends EC_Artist_Platform_TestCase {
	private int $manager_id;
	private int $invitee_id;
	private int $artist_id;
	private string $invitee_email;
	private string $typed_email;

	protected function setUp(): void {
		parent::setUp();

		// The team store commits the test transaction, so fixtures must not collide across tests.
		$suffix              = strtolower( wp_generate_password( 8, false ) );
		$this->invitee_email = 'qx9picked-' . $suffix . '@example.org';
		$this->typed_email   = 'typed-invite-' . $suffix . '@example.org';

		$this->manager_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->invitee_id = (int) self::factory()->user->create(
			array(
				'user_login'   => 'pickedinvitee' . $suffix,
				'user_email'   => $this->invitee_email,
				'display_name' => 'Picked Invitee',
				'role'         => 'subscriber',
			)
		);
		$this->artist_id  = $this->create_artist_profile( 'Privacy Roster Artist' );
		$this->create_artist_membership( $this->manager_id, $this->artist_id );

		/*
		 * The custom-avatar lookup switches to the community blog, which this
		 * harness does not provision; the resulting SQL error aborts the test
		 * transaction. Avatars are irrelevant to this contract.
		 */
		remove_filter( 'pre_get_avatar_data', 'extrachill_custom_avatar_data', 10 );

		$GLOBALS['ec_artist_platform_test_queued_actions'] = array();

		wp_set_current_user( $this->manager_id );
	}

	/**
	 * The team store commits its own transactions, which also commits the WP
	 * test transaction. Remove this artist's team rows so later suites see a
	 * clean store.
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE team_type = %s AND team_id = %d', \ExtraChill\Users\Teams\TeamMembershipRepository::table(), 'artist', $this->artist_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE team_type = %s AND team_id = %d', \ExtraChill\Users\Teams\TeamMembershipRepository::invitations_table(), 'artist', $this->artist_id ) );
		switch_to_blog( $this->artist_blog_id() );
		wp_delete_post( $this->artist_id, true );
		restore_current_blog();
		parent::tearDown();
	}

	/**
	 * Network invitation rows for this artist.
	 *
	 * @return array[]
	 */
	private function invitation_rows(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, email_hash, status FROM %i WHERE team_type = %s AND team_id = %d', \ExtraChill\Users\Teams\TeamMembershipRepository::invitations_table(), 'artist', $this->artist_id ), ARRAY_A );
	}

	/**
	 * List the roster as the managing member.
	 *
	 * @return array|WP_Error
	 */
	private function list_roster() {
		return $this->on_artist_blog(
			fn() => extrachill_artist_platform_ability_artist_get_roster( array( 'id' => $this->artist_id ) )
		);
	}

	/**
	 * Run a callback on the artist blog, where roster routes execute.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	private function on_artist_blog( callable $callback ) {
		switch_to_blog( $this->artist_blog_id() );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Invite via the REST handler.
	 *
	 * @param array $params Body params.
	 * @return WP_REST_Response|WP_Error
	 */
	private function rest_invite( array $params ) {
		if ( ! function_exists( 'extrachill_api_artist_roster_invite_handler' ) ) {
			$this->markTestSkipped( 'extrachill-api roster routes are not loaded.' );
		}
		return $this->on_artist_blog(
			function () use ( $params ) {
				$request = new WP_REST_Request( 'POST', '/extrachill/v1/artists/' . $this->artist_id . '/roster' );
				$request->set_param( 'id', $this->artist_id );
				foreach ( $params as $key => $value ) {
					$request->set_param( $key, $value );
				}
				return extrachill_api_artist_roster_invite_handler( $request );
			}
		);
	}

	public function test_invite_by_user_id_binds_member_without_echoing_email(): void {
		$response = $this->rest_invite( array( 'user_id' => $this->invitee_id ) );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertStringNotContainsString( 'qx9picked', wp_json_encode( $data ) );
		$this->assertSame( '', $data['invitation']['email'] );
		$this->assertSame( 'Picked Invitee', $data['invitation']['display_name'] );

		// The network invitation itself is bound to the picked member's account and address.
		$rows = $this->invitation_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->invitee_id, (int) $rows[0]['user_id'] );
		$this->assertSame( hash_hmac( 'sha256', $this->invitee_email, wp_salt( 'auth' ) ), $rows[0]['email_hash'] );
		$this->assertCount( 1, $GLOBALS['ec_artist_platform_test_queued_actions'] );
	}

	public function test_roster_listing_withholds_email_of_existing_members(): void {
		$this->assertNotWPError( $this->rest_invite( array( 'user_id' => $this->invitee_id ) ) );

		$roster = $this->list_roster();

		$this->assertNotWPError( $roster );
		$this->assertStringNotContainsString( 'qx9picked', wp_json_encode( $roster['invites'] ) );
		$this->assertCount( 1, $roster['invites'] );
		$this->assertSame( '', $roster['invites'][0]['email'] );
		$this->assertSame( 'Picked Invitee', $roster['invites'][0]['display_name'] );
		$this->assertTrue( $roster['invites'][0]['of_existing_user'] );
	}

	public function test_typed_email_of_existing_member_is_echoed_once_then_withheld(): void {
		$response = $this->rest_invite( array( 'email' => $this->invitee_email ) );

		$this->assertNotWPError( $response );
		// The inviter typed this address, so the invite response may echo it.
		$this->assertSame( $this->invitee_email, $response->get_data()['invitation']['email'] );

		// The stored invitation cannot tell typed from picked, so listings withhold it.
		$roster = $this->list_roster();
		$this->assertStringNotContainsString( 'qx9picked', wp_json_encode( $roster['invites'] ) );
		$this->assertSame( 'Picked Invitee', $roster['invites'][0]['display_name'] );
	}

	/**
	 * Public invitation record shaped like TeamOnboardingService::list_invitations().
	 *
	 * @param int    $user_id   Bound user ID.
	 * @param string $public_id Invitation public ID.
	 * @return array
	 */
	private function listed_invitation( int $user_id, string $public_id ): array {
		return array(
			'id'         => 7,
			'public_id'  => $public_id,
			'user_id'    => $user_id,
			'status'     => 'pending',
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public function test_listing_shows_typed_address_of_account_created_by_the_invitation(): void {
		$public_id = wp_generate_uuid4();
		$user_id   = (int) self::factory()->user->create( array( 'user_email' => $this->typed_email ) );
		update_user_meta( $user_id, 'ec_unclaimed', '1' );
		update_user_meta( $user_id, 'ec_team_invitation_account', $public_id );

		$listed = ec_artist_roster_invitation_response( $this->listed_invitation( $user_id, $public_id ) );

		$this->assertSame( $this->typed_email, $listed['email'] );
		$this->assertSame( $this->typed_email, $listed['display_name'] );
	}

	public function test_listing_withholds_address_without_matching_invitation_provenance(): void {
		$public_id = wp_generate_uuid4();
		$user_id   = (int) self::factory()->user->create(
			array(
				'user_email'   => $this->typed_email,
				'display_name' => 'Unclaimed Elsewhere',
			)
		);
		// Unclaimed by another flow (e.g. an event submission), not by this invitation.
		update_user_meta( $user_id, 'ec_unclaimed', '1' );
		update_user_meta( $user_id, 'ec_team_invitation_account', wp_generate_uuid4() );

		$listed = ec_artist_roster_invitation_response( $this->listed_invitation( $user_id, $public_id ) );

		$this->assertSame( '', $listed['email'] );
		$this->assertSame( 'Unclaimed Elsewhere', $listed['display_name'] );

		// A claimed account never exposes its address, even with matching provenance.
		update_user_meta( $user_id, 'ec_team_invitation_account', $public_id );
		delete_user_meta( $user_id, 'ec_unclaimed' );
		$this->assertSame( '', ec_artist_roster_invitation_response( $this->listed_invitation( $user_id, $public_id ) )['email'] );
	}

	public function test_invite_by_unknown_user_id_is_rejected(): void {
		$response = $this->rest_invite( array( 'user_id' => $this->invitee_id + 9999 ) );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_user', $response->get_error_code() );
		$this->assertSame( array(), $this->invitation_rows() );
		$this->assertSame( array(), $GLOBALS['ec_artist_platform_test_queued_actions'] );
	}

	public function test_invite_requires_email_or_user_id(): void {
		$response = $this->rest_invite( array() );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_email', $response->get_error_code() );
	}

	public function test_non_manager_cannot_invite_by_user_id(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->rest_invite( array( 'user_id' => $this->invitee_id ) );

		$this->assertWPError( $response );
		$this->assertSame( 'permission_denied', $response->get_error_code() );
		$this->assertSame( array(), $this->invitation_rows() );
	}
}
