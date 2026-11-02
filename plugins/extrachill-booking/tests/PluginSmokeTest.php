<?php
/** Booking bootstrap subprocess proofs avoid cross-suite WordPress doubles. */
use PHPUnit\Framework\TestCase;
final class PluginSmokeTest extends TestCase {
 public function test_bootstrap_boundaries(): void {
  exec( PHP_BINARY . ' ' . escapeshellarg( __DIR__ . '/bootstrap-boundary.php' ), $output, $status );
  $this->assertSame( 0, $status, implode( "\n", $output ) );
 }
}
