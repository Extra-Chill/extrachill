<?php

use PHPUnit\Framework\TestCase;

final class BootstrapAndPurityTest extends TestCase {
	/**
	 * These tests spawn a fresh PHP process to verify cold-boot symbol purity, which genuinely
	 * requires a real subprocess (an in-process rewrite would stop proving what it claims to prove).
	 * Some managed sandboxes (e.g. WordPress Playground-backed harnesses) redefine PHP_BINARY to an
	 * in-process eval shim such as `/internal/eval.php` that cannot be exec()'d as a real interpreter.
	 * Skip loudly there instead of failing, since host and CI runs already cover this class.
	 */
	private function requireSpawnablePhp(): void {
		if ( ! is_executable( PHP_BINARY ) || 'eval.php' === basename( PHP_BINARY ) ) {
			$this->markTestSkipped( 'Process-spawn coverage skipped: this harness reports PHP_BINARY as ' . PHP_BINARY . ', which cannot be exec()\'d as a real PHP interpreter (e.g. a sandboxed eval shim). Cold-boot symbol purity is covered by the host and CI test runs, which spawn a real PHP binary.' );
		}
	}

	private function artistWorktree(): string {
		// Prefer the monorepo sibling over the legacy standalone checkout.
		$path = getenv( 'ARTIST_PLATFORM_WORKTREE' ) ?: dirname( __DIR__, 2 ) . '/extrachill-artist-platform';
		if ( ! is_dir( $path . '/inc/link-pages' ) ) {
			$this->markTestSkipped( 'Cross-runtime coverage skipped: the Extra Chill Artist Platform sibling checkout is unavailable. Set ARTIST_PLATFORM_WORKTREE to an extrachill-artist-platform checkout containing inc/link-pages (expected default: ' . $path . ').' );
		}
		return $path;
	}

	private function fixture( $name, array $environment = array() ): array {
		$this->requireSpawnablePhp();
		$command = '';
		foreach ( $environment as $key => $value ) { $command .= $key . '=' . escapeshellarg( $value ) . ' '; }
		$command .= escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/' . $name );
		exec( $command, $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true );
	}

	public function test_activation_coexists_with_loaded_fallback_symbols(): void {
		$result = $this->fixture( 'activation-coexistence.php' );
		$this->assertTrue( $result['ready'] );
		$this->assertSame( 1, $result['registrations'] );
		$this->assertSame( 1, $result['flushes'] );
		$this->assertSame( array( 'fallback' ), $result['fallback'] );
	}

	public function test_multisite_storage_constant_is_validated_before_activation(): void {
		$valid = $this->fixture( 'storage-constant.php', array( 'STORAGE_BLOG_ID' => '4' ) );
		$this->assertSame( 4, $valid['storage_blog_id'] );
		$this->assertTrue( $valid['activation_ready'] );

		$invalid = $this->fixture( 'storage-constant.php', array( 'STORAGE_BLOG_ID' => '999' ) );
		$this->assertSame( 0, $invalid['storage_blog_id'] );
		$this->assertSame( 'link_page_network_storage_unconfigured', $invalid['activation_error'] );
	}

	public function test_real_external_adapter_registers_against_runtime(): void {
		$path = $this->artistWorktree();
		$result = $this->fixture( 'external-adapter.php', array( 'ARTIST_PLATFORM_WORKTREE' => $path ) );
		$this->assertTrue( $result['ready'] );
		$this->assertSame( array( 'artist-platform' ), $result['owner_providers'] );
		$this->assertSame( array( 'artist-platform' ), $result['operation_providers'] );
	}

	public function test_generic_source_is_owner_neutral(): void {
		$root = dirname( __DIR__ );
		$source = '';
		foreach ( array_merge( glob( $root . '/*.php' ), glob( $root . '/inc/*.php' ), glob( $root . '/templates/*.php' ), glob( $root . '/templates/components/*.php' ), glob( $root . '/assets/js/*.js' ), glob( $root . '/assets/css/*.css' ) ) as $file ) {
			$source .= file_get_contents( $file );
		}
		$source = strtolower( $source );
		$historical_contract_identifiers = array(
			'artist_link_page',
			'extrachill_artist_link_page_minimal_head',
			'extrachill_redirect_artist_link_page_cpt_to_custom_domain',
			'extrachill_artist_link_page_sitemap_urls',
			'extrachill_artist_enqueue_link_page_minimal_assets',
		);
		$source = str_replace( $historical_contract_identifiers, '', $source );
		foreach ( array( 'artist', 'manage-artist', 'manage-link-page', 'dev_view_link_page', "'artist_id'", "'join' ===", 'venue', 'promoter', 'booking', 'subscription', 'domain authorization' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $source );
		}
	}

	public function test_artist_platform_carries_no_copy_of_the_generic_runtime(): void {
		$external = $this->artistWorktree();

		// This plugin is the only provider of the generic API: the consumer
		// must not ship its own owner-reference or operations runtime.
		$this->assertFileDoesNotExist( $external . '/inc/link-pages/owner-reference.php' );
		$this->assertFileDoesNotExist( $external . '/inc/link-pages/operations.php' );

		$all_local = '';
		foreach ( glob( dirname( __DIR__ ) . '/inc/*.php' ) as $file ) {
			$all_local .= file_get_contents( $file );
		}
		preg_match_all( '/^function\s+(ec_[a-z0-9_]+)\s*\(/m', $all_local, $all_functions );
		$this->assertSame( array(), array_diff( array_keys( ec_link_pages_runtime_function_contract() ), $all_functions[1] ) );
	}

	/** @dataProvider incompatibleRuntimeProvider */
	public function test_incompatible_preloaded_runtimes_fail_explicitly( $mode, $error ): void {
		$result = $this->fixture( 'runtime-compatibility.php', array( 'RUNTIME_MODE' => $mode ) );
		$this->assertSame( $error, $result['validation'] );
		$this->assertSame( $error, $result['activation'] );
	}

	public function incompatibleRuntimeProvider(): array {
		return array(
			'partial component' => array( 'partial', 'ec_link_pages_runtime_partial' ),
			'partial storage component' => array( 'partial_storage', 'ec_link_pages_runtime_partial' ),
			'partial lifecycle component' => array( 'partial_lifecycle', 'ec_link_pages_runtime_partial' ),
			'wrong constants' => array( 'wrong_constants', 'ec_link_pages_runtime_incompatible' ),
			'wrong API' => array( 'wrong_api', 'ec_link_pages_runtime_incompatible' ),
			'incompatible signature' => array( 'incompatible_signature', 'ec_link_pages_runtime_incompatible' ),
			'readiness exception' => array( 'readiness_exception', 'ec_link_pages_runtime_readiness_failed' ),
		);
	}

	public function test_complete_preloaded_operations_allow_owner_component_to_load_independently(): void {
		$result = $this->fixture( 'runtime-compatibility.php', array( 'RUNTIME_MODE' => 'operations_only' ) );
		$this->assertTrue( $result['validation'] );
		$this->assertTrue( $result['activation'] );
		$this->assertTrue( $result['owner_loaded'] );
		$this->assertTrue( $result['operation_loaded'] );
	}
}
