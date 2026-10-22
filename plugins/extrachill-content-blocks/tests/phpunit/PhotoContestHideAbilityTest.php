<?php
/**
 * Photo Contest hide ability tests.
 *
 * Exercises extrachill/photo-contest-hide through the Abilities API surface
 * (wp_get_ability()->execute()), the way core's Abilities REST runner invokes
 * it for the front-end moderator Hide/Unhide controls. The permission check
 * (edit_others_posts) stays the authority regardless of transport.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

/**
 * Hide ability: moderator-only hide/unhide.
 */
class PhotoContestHideAbilityTest extends TestCase {

	private const CONTEST_ID = 'contest-1729000000000-123456';

	protected function setUp(): void {
		wp_stubs_reset();
		extrachill_content_blocks_register_photo_contest_hide_ability();
	}

	/**
	 * Create a contest post + one entry, returning [post_id, entry_id].
	 *
	 * @return array{0:int,1:int}
	 */
	private function createContestWithEntry(): array {
		$post_id = wp_stubs_insert_post( array( 'post_type' => 'post' ) );

		$GLOBALS['wp_test_posts'][ $post_id ]->post_content = 'blocks:' . $post_id;
		$GLOBALS['wp_test_blocks'][ $post_id ]              = array(
			array(
				'blockName' => 'extrachill/photo-contest',
				'attrs'     => array( 'contestId' => self::CONTEST_ID ),
			),
		);

		$result = extrachill_content_blocks_photo_contest_submit_entry(
			array(
				'post_id'      => $post_id,
				'contest_id'   => self::CONTEST_ID,
				'email'        => 'ghoul@example.com',
				'display_name' => 'Ghoul Friend',
				'honeypot'     => '',
				'file'         => array(
					'name'     => 'entry.jpg',
					'tmp_name' => '/tmp/phpXXXXXX',
					'size'     => 1024,
					'error'    => 0,
				),
			)
		);

		return array( $post_id, (int) $result['entry']['id'] );
	}

	/**
	 * Core's Abilities REST runner requires DELETE for destructive+idempotent
	 * abilities. The front-end moderator controls POST to the hide ability, so
	 * it must stay non-destructive (hiding is reversible).
	 */
	public function test_hide_ability_annotations_route_to_post() {
		$ability     = wp_get_ability( 'extrachill/photo-contest-hide' );
		$annotations = $ability->get_meta()['annotations'];

		$this->assertFalse( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'], 'Hide is reversible; destructive would force DELETE in the core runner.' );
	}

	public function test_anonymous_execution_is_rejected_by_the_permission_check() {
		list( , $entry_id ) = $this->createContestWithEntry();

		$result = wp_get_ability( 'extrachill/photo-contest-hide' )->execute(
			array(
				'entry_id' => $entry_id,
				'hidden'   => true,
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_moderators_can_hide_and_unhide_through_the_ability() {
		list( $post_id, $entry_id ) = $this->createContestWithEntry();

		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );
		$ability = wp_get_ability( 'extrachill/photo-contest-hide' );

		$hide = $ability->execute(
			array(
				'entry_id' => $entry_id,
				'hidden'   => true,
			)
		);
		$this->assertFalse( is_wp_error( $hide ) );
		$this->assertCount( 0, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );

		$unhide = $ability->execute(
			array(
				'entry_id' => $entry_id,
				'hidden'   => false,
			)
		);
		$this->assertFalse( is_wp_error( $unhide ) );
		$this->assertCount( 1, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
	}
}
