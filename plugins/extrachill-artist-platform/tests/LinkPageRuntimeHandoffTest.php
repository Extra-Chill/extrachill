<?php

use PHPUnit\Framework\TestCase;

final class LinkPageRuntimeHandoffTest extends TestCase {
	private function runSmokeFixture( $fixture, $argument = '' ) {
		$output = array();
		$status = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/' . $fixture );
		if ( '' !== $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}
		exec( $command, $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	public function test_fake_external_runtime_owns_generic_symbols_and_cpt_while_artist_adapter_registers_once(): void {
		$result = $this->runSmokeFixture( 'link-pages-external-runtime-smoke.php' );

		$this->assertTrue( $result['booted'] );
		$this->assertSame( 'artist_link_page', $result['post_type_constant'] );
		$this->assertSame( '_ec_link_page_owner_reference', $result['owner_meta_constant'] );
		$this->assertSame( 1, $result['owner_providers'] );
		$this->assertSame( 1, $result['operation_providers'] );
		$this->assertSame( 'external-runtime', $result['link_page_cpt_owner'] );
		$this->assertTrue( $result['artist_profile_exists'] );
		$this->assertSame( 'post:4:artist_profile:20', $result['legacy_owner_reference'] );
		$this->assertSame( 0, $result['write_calls'] );
	}

	public function test_configured_runtime_failure_is_explicit(): void {
		$result = $this->runSmokeFixture( 'link-pages-missing-runtime-smoke.php' );

		$this->assertSame( 'extrachill_link_pages_runtime_incomplete', $result['error_code'] );
		$this->assertTrue( $result['notice_hooked'] );
	}

	/**
	 * @dataProvider activationFailureProvider
	 */
	public function test_activation_fails_explicitly_for_invalid_configured_runtime( $mode, $message ): void {
		$result = $this->runSmokeFixture( 'link-pages-activation-failure-smoke.php', $mode );

		$this->assertTrue( $result['failed'] );
		$this->assertStringContainsString( $message, $result['message'] );
	}

	public function activationFailureProvider(): array {
		return array(
			'missing runtime'       => array( 'missing', 'did not load its complete generic API' ),
			'no API marker'         => array( 'no-marker', 'did not declare its API version' ),
			'stale API version'     => array( 'stale-version', 'API version is not supported' ),
			'wrong generic arity'   => array( 'wrong-arity', 'incompatible generic function signature' ),
			'wrong required arity'  => array( 'wrong-required-arity', 'incompatible generic function signature' ),
			'no readiness marker'   => array( 'no-readiness', 'did not expose its readiness marker' ),
			'wrong readiness arity' => array( 'readiness-arity', 'incompatible readiness marker' ),
		);
	}

	/**
	 * @dataProvider incompatibleRuntimeProvider
	 */
	public function test_incompatible_external_runtime_fails_before_adapters_load( $mode, $error_code ): void {
		$result = $this->runSmokeFixture( 'link-pages-runtime-validation-smoke.php', $mode );

		$this->assertSame( $error_code, $result['error_code'] );
		$this->assertTrue( $result['notice_hooked'] );
	}

	public function incompatibleRuntimeProvider(): array {
		return array(
			'post type contract' => array( 'post-type', 'extrachill_link_pages_runtime_incompatible' ),
			'owner meta contract' => array( 'owner-meta', 'extrachill_link_pages_runtime_incompatible' ),
			'no API marker'      => array( 'no-marker', 'extrachill_link_pages_runtime_incomplete' ),
			'stale API version'  => array( 'stale-version', 'extrachill_link_pages_runtime_incompatible' ),
			'wrong generic arity' => array( 'wrong-arity', 'extrachill_link_pages_runtime_incompatible' ),
			'wrong required arity'=> array( 'wrong-required-arity', 'extrachill_link_pages_runtime_incompatible' ),
			'no readiness marker'=> array( 'no-readiness', 'extrachill_link_pages_runtime_incomplete' ),
			'readiness arity'    => array( 'readiness-arity', 'extrachill_link_pages_runtime_incompatible' ),
			'not ready'          => array( 'readiness', 'extrachill_link_pages_runtime_incomplete' ),
		);
	}

	public function test_first_activation_and_next_request_use_the_real_standalone_runtime_contract(): void {
		$activation = $this->runSmokeFixture( 'link-pages-real-runtime-smoke.php', 'activation' );
		$this->assertFalse( $activation['before_boot'] );
		$this->assertTrue( $activation['after_boot'] );
		$this->assertTrue( $activation['ready'] );
		$this->assertTrue( $activation['contract_matches'] );
		$this->assertSame( 1, $activation['link_registrations'] );
		$this->assertSame( 1, $activation['owner_providers'] );
		$this->assertSame( 1, $activation['operation_providers'] );
		$this->assertSame( 1, $activation['flushes'] );

		$external = $this->runSmokeFixture( 'link-pages-real-runtime-smoke.php', 'external' );
		$this->assertFalse( $external['before_standalone'] );
		$this->assertTrue( $external['after_standalone'] );
		$this->assertTrue( $external['ready'] );
		$this->assertTrue( $external['contract_matches'] );
		$this->assertTrue( $external['second_boot'] );
		$this->assertSame( 1, $external['link_registrations'] );
		$this->assertSame( 1, $external['owner_providers'] );
		$this->assertSame( 1, $external['operation_providers'] );
	}

	public function test_fallback_mode_remains_configured_without_a_hard_plugin_dependency(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/extrachill-artist-platform.php' );

		$this->assertStringNotContainsString( 'Requires Plugins: extrachill-link-pages', $source );
		$this->assertTrue( defined( 'EC_LINK_PAGE_POST_TYPE' ) );
		$this->assertTrue( function_exists( 'ec_get_link_page_owner' ) );
		$this->assertTrue( function_exists( 'ec_read_link_page' ) );
	}

	public function test_site_and_network_active_configuration_select_the_external_runtime(): void {
		$GLOBALS['ec_test']['options']['active_plugins'] = array( 'extrachill-link-pages/extrachill-link-pages.php' );
		$this->assertTrue( extrachill_artist_platform_uses_external_link_pages_runtime() );

		$GLOBALS['ec_test']['options']['active_plugins'] = array();
		$GLOBALS['ec_test']['site_options']['active_sitewide_plugins'] = array(
			'extrachill-link-pages/extrachill-link-pages.php' => 123,
		);
		$this->assertTrue( extrachill_artist_platform_uses_external_link_pages_runtime() );

		$GLOBALS['ec_test']['site_options']['active_sitewide_plugins'] = array();
		$this->assertFalse( extrachill_artist_platform_uses_external_link_pages_runtime() );
	}
}
