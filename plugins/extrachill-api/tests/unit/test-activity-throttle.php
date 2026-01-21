<?php
/**
 * Unit tests for Activity Throttle functions.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_Activity_Throttle extends TestCase {

	/**
	 * Reset transients before each test.
	 */
	protected function setUp(): void {
		global $__test_transients;
		$__test_transients = array();
	}

	/**
	 * Build a throttleable event.
	 *
	 * @return array
	 */
	private function get_throttleable_event() {
		return array(
			'type'     => 'post_updated',
			'actor_id' => 100,
			'primary'  => array(
				'blog_id' => 1,
				'id'      => '500',
			),
		);
	}

	/**
	 * Test should_throttle returns false when no actor.
	 */
	public function test_should_throttle_no_actor() {
		$event = $this->get_throttleable_event();
		unset( $event['actor_id'] );

		$result = extrachill_api_activity_should_throttle( $event );

		$this->assertFalse( $result );
	}

	/**
	 * Test should_throttle returns false when actor_id is null.
	 */
	public function test_should_throttle_null_actor() {
		$event             = $this->get_throttleable_event();
		$event['actor_id'] = null;

		$result = extrachill_api_activity_should_throttle( $event );

		$this->assertFalse( $result );
	}

	/**
	 * Test should_throttle returns false for non-configured type.
	 */
	public function test_should_throttle_non_configured_type() {
		$event         = $this->get_throttleable_event();
		$event['type'] = 'comment_posted';

		$result = extrachill_api_activity_should_throttle( $event );

		$this->assertFalse( $result );
	}

	/**
	 * Test should_throttle returns false for first event.
	 */
	public function test_should_throttle_first_event() {
		$event = $this->get_throttleable_event();

		$result = extrachill_api_activity_should_throttle( $event );

		$this->assertFalse( $result );
	}

	/**
	 * Test should_throttle returns true for duplicate event.
	 */
	public function test_should_throttle_duplicate() {
		$event = $this->get_throttleable_event();

		// Mark as emitted first.
		extrachill_api_activity_mark_emitted( $event );

		// Now it should be throttled.
		$result = extrachill_api_activity_should_throttle( $event );

		$this->assertTrue( $result );
	}

	/**
	 * Test different type is not throttled.
	 */
	public function test_should_throttle_different_type() {
		$event1 = $this->get_throttleable_event();
		extrachill_api_activity_mark_emitted( $event1 );

		$event2         = $this->get_throttleable_event();
		$event2['type'] = 'different_type';

		$result = extrachill_api_activity_should_throttle( $event2 );

		$this->assertFalse( $result );
	}

	/**
	 * Test different actor is not throttled.
	 */
	public function test_should_throttle_different_actor() {
		$event1 = $this->get_throttleable_event();
		extrachill_api_activity_mark_emitted( $event1 );

		$event2             = $this->get_throttleable_event();
		$event2['actor_id'] = 200;

		$result = extrachill_api_activity_should_throttle( $event2 );

		$this->assertFalse( $result );
	}

	/**
	 * Test different object is not throttled.
	 */
	public function test_should_throttle_different_object() {
		$event1 = $this->get_throttleable_event();
		extrachill_api_activity_mark_emitted( $event1 );

		$event2                   = $this->get_throttleable_event();
		$event2['primary']['id'] = '999';

		$result = extrachill_api_activity_should_throttle( $event2 );

		$this->assertFalse( $result );
	}

	/**
	 * Test throttle_key is deterministic.
	 */
	public function test_throttle_key_deterministic() {
		$event = $this->get_throttleable_event();

		$key1 = extrachill_api_activity_throttle_key( $event );
		$key2 = extrachill_api_activity_throttle_key( $event );

		$this->assertEquals( $key1, $key2 );
	}

	/**
	 * Test throttle_key includes all components.
	 */
	public function test_throttle_key_format() {
		$event = array(
			'type'     => 'post_updated',
			'actor_id' => 42,
			'primary'  => array(
				'blog_id' => 5,
				'id'      => '123',
			),
		);

		$key = extrachill_api_activity_throttle_key( $event );

		$this->assertEquals( 'ec_activity_throttle_42_post_updated_5_123', $key );
	}

	/**
	 * Test mark_emitted sets transient.
	 */
	public function test_mark_emitted_sets_transient() {
		global $__test_transients;

		$event = $this->get_throttleable_event();
		$key   = extrachill_api_activity_throttle_key( $event );

		// Before marking.
		$this->assertArrayNotHasKey( $key, $__test_transients );

		// Mark as emitted.
		extrachill_api_activity_mark_emitted( $event );

		// After marking.
		$this->assertArrayHasKey( $key, $__test_transients );
		$this->assertEquals( 1, $__test_transients[ $key ]['value'] );
	}

	/**
	 * Test mark_emitted does nothing for non-configured type.
	 */
	public function test_mark_emitted_non_configured_type() {
		global $__test_transients;

		$event         = $this->get_throttleable_event();
		$event['type'] = 'not_in_rules';

		extrachill_api_activity_mark_emitted( $event );

		$this->assertEmpty( $__test_transients );
	}

	/**
	 * Test mark_emitted does nothing when no actor.
	 */
	public function test_mark_emitted_no_actor() {
		global $__test_transients;

		$event = $this->get_throttleable_event();
		unset( $event['actor_id'] );

		extrachill_api_activity_mark_emitted( $event );

		$this->assertEmpty( $__test_transients );
	}
}
