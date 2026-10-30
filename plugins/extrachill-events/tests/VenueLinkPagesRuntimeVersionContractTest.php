<?php
/**
 * Regression coverage for #899: VenueLinkPagesProvider::validate_runtime()
 * fatally rejected a Link Pages runtime declaring API version '4'
 * (extrachill-link-pages.php:861 in production, extrachill-link-pages 0.9.0)
 * because the provider was still pinned to exactly '3'. This left
 * `\ExtraChillEvents\Core\VenueLinkPages` unloaded, which fatalled
 * /venue-settings/ for every venue team member.
 *
 * Unlike the other coordinated-runtime tests in this directory, this suite is
 * self-contained: it stubs the exact v3 function contract (unaffected by the
 * v3->v4 bump) plus the one v4-only addition, so it exercises the fix
 * regardless of which extrachill-link-pages ref LINK_PAGES_WORKTREE resolves
 * to.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

final class VenueLinkPagesRuntimeVersionContractTest extends TestCase {
	private function validate( string $mode ): array {
		$output  = array();
		$status  = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/venue-link-pages-runtime-version-contract.php' ) . ' ' . escapeshellarg( $mode );
		exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated PHP contract fixture.
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	/** A legacy runtime declaring API version '3' is still accepted. */
	public function test_v3_runtime_validates(): void {
		$result = $this->validate( 'v3' );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '', $result['error'] );
	}

	/** The exact production shape (#899): version '4' plus the post-type resolver. */
	public function test_v4_runtime_with_post_type_resolver_validates(): void {
		$result = $this->validate( 'v4-complete' );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '', $result['error'] );
	}

	/** Version '4' without the post-type resolver is a genuinely incomplete runtime. */
	public function test_v4_runtime_without_post_type_resolver_stays_rejected(): void {
		$result = $this->validate( 'v4-missing-resolver' );
		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'venue_link_pages_runtime_incomplete', $result['error'] );
	}
}
