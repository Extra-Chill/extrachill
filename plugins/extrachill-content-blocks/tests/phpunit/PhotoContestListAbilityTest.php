<?php
/**
 * Photo Contest list ability tests.
 *
 * Exercises extrachill/photo-contest-list through the Abilities API surface
 * (wp_get_ability()->execute()). The ability stays public (anonymous contest
 * pages list entries through it), but the optional include_hidden input is
 * honoured only for callers who pass the moderation permission check —
 * everyone else silently gets the public list with the hidden flag stripped.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

/**
 * List ability: include_hidden moderator gating.
 */
class PhotoContestListAbilityTest extends TestCase {

	private const CONTEST_ID = 'contest-1729000000000-123456';

	protected function setUp(): void {
		wp_stubs_reset();
		extrachill_content_blocks_register_photo_contest_list_ability();
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
	private function createEntry( int $post_id, string $email ): int {
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
	 * Execute the list ability through the Abilities API.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error Execution result.
	 */
	private function executeList( array $input ) {
		return wp_get_ability( 'extrachill/photo-contest-list' )->execute(
			array_merge(
				array(
					'post_id'    => 0,
					'contest_id' => self::CONTEST_ID,
				),
				$input
			)
		);
	}

	public function test_include_hidden_is_honoured_for_moderators() {
		$post_id = $this->createContestPost();
		$visible = $this->createEntry( $post_id, 'a@example.com' );
		$hidden  = $this->createEntry( $post_id, 'b@example.com' );
		extrachill_content_blocks_photo_contest_set_entry_hidden( $hidden, true );

		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );

		$result = $this->executeList(
			array(
				'post_id'        => $post_id,
				'include_hidden' => true,
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( $visible, $hidden ), array_column( $result['entries'], 'id' ) );

		// Moderators get the hidden flag so the UI can dim/label hidden cards.
		$by_id = array_column( $result['entries'], null, 'id' );
		$this->assertFalse( $by_id[ $visible ]['hidden'] );
		$this->assertTrue( $by_id[ $hidden ]['hidden'] );
	}

	public function test_include_hidden_is_ignored_for_anonymous_callers() {
		$post_id = $this->createContestPost();
		$visible = $this->createEntry( $post_id, 'a@example.com' );
		$hidden  = $this->createEntry( $post_id, 'b@example.com' );
		extrachill_content_blocks_photo_contest_set_entry_hidden( $hidden, true );

		// No caps granted: the stub models an anonymous visitor.
		$result = $this->executeList(
			array(
				'post_id'        => $post_id,
				'include_hidden' => true,
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( $visible ), array_column( $result['entries'], 'id' ) );
		$this->assertArrayNotHasKey( 'hidden', $result['entries'][0], 'Public payload keeps the hidden flag internal.' );
	}

	public function test_include_hidden_is_ignored_for_logged_in_non_moderators() {
		$post_id = $this->createContestPost();
		$visible = $this->createEntry( $post_id, 'a@example.com' );
		$hidden  = $this->createEntry( $post_id, 'b@example.com' );
		extrachill_content_blocks_photo_contest_set_entry_hidden( $hidden, true );

		wp_stubs_set_user_caps( array( 'read', 'edit_posts' ) );

		$result = $this->executeList(
			array(
				'post_id'        => $post_id,
				'include_hidden' => true,
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( $visible ), array_column( $result['entries'], 'id' ) );
	}

	public function test_moderators_without_include_hidden_get_the_public_list() {
		$post_id = $this->createContestPost();
		$visible = $this->createEntry( $post_id, 'a@example.com' );
		$hidden  = $this->createEntry( $post_id, 'b@example.com' );
		extrachill_content_blocks_photo_contest_set_entry_hidden( $hidden, true );

		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );

		$result = $this->executeList( array( 'post_id' => $post_id ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( $visible ), array_column( $result['entries'], 'id' ) );
		$this->assertArrayNotHasKey( 'hidden', $result['entries'][0] );
	}
}
