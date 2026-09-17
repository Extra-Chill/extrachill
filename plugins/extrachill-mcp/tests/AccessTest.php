<?php
/**
 * Tests for the Extra Chill MCP access gate.
 *
 * Access::permission_callback() (used directly as the `permission_callback`
 * for `extrachill/ability-search` and `extrachill/ability-call`) and
 * Access::filter_substrate_permission() (used to widen the Agents API
 * substrate's own `agents_ability_search_permission` /
 * `agents_ability_call_permission` filters for the local hop) both reach the
 * same decision. This suite exercises that decision directly.
 *
 * This CI environment does not install extrachill-users, so
 * `function_exists( 'ec_feature_available' )` is false here and every
 * assertion below exercises the fallback path (`access_roadie`) — which is
 * itself the real, production behavior for any Extra Chill site running
 * this plugin without extrachill-users active, not merely a test double.
 *
 * The `register_feature_ceiling()` regression tests exist because a prior
 * PHPStan pass deleted the `is_string()` guard on this plugin's previous
 * capability filter, on the theory that a filter's own docblock makes the
 * type check redundant. A docblock documents the contract a well-behaved
 * filter should honor; it does not enforce it. `ec_feature_ceilings` is a
 * third-party-hookable filter feeding this plugin's own registration, so the
 * same discipline applies: guard the return value, never trust it.
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

	public function test_permission_callback_upstream_true_short_circuits_search_substrate_filter(): void {
		wp_set_current_user( 0 );

		$this->assertTrue( $this->access->filter_substrate_permission( true ) );
	}

	public function test_team_member_reaches_wrapper_abilities(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_roadie' );
		wp_set_current_user( $user_id );

		$this->assertTrue( $this->access->permission_callback() );
	}

	public function test_team_member_widens_the_substrate_filters_the_local_hop_depends_on(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_roadie' );
		wp_set_current_user( $user_id );

		// false is the substrate's own upstream decision (current_user_can('manage_options')),
		// which a non-admin team member fails — this filter is what has to widen it.
		$this->assertTrue( $this->access->filter_substrate_permission( false ) );
	}

	public function test_non_team_user_is_denied_the_wrapper_abilities(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( $this->access->permission_callback() );
	}

	public function test_non_team_user_does_not_widen_the_substrate_filters(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( $this->access->filter_substrate_permission( false ) );
	}

	public function test_anonymous_caller_is_denied(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->access->permission_callback() );
	}

	public function test_register_feature_ceiling_sets_team_tier(): void {
		$ceilings = $this->access->register_feature_ceiling( array() );

		$this->assertSame( 'team', $ceilings['extrachill_mcp'] );
	}

	public function test_register_feature_ceiling_preserves_other_registered_ceilings(): void {
		$ceilings = $this->access->register_feature_ceiling( array( 'shop' => 'admin' ) );

		$this->assertSame( 'admin', $ceilings['shop'] );
		$this->assertSame( 'team', $ceilings['extrachill_mcp'] );
	}

	/**
	 * @dataProvider malformed_ceilings_filter_provider
	 *
	 * @param mixed $malformed_ceilings Value a misbehaving filter might return.
	 */
	public function test_register_feature_ceiling_recovers_from_a_malformed_upstream_value( $malformed_ceilings ): void {
		$ceilings = $this->access->register_feature_ceiling( $malformed_ceilings );

		$this->assertIsArray( $ceilings );
		$this->assertSame( 'team', $ceilings['extrachill_mcp'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function malformed_ceilings_filter_provider(): array {
		return array(
			'null'         => array( null ),
			'false'        => array( false ),
			'string'       => array( 'not-an-array' ),
		);
	}
}
