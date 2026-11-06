<?php

use PHPUnit\Framework\TestCase;

final class CardBackgroundTest extends TestCase {
	public function test_card_background_persistence_smoke(): void {
		ec_test_reset();
		ob_start();
		try {
			require __DIR__ . '/card-bg-smoke.php';
			$this->assertStringContainsString( 'Card background smoke passed', ob_get_contents() );
		} finally {
			ob_end_clean();
		}
	}
}
