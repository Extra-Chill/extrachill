<?php
/**
 * End-to-end authorization for a non-team account calling through
 * `extrachill/ability-call` at the public rollout tier.
 *
 * Opening the MCP to every signed-in account is only safe because the
 * TARGET ability's own `permission_callback` still runs, as that account,
 * on every call. This suite proves it on the real call chain:
 *
 *   extrachill/ability-call (Access gate)
 *     -> agents/ability-call (substrate gate, widened by Access for exposed abilities)
 *       -> target ability (its own permission_callback, as the caller)
 *
 * The Agents API substrate is not installed in this isolated environment,
 * so a stand-in `agents/ability-call` with the substrate's own contract is
 * registered: permission through `agents_ability_call_permission`
 * (default `manage_options`) and dispatch through `WP_Ability::execute()`.
 * If the real substrate is present it is used instead.
 *
 * No network stub is installed: NetworkAbilityAbsenceTest asserts the
 * network globals stay undefined. With no network the owner resolves to
 * null and the call takes the local hop.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Abilities\CategoryRegistration;
use ExtraChillMcp\Abilities\NetworkAbilityCall;
use ExtraChillMcp\Access;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group access
 */
class NonTeamAbilityCallTest extends WP_UnitTestCase {

	private const CATEGORY  = 'extrachill-mcp-test';
	private const SUBSTRATE = 'agents/ability-call';
	private const OWN_EVENT = 'extrachill-mcp-test/manage-own-event';
	private const META_CALL = 'extrachill/ability-call';

	/** @var int[] Users allowed to manage the test event. */
	public static array $event_managers = array();

	/** @var int Times the target's execute callback ran. */
	public static int $target_runs = 0;

	private bool $registered_substrate = false;

	private Access $access;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable.' );
		}

		self::$event_managers = array();
		self::$target_runs    = 0;

		// Public tier, resolved the way extrachill-users does for `public`.
		$this->access = new Access( static fn(): bool => true );
		$this->access->register();

		$this->register_categories();
		$this->register_abilities();
	}

	public function tear_down(): void {
		foreach ( array( self::OWN_EVENT, self::META_CALL ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		// Other suites assert the substrate is absent; only remove our stand-in.
		if ( $this->registered_substrate && wp_has_ability( self::SUBSTRATE ) ) {
			wp_unregister_ability( self::SUBSTRATE );
		}

		remove_filter( 'agents_ability_call_permission', array( $this->access, 'filter_substrate_call_permission' ), 10 );
		remove_filter( 'ec_feature_ceilings', array( $this->access, 'register_feature_ceiling' ) );
		remove_all_filters( 'default_site_option_' . Access::TIER_OPTION );
		remove_all_filters( 'extrachill_mcp_is_authorized' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function register_categories(): void {
		remove_all_actions( 'wp_abilities_api_categories_init' );
		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( self::CATEGORY ) ) {
					wp_register_ability_category(
						self::CATEGORY,
						array(
							'label'       => 'Test',
							'description' => 'Test category.',
						)
					);
				}
			}
		);
		add_action( 'wp_abilities_api_categories_init', array( new CategoryRegistration(), 'register_category' ) );
		do_action( 'wp_abilities_api_categories_init' );
	}

	private function register_abilities(): void {
		$this->registered_substrate = ! wp_has_ability( self::SUBSTRATE );
		$register_substrate         = $this->registered_substrate;
		$meta_call                  = new NetworkAbilityCall( $this->access );

		remove_all_actions( 'wp_abilities_api_init' );
		add_action(
			'wp_abilities_api_init',
			static function () use ( $register_substrate, $meta_call ): void {
				if ( wp_has_ability( self::OWN_EVENT ) ) {
					return;
				}

				// A manager-scoped ability shaped like the events plugin's
				// door-list/perk abilities: REST-visible, and authorized per
				// call against the caller and the input.
				wp_register_ability(
					self::OWN_EVENT,
					array(
						'label'               => 'Manage Own Event',
						'description'         => 'Test ability authorized per event.',
						'category'            => self::CATEGORY,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array( 'event_id' => array( 'type' => 'integer' ) ),
						),
						'output_schema'       => array( 'type' => 'object' ),
						'permission_callback' => static function (): bool {
							return in_array( get_current_user_id(), self::$event_managers, true );
						},
						'execute_callback'    => static function ( array $input = array() ): array {
							++self::$target_runs;
							return array( 'managed_event' => (int) ( $input['event_id'] ?? 0 ) );
						},
						'meta'                => array( 'show_in_rest' => true ),
					)
				);

				if ( $register_substrate ) {
					wp_register_ability(
						self::SUBSTRATE,
						array(
							'label'               => 'Call Ability',
							'description'         => 'Stand-in for the Agents API substrate.',
							'category'            => self::CATEGORY,
							'input_schema'        => array( 'type' => 'object' ),
							'output_schema'       => array( 'type' => 'object' ),
							'permission_callback' => static function ( array $input = array() ): bool {
								return (bool) apply_filters( 'agents_ability_call_permission', current_user_can( 'manage_options' ), $input );
							},
							'execute_callback'    => static function ( array $input = array() ) {
								$name   = (string) ( $input['name'] ?? '' );
								$target = wp_get_ability( $name );
								if ( null === $target ) {
									return new WP_Error( 'agents_ability_call_not_found', 'Ability is not registered.' );
								}
								$result = $target->execute( is_array( $input['parameters'] ?? null ) ? $input['parameters'] : array() );
								if ( is_wp_error( $result ) ) {
									return $result;
								}
								return array(
									'name'   => $name,
									'result' => $result,
								);
							},
							'meta'                => array( 'show_in_rest' => true ),
						)
					);
				}

				$meta_call->register_ability();
			}
		);
		do_action( 'wp_abilities_api_init' );
	}

	private function call_as( int $user_id, int $event_id ) {
		wp_set_current_user( $user_id );

		return wp_get_ability( self::META_CALL )->execute(
			array(
				'name'       => self::OWN_EVENT,
				'parameters' => array( 'event_id' => $event_id ),
			)
		);
	}

	public function test_non_team_manager_can_run_an_ability_they_are_permitted_to_run(): void {
		$manager                = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::$event_managers[] = $manager;

		$result = $this->call_as( $manager, 42 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( array( 'managed_event' => 42 ), $result['result'] );
		$this->assertSame( 1, self::$target_runs );
	}

	public function test_non_team_account_is_still_denied_by_the_target_permission(): void {
		self::$event_managers[] = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$stranger               = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->call_as( $stranger, 42 );

		$this->assertInstanceOf( WP_Error::class, $result );
		// WP_Ability::execute() rejects on the target's own permission check.
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 0, self::$target_runs, 'The target must not run for an account it does not authorize.' );
	}

	public function test_signed_out_caller_never_reaches_the_target(): void {
		self::$event_managers[] = 0;

		$result = $this->call_as( 0, 42 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, self::$target_runs );
	}

	public function test_entitlement_seam_denial_stops_the_call_before_the_target(): void {
		$manager                = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::$event_managers[] = $manager;
		add_filter( 'extrachill_mcp_is_authorized', '__return_false' );

		$result = $this->call_as( $manager, 42 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, self::$target_runs );
	}
}
