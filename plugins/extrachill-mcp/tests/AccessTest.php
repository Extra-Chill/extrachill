<?php
/**
 * Tests for the Extra Chill MCP access gate.
 *
 * Access::permission_callback() (used directly as the `permission_callback`
 * for `extrachill/ability-search` and `extrachill/ability-call`) and
 * Access::filter_substrate_call_permission() (used to widen the Agents API
 * substrate's `agents_ability_call_permission` filter for the local hop)
 * both reach the same decision: Access::is_authorized().
 *
 * This CI environment does not install extrachill-users, so
 * `function_exists( 'ec_feature_available' )` is false here. Tests that need
 * a rollout tier inject a resolver that follows extrachill-users' documented
 * ladder (see tier_resolver()); the remaining tests exercise the fallback
 * path (`access_studio`), which is the real behavior for a site running this
 * plugin without extrachill-users.
 *
 * The `register_feature_ceiling()` regression tests exist because a prior
 * PHPStan pass deleted the `is_string()` guard on this plugin's previous
 * capability filter, on the theory that a filter's own docblock makes the
 * type check redundant. `ec_feature_ceilings` is a third-party-hookable
 * filter feeding this plugin's own registration, so guard the return value,
 * never trust it.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Access;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group access
 */
class AccessTest extends WP_UnitTestCase {

	private Access $access;

	public function set_up(): void {
		parent::set_up();
		$this->access = new Access();
	}

	public function tear_down(): void {
		remove_all_filters( 'extrachill_mcp_is_authorized' );
		remove_all_filters( 'default_site_option_' . Access::TIER_OPTION );
		delete_site_option( Access::TIER_OPTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Rollout resolver following extrachill-users' `ec_feature_available()`
	 * ladder: public -> true, team -> admin or team member, admin -> admin.
	 */
	private function tier_resolver( string $tier ): callable {
		return static function ( string $feature, int $user_id ) use ( $tier ): bool {
			if ( Access::FEATURE !== $feature ) {
				return false;
			}

			switch ( $tier ) {
				case 'public':
					return true;
				case 'team':
					return $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'access_studio' ) );
				default:
					return $user_id > 0 && user_can( $user_id, 'manage_options' );
			}
		};
	}

	private function subscriber(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function team_member(): int {
		$user_id = $this->subscriber();
		get_user_by( 'id', $user_id )->add_cap( 'access_studio' );

		return $user_id;
	}

	// -----------------------------------------------------------------
	// Rollout tier.
	// -----------------------------------------------------------------

	public function test_non_team_signed_in_user_is_authorized_when_tier_is_public(): void {
		wp_set_current_user( $this->subscriber() );

		$access = new Access( $this->tier_resolver( 'public' ) );

		$this->assertTrue( $access->is_authorized() );
		$this->assertTrue( $access->permission_callback() );
	}

	public function test_signed_out_caller_is_denied_even_when_tier_is_public(): void {
		wp_set_current_user( 0 );

		$access = new Access( $this->tier_resolver( 'public' ) );

		$this->assertFalse( $access->is_authorized() );
		$this->assertFalse( $access->permission_callback() );
	}

	public function test_non_team_user_is_denied_when_tier_is_team(): void {
		wp_set_current_user( $this->subscriber() );

		$this->assertFalse( ( new Access( $this->tier_resolver( 'team' ) ) )->is_authorized() );
	}

	public function test_team_member_is_authorized_when_tier_is_team(): void {
		wp_set_current_user( $this->team_member() );

		$this->assertTrue( ( new Access( $this->tier_resolver( 'team' ) ) )->is_authorized() );
	}

	public function test_team_member_is_denied_when_tier_is_admin(): void {
		wp_set_current_user( $this->team_member() );

		$this->assertFalse( ( new Access( $this->tier_resolver( 'admin' ) ) )->is_authorized() );
	}

	// -----------------------------------------------------------------
	// Entitlement seam: extrachill_mcp_is_authorized.
	// -----------------------------------------------------------------

	public function test_filter_seam_can_deny_a_user_the_tier_admits(): void {
		$user_id = $this->subscriber();
		wp_set_current_user( $user_id );

		$seen = array();
		add_filter(
			'extrachill_mcp_is_authorized',
			static function ( $authorized, $user ) use ( &$seen ) {
				$seen = array( $authorized, $user );
				return false;
			},
			10,
			2
		);

		$this->assertFalse( ( new Access( $this->tier_resolver( 'public' ) ) )->is_authorized() );
		$this->assertTrue( $seen[0], 'The seam receives the rollout decision.' );
		$this->assertInstanceOf( WP_User::class, $seen[1] );
		$this->assertSame( $user_id, $seen[1]->ID, 'The seam receives the signed-in account.' );
	}

	public function test_filter_seam_can_admit_a_named_account_the_tier_refuses(): void {
		$user_id = $this->subscriber();
		wp_set_current_user( $user_id );

		add_filter(
			'extrachill_mcp_is_authorized',
			static fn( $authorized, $user ) => $authorized || $user_id === $user->ID,
			10,
			2
		);

		$this->assertTrue( ( new Access( $this->tier_resolver( 'team' ) ) )->is_authorized() );
	}

	public function test_filter_seam_cannot_admit_a_signed_out_caller(): void {
		wp_set_current_user( 0 );
		add_filter( 'extrachill_mcp_is_authorized', '__return_true' );

		$this->assertFalse( ( new Access( $this->tier_resolver( 'public' ) ) )->is_authorized() );
	}

	public function test_filter_seam_only_admits_on_strict_true(): void {
		wp_set_current_user( $this->subscriber() );
		add_filter( 'extrachill_mcp_is_authorized', static fn() => 'yes' );

		$this->assertFalse( ( new Access( $this->tier_resolver( 'public' ) ) )->is_authorized() );
	}

	public function test_filter_seam_also_governs_the_substrate_call_hop(): void {
		wp_set_current_user( $this->subscriber() );
		add_filter( 'extrachill_mcp_is_authorized', '__return_false' );

		// core/get-user-info is a core, REST-visible ability, so exposure is
		// not what denies here: the seam is.
		$this->assertFalse(
			( new Access( $this->tier_resolver( 'public' ) ) )->filter_substrate_call_permission( false, array( 'name' => 'core/get-user-info' ) )
		);
	}

	// -----------------------------------------------------------------
	// Fallback path (no extrachill-users).
	// -----------------------------------------------------------------

	public function test_team_member_reaches_wrapper_abilities_without_extrachill_users(): void {
		wp_set_current_user( $this->team_member() );

		$this->assertTrue( $this->access->permission_callback() );
	}

	public function test_non_team_user_is_denied_without_extrachill_users(): void {
		wp_set_current_user( $this->subscriber() );

		$this->assertFalse( $this->access->permission_callback() );
	}

	public function test_anonymous_caller_is_denied(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->access->permission_callback() );
	}

