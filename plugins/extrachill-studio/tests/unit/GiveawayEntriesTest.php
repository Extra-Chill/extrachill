<?php
/**
 * Giveaway entry filtering and winner selection tests.
 *
 * @package ExtraChillStudio
 */

/**
 * Verify extrachill/run-giveaway entry counting and winner selection.
 */
class Test_Giveaway_Entries extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing

	/**
	 * Build a normalized comment.
	 *
	 * @param string   $id       Comment ID.
	 * @param string   $username Author username.
	 * @param string[] $mentions Mentioned usernames.
	 * @return array
	 */
	private function comment( string $id, string $username, array $mentions = array() ): array {
		return array(
			'id'              => $id,
			'author_username' => $username,
			'text'            => implode( ' ', array_map( static fn( $m ) => '@' . $m, $mentions ) ),
			'mentions'        => $mentions,
		);
	}

	/**
	 * A pool modelled on a real giveaway: one heavy tagger, a multi-tag
	 * single comment, a commenter whose first comment has no tag, and
	 * comments without tags.
	 */
	private function comments(): array {
		return array(
			$this->comment( 'c1', 'heavy', array( 'a' ) ),
			$this->comment( 'c2', 'heavy', array( 'b' ) ),
			$this->comment( 'c3', 'heavy', array( 'c' ) ),
			$this->comment( 'c4', 'multi', array( 'a', 'b', 'c', 'd', 'e' ) ),
			$this->comment( 'c5', 'late', array() ),
			$this->comment( 'c6', 'late', array( 'f' ) ),
			$this->comment( 'c7', 'lurker', array() ),
			$this->comment( 'c8', 'Heavy', array( 'g' ) ),
		);
	}

	public function test_per_comment_is_the_default_and_counts_every_tagging_comment(): void {
		$result = ec_studio_filter_giveaway_entries( $this->comments(), array( 'require_tag' => true ) );

		$this->assertSame( 'per_comment', $result['stats']['entry_mode'] );
		$this->assertSame( array( 'c1', 'c2', 'c3', 'c4', 'c6', 'c8' ), array_column( $result['entries'], 'id' ) );
		$this->assertSame( 8, $result['stats']['total_comments'] );
		$this->assertSame( 6, $result['stats']['valid_entries'] );
		$this->assertSame( 2, $result['stats']['filtered_out'] );
		$this->assertSame( 3, $result['stats']['unique_users'] );
		$this->assertSame(
			array(
				'heavy' => 4,
				'multi' => 1,
				'late'  => 1,
			),
			$result['stats']['entries_by_user']
		);
	}

	public function test_per_user_gives_each_qualifying_person_one_entry_from_their_first_qualifying_comment(): void {
		$result = ec_studio_filter_giveaway_entries(
			$this->comments(),
			array(
				'require_tag' => true,
				'entry_mode'  => 'per_user',
			)
		);

		$this->assertSame( 'per_user', $result['stats']['entry_mode'] );
		$this->assertSame( array( 'c1', 'c4', 'c6' ), array_column( $result['entries'], 'id' ) );
		$this->assertSame( 3, $result['stats']['valid_entries'] );
		$this->assertSame( 3, $result['stats']['unique_users'] );
	}

	public function test_unknown_entry_mode_falls_back_to_per_comment(): void {
		$result = ec_studio_filter_giveaway_entries( $this->comments(), array( 'entry_mode' => 'per_tag' ) );

		$this->assertSame( 'per_comment', $result['stats']['entry_mode'] );
	}

	public function test_min_tags_counts_mentions_per_comment(): void {
		$result = ec_studio_filter_giveaway_entries(
			$this->comments(),
			array(
				'require_tag' => true,
				'min_tags'    => 2,
			)
		);

		$this->assertSame( array( 'c4' ), array_column( $result['entries'], 'id' ) );
	}

	public function test_without_require_tag_every_comment_is_an_entry(): void {
		$result = ec_studio_filter_giveaway_entries( $this->comments(), array( 'require_tag' => false ) );

		$this->assertSame( 8, $result['stats']['valid_entries'] );
		$this->assertSame( 4, $result['stats']['unique_users'] );
	}

	public function test_excluded_usernames_lose_all_their_entries(): void {
		$result = ec_studio_filter_giveaway_entries(
			$this->comments(),
			array( 'require_tag' => true ),
			array( 'HEAVY' )
		);

		$this->assertSame( array( 'c4', 'c6' ), array_column( $result['entries'], 'id' ) );
	}

	public function test_multiple_winners_are_always_distinct_people(): void {
		$entries = ec_studio_filter_giveaway_entries( $this->comments(), array( 'require_tag' => true ) )['entries'];

		for ( $i = 0; $i < 50; $i++ ) {
			$winners   = ec_studio_pick_giveaway_winners( $entries, 3 );
			$usernames = array_map( static fn( $w ) => strtolower( $w['author_username'] ), $winners );

			$this->assertCount( 3, $winners );
			$this->assertSame( $usernames, array_values( array_unique( $usernames ) ) );
		}
	}

	public function test_winner_count_is_capped_at_the_number_of_people(): void {
		$entries = ec_studio_filter_giveaway_entries( $this->comments(), array( 'require_tag' => true ) )['entries'];

		$this->assertCount( 3, ec_studio_pick_giveaway_winners( $entries, 10 ) );
		$this->assertSame( array(), ec_studio_pick_giveaway_winners( array(), 1 ) );
		$this->assertSame( array(), ec_studio_pick_giveaway_winners( $entries, 0 ) );
	}

	public function test_every_entry_has_equal_odds_so_more_comments_mean_more_wins(): void {
		$entries = ec_studio_filter_giveaway_entries( $this->comments(), array( 'require_tag' => true ) )['entries'];
		$wins    = array();

		for ( $i = 0; $i < 3000; $i++ ) {
			$winner            = ec_studio_pick_giveaway_winners( $entries, 1 )[0];
			$username          = strtolower( $winner['author_username'] );
			$wins[ $username ] = ( $wins[ $username ] ?? 0 ) + 1;
		}

		// heavy holds 4 of 6 entries (~2000 of 3000 draws); multi and late
		// hold 1 each (~500). Bounds are loose enough to never flake.
		$this->assertGreaterThan( 1700, $wins['heavy'] );
		$this->assertLessThan( 2300, $wins['heavy'] );
		$this->assertGreaterThan( 350, $wins['multi'] );
		$this->assertGreaterThan( 350, $wins['late'] );
	}
}
