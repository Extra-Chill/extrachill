<?php
/**
 * Unit tests for Activity Schema functions.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_Activity_Schema extends TestCase {

	/**
	 * Build a valid event for testing.
	 *
	 * @return array
	 */
	private function get_valid_event() {
		return array(
			'type'           => 'post_published',
			'blog_id'        => 1,
			'actor_id'       => 100,
			'primary_object' => array(
				'object_type' => 'post',
				'blog_id'     => 1,
				'id'          => '500',
			),
			'summary'        => 'Published a new post',
			'visibility'     => 'public',
		);
	}

	/**
	 * Test normalize returns valid structure for complete event.
	 */
	public function test_normalize_valid_event() {
		$event  = $this->get_valid_event();
		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'post_published', $result['type'] );
		$this->assertEquals( 1, $result['blog_id'] );
		$this->assertEquals( 100, $result['actor_id'] );
		$this->assertEquals( 'Published a new post', $result['summary'] );
		$this->assertEquals( 'public', $result['visibility'] );
		$this->assertArrayHasKey( 'created_at', $result );
		$this->assertArrayHasKey( 'primary', $result );
		$this->assertArrayHasKey( 'secondary', $result );
	}

	/**
	 * Test normalize returns error for non-array input.
	 */
	public function test_normalize_non_array_input() {
		$result = extrachill_api_activity_normalize_event( 'not an array' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_event', $result->get_error_code() );
		$this->assertStringContainsString( 'must be an array', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for missing type.
	 */
	public function test_normalize_missing_type() {
		$event = $this->get_valid_event();
		unset( $event['type'] );

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'type is required', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for empty type.
	 */
	public function test_normalize_empty_type() {
		$event         = $this->get_valid_event();
		$event['type'] = '';

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'type is required', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for missing blog_id.
	 */
	public function test_normalize_missing_blog_id() {
		$event = $this->get_valid_event();
		unset( $event['blog_id'] );

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'blog_id is required', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for zero blog_id.
	 */
	public function test_normalize_zero_blog_id() {
		$event            = $this->get_valid_event();
		$event['blog_id'] = 0;

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'blog_id is required', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for missing primary_object.
	 */
	public function test_normalize_missing_primary_object() {
		$event = $this->get_valid_event();
		unset( $event['primary_object'] );

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'primary_object is required', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for non-array primary_object.
	 */
	public function test_normalize_non_array_primary_object() {
		$event                   = $this->get_valid_event();
		$event['primary_object'] = 'not an array';

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'primary_object is required', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for incomplete primary_object.
	 */
	public function test_normalize_incomplete_primary_object() {
		$event                   = $this->get_valid_event();
		$event['primary_object'] = array(
			'object_type' => 'post',
			// Missing blog_id and id.
		);

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'primary_object.object_type, blog_id, and id are required', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for partial secondary_object.
	 */
	public function test_normalize_partial_secondary_object() {
		$event                     = $this->get_valid_event();
		$event['secondary_object'] = array(
			'object_type' => 'comment',
			// Missing blog_id and id.
		);

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'secondary_object must include', $result->get_error_message() );
	}

	/**
	 * Test normalize accepts valid secondary_object.
	 */
	public function test_normalize_valid_secondary_object() {
		$event                     = $this->get_valid_event();
		$event['secondary_object'] = array(
			'object_type' => 'comment',
			'blog_id'     => 2,
			'id'          => '999',
		);

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'comment', $result['secondary']['type'] );
		$this->assertEquals( 2, $result['secondary']['blog_id'] );
		$this->assertEquals( '999', $result['secondary']['id'] );
	}

	/**
	 * Test normalize returns error for missing summary.
	 */
	public function test_normalize_missing_summary() {
		$event = $this->get_valid_event();
		unset( $event['summary'] );

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'summary is required', $result->get_error_message() );
	}

	/**
	 * Test normalize returns error for empty summary.
	 */
	public function test_normalize_empty_summary() {
		$event            = $this->get_valid_event();
		$event['summary'] = '';

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'summary is required', $result->get_error_message() );
	}

	/**
	 * Test normalize strips HTML from summary.
	 */
	public function test_normalize_strips_html_summary() {
		$event            = $this->get_valid_event();
		$event['summary'] = 'Hello <b>World</b> with <em>emphasis</em>';

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Hello World with emphasis', $result['summary'] );
	}

	/**
	 * Test normalize returns error for invalid visibility.
	 */
	public function test_normalize_invalid_visibility() {
		$event               = $this->get_valid_event();
		$event['visibility'] = 'friends_only';

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'visibility must be private or public', $result->get_error_message() );
	}

	/**
	 * Test normalize defaults visibility to private.
	 */
	public function test_normalize_defaults_visibility() {
		$event = $this->get_valid_event();
		unset( $event['visibility'] );

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'private', $result['visibility'] );
	}

	/**
	 * Test normalize casts actor_id to int.
	 */
	public function test_normalize_casts_actor_id() {
		$event             = $this->get_valid_event();
		$event['actor_id'] = '42';

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['actor_id'] );
	}

	/**
	 * Test normalize accepts null actor_id.
	 */
	public function test_normalize_accepts_null_actor_id() {
		$event             = $this->get_valid_event();
		$event['actor_id'] = null;

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertNull( $result['actor_id'] );
	}

	/**
	 * Test normalize accepts actor_id = 0 as null.
	 */
	public function test_normalize_zero_actor_id_becomes_null() {
		$event             = $this->get_valid_event();
		$event['actor_id'] = 0;

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertNull( $result['actor_id'] );
	}

	/**
	 * Test normalize returns error when data is non-array.
	 */
	public function test_normalize_data_must_be_array() {
		$event         = $this->get_valid_event();
		$event['data'] = 'not an array';

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'data must be an object/array', $result->get_error_message() );
	}

	/**
	 * Test normalize accepts null data.
	 */
	public function test_normalize_accepts_null_data() {
		$event         = $this->get_valid_event();
		$event['data'] = null;

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertNull( $result['data'] );
	}

	/**
	 * Test normalize accepts array data.
	 */
	public function test_normalize_accepts_array_data() {
		$event         = $this->get_valid_event();
		$event['data'] = array( 'extra' => 'info' );

		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertEquals( array( 'extra' => 'info' ), $result['data'] );
	}

	/**
	 * Test normalize includes created_at timestamp.
	 */
	public function test_normalize_includes_created_at() {
		$event  = $this->get_valid_event();
		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['created_at'] );
	}

	/**
	 * Test normalize transforms primary_object keys.
	 */
	public function test_normalize_transforms_primary_object_keys() {
		$event  = $this->get_valid_event();
		$result = extrachill_api_activity_normalize_event( $event );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'type', $result['primary'] );
		$this->assertArrayHasKey( 'blog_id', $result['primary'] );
		$this->assertArrayHasKey( 'id', $result['primary'] );
		$this->assertArrayNotHasKey( 'object_type', $result['primary'] );
	}
}
