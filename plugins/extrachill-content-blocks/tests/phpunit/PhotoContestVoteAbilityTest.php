<?php
/**
 * Photo Contest vote ability tests.
 *
 * Exercises extrachill/photo-contest-vote through the Abilities API surface
 * (wp_get_ability()->execute()), the way the extrachill-api route adapter
 * invokes it. Contest voters are anonymous — QR code / shared link, email
 * only — so the permission check must pass logged out. The ability does no
 * IP handling or rate limiting: per-client admission is owned by the
 * extrachill-api public-write admission middleware, and the ability is
 * hidden from the core Abilities REST runner (show_in_rest false).
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

/**
 * Vote ability: anonymous permission + status-coded error passthrough.
 */
class PhotoContestVoteAbilityTest extends TestCase {

	private const CONTEST_ID = 'contest-1729000000000-123456';

	protected function setUp(): void {
		wp_stubs_reset();
		extrachill_content_blocks_register_photo_contest_vote_ability();
	}

	/**
	 * Create a contest post with a photo-contest block fixture.
	 *
	 * @return int Post ID.
	 */
	private function createContestPost(): int {
		$post_id = wp_stubs_insert_post(
			array(
				'post_type'  => 'post',
				'post_title' => 'Skinful Halloween Costume Contest',
			)
		);

		$GLOBALS['wp_test_posts'][ $post_id ]->post_content = 'blocks:' . $post_id;
		$GLOBALS['wp_test_blocks'][ $post_id ]              = array(
			array(
				'blockName' => 'extrachill/photo-contest',
				'attrs'     => array( 'contestId' => self::CONTEST_ID ),
			),
		);

		return $post_id;
	}

	/**
	 * Submit a valid entry and return its attachment ID.
	 *
	 * @param int    $post_id Contest post ID.
	 * @param string $email   Entrant email.
	 * @return int Entry ID.
	 */
	private function createEntry( int $post_id, string $email = 'ghoul@example.com' ): int {
		$result = extrachill_content_blocks_photo_contest_submit_entry(
			array(
				'post_id'      => $post_id,
				'contest_id'   => self::CONTEST_ID,
				'email'        => $email,
				'display_name' => 'Ghoul Friend',
				'honeypot'     => '',
				'file'         => array(
					'name'     => 'costume.jpg',
					'tmp_name' => '/tmp/phpXXXXXX',
					'size'     => 1024 * 1024,
					'error'    => 0,
				),
			)
		);

		return (int) $result['entry']['id'];
	}

	/**
	 * Execute the vote ability through the Abilities API.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error Execution result.
	 */
	private function executeVote( array $input ) {
		return wp_get_ability( 'extrachill/photo-contest-vote' )->execute( $input );
	}

	// ─── Anonymous permission ────────────────────────────────────────────────

	public function test_anonymous_execution_passes_the_permission_check() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->createEntry( $post_id );

		$ability = wp_get_ability( 'extrachill/photo-contest-vote' );
		$this->assertNotNull( $ability, 'Vote ability is registered.' );

		// The stubs model a logged-out visitor; is_user_logged_in() is false.
		$this->assertFalse( is_user_logged_in(), 'Precondition: the voter is anonymous.' );
		$this->assertTrue( $ability->has_permission( array() ), 'Vote permission is public.' );

		$result = $this->executeVote(
			array(
				'post_id'       => $post_id,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => $entry_id,
				'email_address' => 'voter@example.com',
			)
		);

		$this->assertFalse( is_wp_error( $result ), 'Anonymous vote must not be rejected by WP_Ability::execute().' );
		$this->assertSame( 1, $result['vote_count'] );
	}

	public function test_anonymous_execution_still_enforces_email_dedup_with_409() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->createEntry( $post_id );

		$input = array(
			'post_id'       => $post_id,
			'contest_id'    => self::CONTEST_ID,
			'entry_id'      => $entry_id,
			'email_address' => 'voter@example.com',
		);

		$this->assertFalse( is_wp_error( $this->executeVote( $input ) ) );

		$repeat = $this->executeVote( $input );
		$this->assertTrue( is_wp_error( $repeat ), 'Email dedup remains the authority.' );
		$this->assertSame( 'already_voted', $repeat->get_error_code() );
		$this->assertSame( 409, $repeat->get_error_data()['status'], 'Conflicts surface as 409, not 500.' );
	}

	// ─── Status-coded error passthrough ──────────────────────────────────────

	public function test_entry_not_found_passes_through_with_404() {
		$post_id = $this->createContestPost();

		$result = $this->executeVote(
			array(
				'post_id'       => $post_id,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => 424242,
				'email_address' => 'voter@example.com',
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'entry_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_contest_not_found_passes_through_with_404() {
		$result = $this->executeVote(
			array(
				'post_id'       => 999999,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => 1,
				'email_address' => 'voter@example.com',
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'contest_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_input_validation_errors_carry_400() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->createEntry( $post_id );

		$cases = array(
			'invalid_post_id'    => array(
				'post_id'       => 0,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => $entry_id,
				'email_address' => 'voter@example.com',
			),
			'invalid_contest_id' => array(
				'post_id'       => $post_id,
				'contest_id'    => '',
				'entry_id'      => $entry_id,
				'email_address' => 'voter@example.com',
			),
			'invalid_entry_id'   => array(
				'post_id'       => $post_id,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => 0,
				'email_address' => 'voter@example.com',
			),
			'invalid_email'      => array(
				'post_id'       => $post_id,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => $entry_id,
				'email_address' => 'not-an-email',
			),
		);

		foreach ( $cases as $expected_code => $input ) {
			$result = $this->executeVote( $input );
			$this->assertTrue( is_wp_error( $result ), "{$expected_code} must fail." );
			$this->assertSame( $expected_code, $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'], "{$expected_code} must carry HTTP 400." );
		}
	}
}
