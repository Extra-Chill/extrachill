<?php

require_once __DIR__ . '/support/base-test-case.php';

final class PluginSourceOutputTest extends EC_Artist_Platform_TestCase {
	public function test_roster_ui_source_does_not_emit_output_when_loaded(): void {
		ob_start();
		include dirname( __DIR__ ) . '/inc/artist-profiles/roster/manage-roster-ui.php';
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
