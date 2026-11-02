<?php
/**
 * Cold-process network adapter contract.
 *
 * @package ExtraChillBooking\Tests
 */

use PHPUnit\Framework\TestCase;

/** Keeps network-only stubs isolated from the WordPress test runtime. */
final class NetworkBookingBlockHandoverTest extends TestCase {
	/** Run the same executable contract used by standalone validation. */
	public function test_network_block_handover(): void {
		$output = array();
		$status = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- A cold subprocess isolates WordPress test doubles.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/network-block-handover.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertContains( 'Booking network block handover passed.', $output );
	}
}