	// -----------------------------------------------------------------
	// Registration.
	// -----------------------------------------------------------------

	public function test_substrate_search_permission_is_not_widened(): void {
		$access = new Access();
		$access->register();

		$this->assertFalse(
			has_filter( 'agents_ability_search_permission', array( $access, 'filter_substrate_permission' ) ),
			'agents/ability-search lists hidden primitives; it must not be widened to the MCP audience.'
		);
		$this->assertSame( 10, has_filter( 'agents_ability_call_permission', array( $access, 'filter_substrate_call_permission' ) ) );

		remove_filter( 'agents_ability_call_permission', array( $access, 'filter_substrate_call_permission' ), 10 );
		remove_filter( 'ec_feature_ceilings', array( $access, 'register_feature_ceiling' ) );
	}

	public function test_unset_tier_option_defaults_to_team_not_the_public_ceiling(): void {
		delete_site_option( Access::TIER_OPTION );
		$access = new Access();
		$access->register();

		// extrachill-users reads the live tier as get_site_option( $key, $ceiling ).
		$this->assertSame( 'team', get_site_option( Access::TIER_OPTION, Access::CEILING ) );

		remove_filter( 'agents_ability_call_permission', array( $access, 'filter_substrate_call_permission' ), 10 );
		remove_filter( 'ec_feature_ceilings', array( $access, 'register_feature_ceiling' ) );
	}

	public function test_stored_tier_option_wins_over_the_default(): void {
		$access = new Access();
		$access->register();
		update_site_option( Access::TIER_OPTION, 'public' );

		$this->assertSame( 'public', get_site_option( Access::TIER_OPTION, Access::CEILING ) );

		remove_filter( 'agents_ability_call_permission', array( $access, 'filter_substrate_call_permission' ), 10 );
		remove_filter( 'ec_feature_ceilings', array( $access, 'register_feature_ceiling' ) );
	}

	public function test_register_feature_ceiling_sets_public_tier(): void {
		$ceilings = $this->access->register_feature_ceiling( array() );

		$this->assertSame( 'public', $ceilings['extrachill_mcp'] );
	}

	public function test_register_feature_ceiling_preserves_other_registered_ceilings(): void {
		$ceilings = $this->access->register_feature_ceiling( array( 'shop' => 'admin' ) );

		$this->assertSame( 'admin', $ceilings['shop'] );
		$this->assertSame( 'public', $ceilings['extrachill_mcp'] );
	}

	/**
	 * @dataProvider malformed_ceilings_filter_provider
	 *
	 * @param mixed $malformed_ceilings Value a misbehaving filter might return.
	 */
	public function test_register_feature_ceiling_recovers_from_a_malformed_upstream_value( $malformed_ceilings ): void {
		$ceilings = $this->access->register_feature_ceiling( $malformed_ceilings );

		$this->assertIsArray( $ceilings );
		$this->assertSame( 'public', $ceilings['extrachill_mcp'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function malformed_ceilings_filter_provider(): array {
		return array(
			'null'   => array( null ),
			'false'  => array( false ),
			'string' => array( 'not-an-array' ),
		);
	}
}
