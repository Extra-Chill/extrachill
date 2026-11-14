<?php
/**
 * LinkPagesProvider::validate_runtime() trusts the Link Pages runtime's own
 * validation (#1355) instead of keeping a per-consumer signature table.
 *
 * Each mode proves one way the runtime can be unusable fails closed with the
 * public events error code, and that a valid runtime is accepted. The suite is
 * self-contained: the fixture stubs the runtime's validation surface, so it
 * runs regardless of which extrachill-link-pages ref LINK_PAGES_WORKTREE
 * resolves to.
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

	/** A runtime that validates itself, is ready and resolves storage is accepted. */
	public function test_valid_runtime_validates(): void {
		$result = $this->validate( 'valid' );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '', $result['error'] );
	}

	/**
	 * @dataProvider unusableRuntimeProvider
	 */
	public function test_unusable_runtime_fails_closed( string $mode, string $error ): void {
		$result = $this->validate( $mode );
		$this->assertFalse( $result['valid'] );
		$this->assertSame( $error, $result['error'] );
	}

	public function unusableRuntimeProvider(): array {
		return array(
			'plugin not active'       => array( 'inactive', 'venue_link_pages_runtime_not_configured' ),
			'validator missing'       => array( 'missing', 'venue_link_pages_runtime_incomplete' ),
			'runtime reports invalid' => array( 'invalid', 'venue_link_pages_runtime_incompatible' ),
			'runtime not ready'       => array( 'not-ready', 'venue_link_pages_runtime_incomplete' ),
			'storage blog unresolved' => array( 'no-storage', 'venue_link_pages_storage_unavailable' ),
		);
	}

	/** The runtime's own error code is preserved as the cause. */
	public function test_invalid_runtime_keeps_the_runtime_error_as_cause(): void {
		$result = $this->validate( 'invalid' );
		$this->assertSame( 'ec_link_pages_runtime_incompatible', $result['cause'] );
	}
}
