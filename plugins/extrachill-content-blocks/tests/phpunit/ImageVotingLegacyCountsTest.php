<?php
/**
 * Image Voting legacy vote count tests (issue #1541).
 *
 * Posts that predate image_vote comment storage keep their historical votes
 * in the block's voteCount/voters attributes. The read path must surface
 * those counts, add votes cast since, and reconcile comments created by the
 * legacy backfill instead of double counting them.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

/**
 * Legacy attribute counts, read path, dedup and backfill idempotency.
 */
class ImageVotingLegacyCountsTest extends TestCase {

	// Real production instance IDs are stored with a mangled "\u002d".
	private const INSTANCE_A = 'blocku002du002d1698522018981-627404';
	private const INSTANCE_B = 'blocku002du002d1729973005667-919648';

	protected function setUp(): void {
		wp_stubs_reset();
		extrachill_content_blocks_register_image_voting_list_ability();
	}

	/**
	 * Create a post with legacy image-voting blocks (one nested in a group).
	 *
	 * @return int Post ID.
	 */
	private function createLegacyPost(): int {
		$post_id = wp_stubs_insert_post( array( 'post_title' => 'Boogieman Costume Contest' ) );

		$GLOBALS['wp_test_posts'][ $post_id ]->post_content = 'blocks:' . $post_id;
		$GLOBALS['wp_test_blocks'][ $post_id ]              = array(
			array(
				'blockName' => 'extrachill/image-voting',
				'attrs'     => array(
					'uniqueBlockId' => self::INSTANCE_A,
					'voteCount'     => 103,
				),
			),
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName' => 'extrachill/image-voting',
						'attrs'     => array(
							'uniqueBlockId' => self::INSTANCE_B,
							'voteCount'     => 2,
							'voters'        => array( 'Fan@Example.com', 'other@example.com', 'third@example.com' ),
						),
					),
				),
			),
		);

		return $post_id;
	}

	public function test_legacy_count_uses_larger_of_vote_count_and_voters() {
		$this->assertSame( 103, extrachill_content_blocks_image_vote_legacy_count_from_attrs( array( 'voteCount' => 103 ) ) );
		$this->assertSame(
			2,
			extrachill_content_blocks_image_vote_legacy_count_from_attrs(
				array(
					'voteCount' => 1,
					'voters'    => array( 'a@example.com', 'A@example.com ', 'b@example.com', '' ),
				)
			)
		);
		$this->assertSame( 0, extrachill_content_blocks_image_vote_legacy_count_from_attrs( array() ) );
	}

	public function test_total_falls_back_to_legacy_attributes_without_comments() {
		$post_id = $this->createLegacyPost();

		$this->assertSame( 0, extrachill_content_blocks_image_vote_count( $post_id, self::INSTANCE_A ) );
		$this->assertSame( 103, extrachill_content_blocks_image_vote_total( $post_id, self::INSTANCE_A ) );
		$this->assertSame( 3, extrachill_content_blocks_image_vote_total( $post_id, self::INSTANCE_B ), 'Nested blocks are found.' );
		$this->assertSame( 0, extrachill_content_blocks_image_vote_total( $post_id, 'unknown-instance' ) );
	}

	public function test_list_ability_returns_legacy_count() {
		$post_id = $this->createLegacyPost();

		$result = wp_get_ability( 'extrachill/image-voting-list' )->execute(
			array(
				'post_id'     => $post_id,
				'instance_id' => self::INSTANCE_A,
			)
		);

		$this->assertSame( array( 'vote_count' => 103 ), $result );
	}

	public function test_new_vote_adds_to_legacy_count() {
		$post_id = $this->createLegacyPost();

		$result = extrachill_content_blocks_process_image_vote( $post_id, self::INSTANCE_A, 'new@example.com' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 104, $result['vote_count'] );
		$this->assertSame( 104, extrachill_content_blocks_image_vote_total( $post_id, self::INSTANCE_A ) );
	}

	public function test_legacy_voter_cannot_vote_again() {
		$post_id = $this->createLegacyPost();

		$result = extrachill_content_blocks_process_image_vote( $post_id, self::INSTANCE_B, 'fan@example.com' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'already_voted', $result['code'] );
		$this->assertSame( 0, extrachill_content_blocks_image_vote_count( $post_id, self::INSTANCE_B ) );
	}

	public function test_backfill_does_not_double_count_and_is_idempotent() {
		$post_id = $this->createLegacyPost();
		extrachill_content_blocks_process_image_vote( $post_id, self::INSTANCE_A, 'new@example.com' );

		$plan = Extrachill_Content_Blocks_Image_Votes_Backfill_Command::plan_post( $post_id );
		$this->assertIsArray( $plan );
		$by_instance = array_column( $plan, null, 'instance_id' );
		$this->assertSame( 103, $by_instance[ self::INSTANCE_A ]['emailless'], 'Live votes are not mistaken for migrated ones.' );
		$this->assertSame( array( 'fan@example.com', 'other@example.com', 'third@example.com' ), $by_instance[ self::INSTANCE_B ]['emails'] );
		$this->assertSame( 0, $by_instance[ self::INSTANCE_B ]['emailless'] );

		// Planning is read-only.
		$this->assertSame( 1, extrachill_content_blocks_image_vote_count( $post_id, self::INSTANCE_A ) );

		$this->assertSame( 106, Extrachill_Content_Blocks_Image_Votes_Backfill_Command::apply_plan( $post_id, $plan ) );

		$this->assertSame( 104, extrachill_content_blocks_image_vote_count( $post_id, self::INSTANCE_A ) );
		$this->assertSame( 104, extrachill_content_blocks_image_vote_total( $post_id, self::INSTANCE_A ) );
		$this->assertSame( 3, extrachill_content_blocks_image_vote_total( $post_id, self::INSTANCE_B ) );

		// Second run plans nothing.
		$again = Extrachill_Content_Blocks_Image_Votes_Backfill_Command::plan_post( $post_id );
		$this->assertIsArray( $again );
		foreach ( $again as $instance ) {
			$this->assertSame( array(), $instance['emails'] );
			$this->assertSame( 0, $instance['emailless'] );
		}
	}

	public function test_backfilled_counts_survive_stripping_legacy_attributes() {
		$post_id = $this->createLegacyPost();
		$plan    = Extrachill_Content_Blocks_Image_Votes_Backfill_Command::plan_post( $post_id );
		$this->assertIsArray( $plan );
		Extrachill_Content_Blocks_Image_Votes_Backfill_Command::apply_plan( $post_id, $plan );

		// Strip voteCount/voters as the documented post-backfill cleanup does.
		$GLOBALS['wp_test_blocks'][ $post_id ][0]['attrs']                   = array( 'uniqueBlockId' => self::INSTANCE_A );
		$GLOBALS['wp_test_blocks'][ $post_id ][1]['innerBlocks'][0]['attrs'] = array( 'uniqueBlockId' => self::INSTANCE_B );

		$this->assertSame( 103, extrachill_content_blocks_image_vote_total( $post_id, self::INSTANCE_A ) );
		$this->assertSame( 3, extrachill_content_blocks_image_vote_total( $post_id, self::INSTANCE_B ) );
	}

	public function test_posts_without_legacy_votes_count_comments_only() {
		$post_id = wp_stubs_insert_post( array( 'post_title' => 'Fresh' ) );

		extrachill_content_blocks_process_image_vote( $post_id, 'block-1-1', 'a@example.com' );
		extrachill_content_blocks_process_image_vote( $post_id, 'block-1-1', 'b@example.com' );

		$this->assertSame( 2, extrachill_content_blocks_image_vote_total( $post_id, 'block-1-1' ) );
	}
}
