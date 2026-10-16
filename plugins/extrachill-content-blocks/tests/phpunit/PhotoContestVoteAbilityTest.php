<?php
/**
 * Photo Contest vote ability tests.
 *
 * Exercises extrachill/photo-contest-vote through the Abilities API surface
 * (wp_get_ability()->execute()), the way the extrachill-api route adapter
 * invokes it. Contest voters are anonymous — QR code / shared link, email
 * only — so the permission check must pass logged out, and abuse protection
 * is the per-IP vote rate limit on the optional route-supplied `ip` input.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

/**
 * Vote ability: anonymous permission + per-IP rate limit.
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
				'ip'           => '',
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

	public function test_anonymous_execution_still_enforces_email_dedup() {
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
		$this->assertSame( 'already_voted', $repeat->code );
	}

	// ─── Per-IP rate limit ───────────────────────────────────────────────────

	public function test_vote_rate_limit_trips_past_the_ceiling() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->createEntry( $post_id );

		for ( $i = 0; $i < EXTRACHILL_PHOTO_CONTEST_VOTE_RATE_LIMIT; $i++ ) {
			$result = $this->executeVote(
				array(
					'post_id'       => $post_id,
					'contest_id'    => self::CONTEST_ID,
					'entry_id'      => $entry_id,
					'email_address' => "guest{$i}@example.com",
					'ip'            => '203.0.113.9',
				)
			);
			$this->assertFalse( is_wp_error( $result ), "Vote {$i} inside the ceiling should pass (shared venue wifi)." );
		}

		$blocked = $this->executeVote(
			array(
				'post_id'       => $post_id,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => $entry_id,
				'email_address' => 'onemore@example.com',
				'ip'            => '203.0.113.9',
			)
		);
		$this->assertTrue( is_wp_error( $blocked ) );
		$this->assertSame( 'rate_limited', $blocked->code );

		$other_ip = $this->executeVote(
			array(
				'post_id'       => $post_id,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => $entry_id,
				'email_address' => 'other@example.com',
				'ip'            => '198.51.100.7',
			)
		);
		$this->assertFalse( is_wp_error( $other_ip ), 'The limit is per IP.' );
	}

	public function test_missing_ip_skips_the_rate_limit() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->createEntry( $post_id );

		for ( $i = 0; $i <= EXTRACHILL_PHOTO_CONTEST_VOTE_RATE_LIMIT; $i++ ) {
			$result = $this->executeVote(
				array(
					'post_id'       => $post_id,
					'contest_id'    => self::CONTEST_ID,
					'entry_id'      => $entry_id,
					'email_address' => "noip{$i}@example.com",
				)
			);
			$this->assertFalse( is_wp_error( $result ), 'Without a route-supplied ip the limiter is skipped (e.g. CLI).' );
		}
	}

	public function test_vote_and_submit_rate_limits_use_separate_buckets() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->createEntry( $post_id );
		$ip       = '203.0.113.9';

		// Exhaust the (much smaller) submission limiter for this IP.
		for ( $i = 0; $i < EXTRACHILL_PHOTO_CONTEST_RATE_LIMIT; $i++ ) {
			extrachill_content_blocks_photo_contest_rate_limit_ok( $ip );
		}
		$this->assertFalse( extrachill_content_blocks_photo_contest_rate_limit_ok( $ip ) );

		$result = $this->executeVote(
			array(
				'post_id'       => $post_id,
				'contest_id'    => self::CONTEST_ID,
				'entry_id'      => $entry_id,
				'email_address' => 'voter@example.com',
				'ip'            => $ip,
			)
		);
		$this->assertFalse( is_wp_error( $result ), 'A submit-limited IP can still vote.' );
	}
}
