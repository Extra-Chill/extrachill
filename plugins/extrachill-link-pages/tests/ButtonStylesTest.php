<?php

use PHPUnit\Framework\TestCase;

final class ButtonStylesTest extends TestCase {
	public function test_standalone_button_styles_regression_proof() {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/button-styles-smoke.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}
}
