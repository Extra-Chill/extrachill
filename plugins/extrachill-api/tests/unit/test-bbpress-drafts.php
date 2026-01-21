<?php
/**
 * Unit tests for bbPress Drafts functions.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_BBPress_Drafts extends TestCase {

	/**
	 * Reset user meta storage before each test.
	 */
	protected function setUp(): void {
		global $test_user_meta;
		$test_user_meta = array();
	}

	/**
	 * Test meta key returns expected string.
	 */
	public function test_meta_key_returns_expected_string() {
		$key = extrachill_api_bbpress_drafts_meta_key();

		$this->assertEquals( 'ec_bbpress_drafts', $key );
	}

	/**
	 * Test draft key format for topic type.
	 */
	public function test_draft_key_topic_format() {
		$context = array(
			'type'     => 'topic',
			'blog_id'  => 2,
			'forum_id' => 456,
		);

		$key = extrachill_api_bbpress_draft_key( $context );

		$this->assertEquals( 'topic:2:456', $key );
	}

	/**
	 * Test draft key format for reply type.
	 */
	public function test_draft_key_reply_format() {
		$context = array(
			'type'     => 'reply',
			'blog_id'  => 2,
			'topic_id' => 789,
			'reply_to' => 0,
		);

		$key = extrachill_api_bbpress_draft_key( $context );

		$this->assertEquals( 'reply:2:789:0', $key );
	}

	/**
	 * Test draft key for reply with reply_to set.
	 */
	public function test_draft_key_reply_with_reply_to() {
		$context = array(
			'type'     => 'reply',
			'blog_id'  => 2,
			'topic_id' => 100,
			'reply_to' => 50,
		);

		$key = extrachill_api_bbpress_draft_key( $context );

		$this->assertEquals( 'reply:2:100:50', $key );
	}

	/**
	 * Test draft key for unknown type returns fallback.
	 */
	public function test_draft_key_unknown_type() {
		$context = array(
			'type'    => 'something_else',
			'blog_id' => 5,
		);

		$key = extrachill_api_bbpress_draft_key( $context );

		$this->assertEquals( 'unknown:5', $key );
	}

	/**
	 * Test draft key uses current blog ID when not provided.
	 */
	public function test_draft_key_uses_current_blog_id() {
		$context = array(
			'type'     => 'topic',
			'forum_id' => 123,
		);

		$key = extrachill_api_bbpress_draft_key( $context );

		// Default blog ID is 1 from mock.
		$this->assertEquals( 'topic:1:123', $key );
	}

	/**
	 * Test get_all returns empty array for invalid user.
	 */
	public function test_drafts_get_all_empty_user() {
		$drafts = extrachill_api_bbpress_drafts_get_all( 0 );

		$this->assertIsArray( $drafts );
		$this->assertEmpty( $drafts );
	}

	/**
	 * Test get_all returns empty array for negative user.
	 */
	public function test_drafts_get_all_negative_user() {
		$drafts = extrachill_api_bbpress_drafts_get_all( -1 );

		$this->assertIsArray( $drafts );
		$this->assertEmpty( $drafts );
	}

	/**
	 * Test get_all returns empty array when no meta exists.
	 */
	public function test_drafts_get_all_no_meta() {
		$drafts = extrachill_api_bbpress_drafts_get_all( 1 );

		$this->assertIsArray( $drafts );
		$this->assertEmpty( $drafts );
	}

	/**
	 * Test set_all and get_all work together.
	 */
	public function test_drafts_set_and_get_all() {
		$user_id = 100;
		$data    = array(
			'topic:2:456' => array(
				'type'    => 'topic',
				'content' => 'Test content',
			),
		);

		$result = extrachill_api_bbpress_drafts_set_all( $user_id, $data );
		$this->assertTrue( $result );

		$retrieved = extrachill_api_bbpress_drafts_get_all( $user_id );
		$this->assertEquals( $data, $retrieved );
	}

	/**
	 * Test set_all returns false for invalid user.
	 */
	public function test_drafts_set_all_invalid_user() {
		$result = extrachill_api_bbpress_drafts_set_all( 0, array( 'test' => 'data' ) );

		$this->assertFalse( $result );
	}

	/**
	 * Test draft_get returns null when no draft exists.
	 */
	public function test_draft_get_returns_null_missing() {
		$context = array(
			'type'     => 'topic',
			'blog_id'  => 2,
			'forum_id' => 999,
		);

		$draft = extrachill_api_bbpress_draft_get( 1, $context );

		$this->assertNull( $draft );
	}

	/**
	 * Test draft_get returns draft when it exists.
	 */
	public function test_draft_get_returns_existing() {
		$user_id = 200;
		$context = array(
			'type'     => 'topic',
			'blog_id'  => 2,
			'forum_id' => 100,
		);

		// Store a draft manually.
		$key    = extrachill_api_bbpress_draft_key( $context );
		$drafts = array(
			$key => array(
				'type'    => 'topic',
				'content' => 'My draft content',
			),
		);
		extrachill_api_bbpress_drafts_set_all( $user_id, $drafts );

		$retrieved = extrachill_api_bbpress_draft_get( $user_id, $context );

		$this->assertIsArray( $retrieved );
		$this->assertEquals( 'My draft content', $retrieved['content'] );
	}

	/**
	 * Test draft_upsert creates new draft.
	 */
	public function test_draft_upsert_creates_new() {
		$user_id = 300;
		$draft   = array(
			'type'     => 'topic',
			'blog_id'  => 2,
			'forum_id' => 500,
			'title'    => 'Test Title',
			'content'  => 'Test Content',
		);

		$result = extrachill_api_bbpress_draft_upsert( $user_id, $draft );

		$this->assertIsArray( $result );
		$this->assertEquals( 'topic', $result['type'] );
		$this->assertEquals( 2, $result['blog_id'] );
		$this->assertEquals( 500, $result['forum_id'] );
		$this->assertEquals( 'Test Title', $result['title'] );
		$this->assertEquals( 'Test Content', $result['content'] );
		$this->assertArrayHasKey( 'updated_at', $result );

		// Verify it's persisted.
		$context   = array(
			'type'     => 'topic',
			'blog_id'  => 2,
			'forum_id' => 500,
		);
		$retrieved = extrachill_api_bbpress_draft_get( $user_id, $context );
		$this->assertEquals( 'Test Content', $retrieved['content'] );
	}

	/**
	 * Test draft_upsert updates existing draft.
	 */
	public function test_draft_upsert_updates_existing() {
		$user_id = 400;
		$context = array(
			'type'     => 'reply',
			'blog_id'  => 2,
			'topic_id' => 100,
			'reply_to' => 0,
		);

		// Create initial draft.
		$initial = array_merge( $context, array( 'content' => 'Initial content' ) );
		extrachill_api_bbpress_draft_upsert( $user_id, $initial );

		// Update draft.
		$updated = array_merge( $context, array( 'content' => 'Updated content' ) );
		extrachill_api_bbpress_draft_upsert( $user_id, $updated );

		// Verify only one draft exists with updated content.
		$drafts = extrachill_api_bbpress_drafts_get_all( $user_id );
		$this->assertCount( 1, $drafts );

		$retrieved = extrachill_api_bbpress_draft_get( $user_id, $context );
		$this->assertEquals( 'Updated content', $retrieved['content'] );
	}

	/**
	 * Test draft_delete removes existing draft.
	 */
	public function test_draft_delete_removes_existing() {
		$user_id = 500;
		$draft   = array(
			'type'     => 'topic',
			'blog_id'  => 2,
			'forum_id' => 200,
			'content'  => 'To be deleted',
		);
		$context = array(
			'type'     => 'topic',
			'blog_id'  => 2,
			'forum_id' => 200,
		);

		// Create draft.
		extrachill_api_bbpress_draft_upsert( $user_id, $draft );

		// Verify it exists.
		$before = extrachill_api_bbpress_draft_get( $user_id, $context );
		$this->assertNotNull( $before );

		// Delete it.
		$result = extrachill_api_bbpress_draft_delete( $user_id, $context );
		$this->assertTrue( $result );

		// Verify it's gone.
		$after = extrachill_api_bbpress_draft_get( $user_id, $context );
		$this->assertNull( $after );
	}

	/**
	 * Test draft_delete returns true for non-existent draft.
	 */
	public function test_draft_delete_nonexistent() {
		$context = array(
			'type'     => 'topic',
			'blog_id'  => 2,
			'forum_id' => 999,
		);

		$result = extrachill_api_bbpress_draft_delete( 600, $context );

		$this->assertTrue( $result );
	}
}
