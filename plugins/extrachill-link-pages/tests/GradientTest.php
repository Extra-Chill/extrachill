<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/gradient-smoke.php';

final class GradientTest extends TestCase {
	public function test_gradient_sanitization_and_public_rendering(): void {
		ec_test_reset();
		ec_gradient_smoke_run();
		$this->addToAssertionCount( 1 );
	}
}
