<?php

use PHPUnit\Framework\TestCase;

final class BootstrapAndPurityTest extends TestCase {
	private function artistWorktree(): string {
		$path = getenv( 'ARTIST_PLATFORM_WORKTREE' ) ?: '/var/lib/datamachine/workspace/extrachill-artist-platform@refactor-152-link-pages-runtime-handoff';
		if ( ! is_dir( $path . '/inc/link-pages' ) ) {
			$this->markTestSkipped( 'The optional Artist Platform integration worktree is unavailable.' );
		}
		return $path;
	}

	private function fixture( $name, array $environment = array() ): array {
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

	public function test_public_functions_and_stable_errors_match_coordinated_runtime(): void {
		$external = $this->artistWorktree();
		$local = file_get_contents( dirname( __DIR__ ) . '/inc/owner-reference.php' ) . file_get_contents( dirname( __DIR__ ) . '/inc/operations.php' );
		$coordinated = file_get_contents( $external . '/inc/link-pages/owner-reference.php' ) . file_get_contents( $external . '/inc/link-pages/operations.php' );

		preg_match_all( '/^function\s+(ec_[a-z0-9_]+)\s*\(([^)]*)\)/m', $local, $local_functions, PREG_SET_ORDER );
		preg_match_all( '/^function\s+(ec_[a-z0-9_]+)\s*\(([^)]*)\)/m', $coordinated, $coordinated_functions, PREG_SET_ORDER );
		$normalize_functions = static function ( $matches ) {
			return array_map( static function ( $match ) { return $match[1] . '(' . preg_replace( '/\s+/', ' ', trim( $match[2] ) ) . ')'; }, $matches );
		};
		$local_contract = array_values( array_filter( $normalize_functions( $local_functions ), static function ( $function ) { return false === strpos( $function, 'ec_can_register_link_page_' ); } ) );
		$this->assertSame( $normalize_functions( $coordinated_functions ), array_slice( $local_contract, 0, count( $coordinated_functions ) ) );
		$all_local = file_get_contents( dirname( __DIR__ ) . '/inc/post-type.php' ) . file_get_contents( dirname( __DIR__ ) . '/inc/compatibility.php' ) . file_get_contents( dirname( __DIR__ ) . '/inc/owner-reference.php' ) . file_get_contents( dirname( __DIR__ ) . '/inc/operations.php' ) . file_get_contents( dirname( __DIR__ ) . '/inc/storage.php' ) . file_get_contents( dirname( __DIR__ ) . '/inc/public-projections.php' ) . file_get_contents( dirname( __DIR__ ) . '/inc/public-runtime.php' );
		preg_match_all( '/^function\s+(ec_[a-z0-9_]+)\s*\(([^)]*)\)/m', $all_local, $all_functions, PREG_SET_ORDER );
		$this->assertSame( array(), array_diff( array_keys( ec_link_pages_runtime_function_contract() ), array_column( $all_functions, 1 ) ) );

		preg_match_all( "/new\s+WP_Error\(\s*'([^']+)'\s*,\s*'([^']+)'/s", $local, $local_errors, PREG_SET_ORDER );
		preg_match_all( "/new\s+WP_Error\(\s*'([^']+)'\s*,\s*'([^']+)'/s", $coordinated, $coordinated_errors, PREG_SET_ORDER );
		$normalize_errors = static function ( $matches ) {
			$errors = array_values( array_unique( array_map( static function ( $match ) { return $match[1] . '|' . $match[2]; }, $matches ) ) );
			sort( $errors );
			return $errors;
		};
		$this->assertSame( array(), array_diff( $normalize_errors( $coordinated_errors ), $normalize_errors( $local_errors ) ) );
	}

	public function test_representative_behavior_suite_passes_against_bundled_fallback(): void {
		$root = dirname( __DIR__ );
		$external = $this->artistWorktree();
		$command = 'LINK_PAGES_USE_FALLBACK=1 ARTIST_PLATFORM_WORKTREE=' . escapeshellarg( $external ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/tools/vendor/bin/phpunit' ) . ' -c ' . escapeshellarg( $root . '/phpunit.xml.dist' ) . ' --filter ' . escapeshellarg( '/^RuntimeTest::/' );
		exec( $command, $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertMatchesRegularExpression( '/OK \(8 tests, \d+ assertions\)/', implode( "\n", $output ) );
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
