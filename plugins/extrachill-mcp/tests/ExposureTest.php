<?php
/**
 * Tests for the MCP exposure contract.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Abilities\Exposure;
use ExtraChillMcp\Abilities\NetworkAbilityCall;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 */
class ExposureTest extends WP_UnitTestCase {

	private const HIDDEN = 'extrachill-mcp-test/hidden-primitive';
	private const REST   = 'extrachill-mcp-test/rest-visible';
	private const MCP    = 'extrachill-mcp-test/mcp-public';

	/** @var bool Set when the hidden primitive's callback runs. */
	public static bool $hidden_ran = false;

	public function set_up(): void {
		parent::set_up();
		self::$hidden_ran = false;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable.' );
		}

		if ( ! wp_has_ability_category( 'extrachill-mcp-test' ) ) {
			remove_all_actions( 'wp_abilities_api_categories_init' );
			add_action(
				'wp_abilities_api_categories_init',
				static function (): void {
					wp_register_ability_category(
						'extrachill-mcp-test',
						array(
							'label'       => 'Test',
							'description' => 'Test category.',
						)
					);
				}
			);
			do_action( 'wp_abilities_api_categories_init' );
		}

		remove_all_actions( 'wp_abilities_api_init' );
		add_action(
			'wp_abilities_api_init',
			static function (): void {
				// The registry fires this action itself on first access, so
				// the explicit do_action() below may be the second run.
				if ( wp_has_ability( self::HIDDEN ) ) {
					return;
				}

				$base = array(
					'label'               => 'Test',
					'description'         => 'Test ability.',
					'category'            => 'extrachill-mcp-test',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => '__return_true',
				);

				wp_register_ability(
					self::HIDDEN,
					$base + array(
						'execute_callback' => static function (): array {
							self::$hidden_ran = true;
							return array( 'ran' => true );
						},
						'meta'             => array( 'show_in_rest' => false ),
					)
				);
				wp_register_ability(
					self::REST,
					$base + array(
						'execute_callback' => static fn(): array => array(),
						'meta'             => array( 'show_in_rest' => true ),
					)
				);
				wp_register_ability(
					self::MCP,
					$base + array(
						'execute_callback' => static fn(): array => array(),
						'meta'             => array(
							'show_in_rest' => false,
							'mcp'          => array( 'public' => true ),
						),
					)
				);
			}
		);
		do_action( 'wp_abilities_api_init' );
	}

	public function tear_down(): void {
		foreach ( array( self::HIDDEN, self::REST, self::MCP ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		parent::tear_down();
	}

	public function test_meta_contract(): void {
		$this->assertTrue( Exposure::meta_is_exposed( array( 'show_in_rest' => true ) ) );
		$this->assertTrue( Exposure::meta_is_exposed( array( 'mcp' => array( 'public' => true ) ) ) );
		$this->assertFalse( Exposure::meta_is_exposed( array( 'show_in_rest' => false ) ) );
		$this->assertFalse( Exposure::meta_is_exposed( array() ) );
		$this->assertFalse( Exposure::meta_is_exposed( array( 'mcp' => array( 'public' => 'yes' ) ) ) );
	}

	public function test_local_exposure_reads_registered_meta(): void {
		$this->assertFalse( Exposure::is_locally_exposed( self::HIDDEN ) );
		$this->assertTrue( Exposure::is_locally_exposed( self::REST ) );
		$this->assertTrue( Exposure::is_locally_exposed( self::MCP ) );
		$this->assertTrue( Exposure::is_locally_exposed( 'extrachill-mcp-test/not-registered' ) );
	}

	public function test_ability_call_refuses_hidden_primitive_without_running_it(): void {
		// No network stub: this must not define the network globals, which
		// NetworkAbilityAbsenceTest asserts are absent. With no network the
		// owner resolves to null and the call takes the local hop.
		$call   = new NetworkAbilityCall( new \ExtraChillMcp\Access() );
		$result = $call->execute( array( 'name' => self::HIDDEN ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'extrachill_mcp_ability_not_exposed', $result->get_error_code() );
		$this->assertFalse( self::$hidden_ran );
	}

	public function test_ability_call_lets_exposed_abilities_reach_the_local_hop(): void {
		// No network stub: this must not define the network globals, which
		// NetworkAbilityAbsenceTest asserts are absent. With no network the
		// owner resolves to null and the call takes the local hop.
		$call = new NetworkAbilityCall( new \ExtraChillMcp\Access() );

		foreach ( array( self::REST, self::MCP ) as $name ) {
			$result = $call->execute( array( 'name' => $name ) );
			// The substrate's agents/ability-call is absent in this isolated
			// environment, so reaching the hop surfaces as ability_not_found.
			$this->assertNotSame(
				'extrachill_mcp_ability_not_exposed',
				is_wp_error( $result ) ? $result->get_error_code() : '',
				$name
			);
		}
	}

	public function test_substrate_call_filter_only_widens_for_exposed_abilities(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( 'access_studio' );
		wp_set_current_user( $user_id );

		$access = new \ExtraChillMcp\Access();

		// agents/ability-call is REST-visible, so the substrate filter is a
		// second route in; it must not reach internal primitives.
		$this->assertFalse( $access->filter_substrate_call_permission( false, array( 'name' => self::HIDDEN ) ) );
		$this->assertFalse( $access->filter_substrate_call_permission( false, array() ) );
		$this->assertTrue( $access->filter_substrate_call_permission( false, array( 'name' => self::REST ) ) );
		$this->assertTrue( $access->filter_substrate_call_permission( false, array( 'name' => self::MCP ) ) );
		$this->assertTrue( $access->filter_substrate_call_permission( true, array( 'name' => self::HIDDEN ) ) );

		wp_set_current_user( 0 );
	}

	public function test_substrate_call_filter_denies_non_team_even_for_exposed_abilities(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( ( new \ExtraChillMcp\Access() )->filter_substrate_call_permission( false, array( 'name' => self::REST ) ) );

		wp_set_current_user( 0 );
	}
}
