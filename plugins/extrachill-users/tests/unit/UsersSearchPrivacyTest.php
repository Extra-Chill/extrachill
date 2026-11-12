<?php
/**
 * Privacy contract for the extrachill/users-search ability.
 *
 * Member email addresses are private account data: non-admin callers must
 * neither receive them nor be able to match on them.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify users-search withholds email from non-admin callers.
 */
class Test_Users_Search_Privacy extends WP_UnitTestCase {

	/**
	 * Searchable target member (artist-flagged so artist-capable returns them).
	 *
	 * @var int
	 */
	private int $target_id;

	/**
	 * Load the ability and create the target member.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/artist-profiles.php';
		require_once dirname( __DIR__, 2 ) . '/inc/core/abilities/users-search.php';

		$this->target_id = self::factory()->user->create(
			array(
				'user_login'   => 'rosterpicktarget',
				'user_email'   => 'zq7hiddenaddr@example.org',
				'display_name' => 'Roster Pick Target',
				'role'         => 'subscriber',
			)
		);
		update_user_meta( $this->target_id, 'user_is_artist', '1' );
	}

	/**
	 * Reset authentication state.
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Log in as a non-admin account that self-declared the artist flag.
	 */
	private function act_as_artist_flagged_member(): void {
		$caller = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $caller, 'user_is_artist', '1' );
		wp_set_current_user( $caller );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	/**
	 * Log in as a site administrator.
	 */
	private function act_as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * Find the target row in a result set.
	 *
	 * @param mixed $results Ability result.
	 * @return array|null
	 */
	private function find_target( $results ): ?array {
		$this->assertIsArray( $results );
		foreach ( $results as $row ) {
			if ( (int) $row['id'] === $this->target_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Artist-flagged callers get picker fields but never an email.
	 */
	public function test_artist_capable_omits_email_for_artist_flagged_caller(): void {
		$this->act_as_artist_flagged_member();

		$row = $this->find_target(
			extrachill_users_ability_search(
				array(
					'term'    => 'Roster Pick',
					'context' => 'artist-capable',
				)
			)
		);

		$this->assertNotNull( $row, 'Target should be found by display name.' );
		$this->assertArrayNotHasKey( 'email', $row );
		$this->assertStringNotContainsString( 'zq7hiddenaddr', wp_json_encode( $row ) );

		// Fields the roster picker consumes.
		$this->assertSame( $this->target_id, $row['id'] );
		$this->assertSame( 'Roster Pick Target', $row['display_name'] );
		$this->assertSame( 'rosterpicktarget', $row['username'] );
		$this->assertArrayHasKey( 'avatar_url', $row );
		$this->assertArrayHasKey( 'profile_url', $row );
	}

	/**
	 * The picker still finds members by login.
	 */
	public function test_artist_capable_matches_login_for_artist_flagged_caller(): void {
		$this->act_as_artist_flagged_member();

		$row = $this->find_target(
			extrachill_users_ability_search(
				array(
					'term'    => 'rosterpick',
					'context' => 'artist-capable',
				)
			)
		);

		$this->assertNotNull( $row );
	}

	/**
	 * Artist-flagged callers cannot match members by partial or exact email.
	 */
	public function test_artist_capable_does_not_match_email_for_artist_flagged_caller(): void {
		$this->act_as_artist_flagged_member();

		foreach ( array( 'zq7hiddenaddr', 'zq7hiddenaddr@example.org' ) as $term ) {
			$results = extrachill_users_ability_search(
				array(
					'term'    => $term,
					'context' => 'artist-capable',
				)
			);
			$this->assertNull( $this->find_target( $results ), "Email term '{$term}' must not match." );
		}
	}

	/**
	 * Admins keep email matching and the email field.
	 */
	public function test_artist_capable_returns_email_for_admin(): void {
		$this->act_as_admin();

		$row = $this->find_target(
			extrachill_users_ability_search(
				array(
					'term'    => 'zq7hiddenaddr',
					'context' => 'artist-capable',
				)
			)
		);

		$this->assertNotNull( $row, 'Admins may search by email.' );
		$this->assertSame( 'zq7hiddenaddr@example.org', $row['email'] );
		$this->assertSame( 'Roster Pick Target', $row['display_name'] );
	}

	/**
	 * The admin context keeps email for admins.
	 */
	public function test_admin_context_returns_email_for_admin(): void {
		$this->act_as_admin();

		$row = $this->find_target(
			extrachill_users_ability_search(
				array(
					'term'    => 'zq7hiddenaddr',
					'context' => 'admin',
				)
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'zq7hiddenaddr@example.org', $row['email'] );
	}

	/**
	 * The admin context stays closed to non-admins.
	 */
	public function test_admin_context_denies_artist_flagged_caller(): void {
		$this->act_as_artist_flagged_member();

		$result = extrachill_users_ability_search(
			array(
				'term'    => 'zq7hiddenaddr',
				'context' => 'admin',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Mentions keep their lightweight, email-free shape.
	 */
	public function test_mentions_context_has_no_email_and_keeps_fields(): void {
		$this->act_as_artist_flagged_member();

		$row = $this->find_target(
			extrachill_users_ability_search(
				array(
					'term'    => 'rosterpick',
					'context' => 'mentions',
				)
			)
		);

		$this->assertNotNull( $row );
		$this->assertArrayNotHasKey( 'email', $row );
		$this->assertSame( 'rosterpicktarget', $row['username'] );
		$this->assertArrayHasKey( 'slug', $row );
		$this->assertArrayHasKey( 'avatar_url', $row );
		$this->assertArrayHasKey( 'profile_url', $row );

		$this->assertNull(
			$this->find_target(
				extrachill_users_ability_search(
					array(
						'term'    => 'zq7hiddenaddr',
						'context' => 'mentions',
					)
				)
			)
		);
	}

	/**
	 * Unflagged members still cannot use the artist-capable context.
	 */
	public function test_artist_capable_denies_unflagged_member(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = extrachill_users_ability_search(
			array(
				'term'    => 'Roster Pick',
				'context' => 'artist-capable',
			)
		);

		$this->assertWPError( $result );
	}
}
