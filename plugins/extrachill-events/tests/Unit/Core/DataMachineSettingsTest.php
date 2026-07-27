<?php
/** Events-owned Data Machine settings contract tests. */

namespace ExtraChillEvents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/core/datamachine-settings.php';

class DataMachineSettingsTest extends TestCase {
	public function test_handles_owned_boolean_settings_without_disturbing_existing_values(): void {
		$result = extrachill_events_filter_datamachine_settings(
			array(
				'settings'     => array( 'existing' => 'value' ),
				'handled_keys' => array( 'existing' ),
			),
			array(
				'dme_qualify_digest_enabled'             => false,
				'extrachill_local_scene_digest_enabled' => true,
				'unrelated'                              => true,
			)
		);

		$this->assertSame( 'value', $result['settings']['existing'] );
		$this->assertFalse( $result['settings']['dme_qualify_digest_enabled'] );
		$this->assertTrue( $result['settings']['extrachill_local_scene_digest_enabled'] );
		$this->assertSame(
			array( 'existing', 'dme_qualify_digest_enabled', 'extrachill_local_scene_digest_enabled' ),
			$result['handled_keys']
		);
		$this->assertArrayNotHasKey( 'unrelated', $result['settings'] );
	}

	public function test_leaves_non_boolean_owned_values_unhandled(): void {
		$result = extrachill_events_filter_datamachine_settings(
			array(
				'settings'     => array(),
				'handled_keys' => array(),
			),
			array( 'extrachill_local_scene_digest_enabled' => 'true' )
		);

		$this->assertSame( array(), $result['settings'] );
		$this->assertSame( array(), $result['handled_keys'] );
	}
}
