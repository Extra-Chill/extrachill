<?php
/**
 * Tests for the Extra Chill MCP access gate.
 *
 * Access::can_use() is the authorization decision reached on every call to
 * `agents/ability-search` and `agents/ability-call`. The regression tests
 * below exist because a prior PHPStan pass deleted the `is_string()` guard
 * on the `extrachill_mcp_capability` filter's return value, on the theory
 * that the filter's own docblock (`@param string $capability`) makes the
 * type check redundant. A docblock documents the contract a well-behaved
 * filter should honor; it does not enforce it. Any third-party plugin can
 * hook `extrachill_mcp_capability` and return anything, and
 * `current_user_can()` throws a TypeError on null/array input on WP 7.1.
 * Without the guard, a misbehaving filter fatals a public REST endpoint
 * instead of cleanly denying access.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group access
 */
class AccessTest extends WP_UnitTestCase {

	private \ExtraChillMcp\Access $access;

	public function set_up(): void {
		parent::set_up();
		$this->access = new \ExtraChillMcp\Access();
	}

	public function tear_down(): void {
		remove_all_filters( 'extrachill_mcp_capability' );
		parent::tear_down();
	}

	public function test_upstream_true_short_circuits_to_allowed(): void {
		wp_set_current_user( 0 );

		$this->assertTrue( $this->access->can_use( true ) );
	}

	public function test_user_holding_default_capability_is_allowed(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_roadie' );
		wp_set_current_user( $user_id );

		$this->assertTrue( $this->access->can_use( false ) );
	}

	public function test_user_without_default_capability_is_denied(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( $this->access->can_use( false ) );
	}

	public function test_anonymous_caller_is_denied(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->access->can_use( false ) );
	}

	/**
	 * @dataProvider malformed_capability_filter_provider
	 *
	 * @param mixed $malformed_capability Value a misbehaving filter might return.
	 */
	public function test_malformed_capability_filter_denies_cleanly_without_throwing( $malformed_capability ): void {
		// Use an administrator to prove the denial comes from the guard on
		// the filter's return value, not from the user's own permissions.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		add_filter(
			'extrachill_mcp_capability',
			static function () use ( $malformed_capability ) {
				return $malformed_capability;
			}
		);

		$result = $this->access->can_use( false );

		$this->assertFalse( $result );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function malformed_capability_filter_provider(): array {
		return array(
			'null'         => array( null ),
			'empty array'  => array( array() ),
			'false'        => array( false ),
			'empty string' => array( '' ),
		);
	}
}
