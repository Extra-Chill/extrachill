<?php
/**
 * Door ability contract tests, isolated from the ticket suite's WP doubles.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

/** Door rows and permission regressions. */
final class DoorListAbilitiesTest extends TestCase {
	/** Run the isolated contract fixture. */
	public function test_door_contract(): void {
		$fixture = dirname( __DIR__, 2 ) . '/fixtures/door-list-abilities.php';
		$output  = array();
		$status  = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fixture ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( 'door contract passed', implode( "\n", $output ) );
	}
}
