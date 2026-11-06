<?php

use PHPUnit\Framework\TestCase;

final class ButtonStylesTest extends TestCase {
	public function test_standalone_button_styles_regression_proof() {
		ec_test_reset();
		ob_start();
		try {
			require __DIR__ . '/button-styles-smoke.php';
			$this->assertStringContainsString( 'Button styles smoke passed', ob_get_contents() );
		} finally {
			ob_end_clean();
		}
	}
}
