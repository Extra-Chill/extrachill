<?php
/**
 * Data Machine Events ability capability bridge tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- File-level WP double for the capability check.

use ExtraChillEvents\Core\TeamAuthorization;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Resolve a capability against the current test role.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function current_user_can( $capability ) {
		$roles = array(
			'extra_chill_team' => array( 'read', 'edit_posts', TeamAuthorization::ACCESS_CAPABILITY ),
			'subscriber'       => array( 'read' ),
		);
		$role  = $GLOBALS['ec_dme_capability_test_role'] ?? '';

		return in_array( $capability, $roles[ $role ] ?? array(), true );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/data-machine-events/ability-capabilities.php';

/** Team members must pass the DME ability gates; subscribers must not. */
final class DataMachineEventsAbilityCapabilitiesTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['ec_dme_capability_test_role'] );
	}

	/**
	 * Mirror of DataMachineEvents\Abilities\AbilityPermissions::canWrite() outside WP-CLI.
	 */
	private function dme_can_write(): bool {
		return current_user_can( (string) apply_filters( 'datamachine_events_write_capability', 'edit_others_posts' ) );
	}

	/**
	 * Mirror of DataMachineEvents\Abilities\AbilityPermissions::canRead() outside WP-CLI.
	 */
	private function dme_can_read(): bool {
		return current_user_can( (string) apply_filters( 'datamachine_events_read_capability', 'edit_posts' ) );
	}

	public function test_dme_capability_filters_resolve_to_events_team_capability(): void {
		$this->assertSame( 'access_events_admin', TeamAuthorization::ACCESS_CAPABILITY );
		$this->assertSame( TeamAuthorization::ACCESS_CAPABILITY, apply_filters( 'datamachine_events_write_capability', 'edit_others_posts' ) );
		$this->assertSame( TeamAuthorization::ACCESS_CAPABILITY, apply_filters( 'datamachine_events_read_capability', 'edit_posts' ) );
	}

	public function test_team_member_passes_dme_ability_gates(): void {
		$GLOBALS['ec_dme_capability_test_role'] = 'extra_chill_team';

		$this->assertFalse( current_user_can( 'edit_others_posts' ) );
		$this->assertTrue( $this->dme_can_write() );
		$this->assertTrue( $this->dme_can_read() );
	}

	public function test_subscriber_fails_dme_ability_gates(): void {
		$GLOBALS['ec_dme_capability_test_role'] = 'subscriber';

		$this->assertFalse( $this->dme_can_write() );
		$this->assertFalse( $this->dme_can_read() );
	}
}
