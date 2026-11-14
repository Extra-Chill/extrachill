<?php
/**
 * Assert the runtime API contract consumers trust.
 *
 * Consumers (Artist Platform, Events) keep no copy of this runtime's function
 * signatures: they ask ec_validate_link_pages_runtime() and
 * ec_link_pages_runtime_ready(). The runtime's own
 * ec_link_pages_runtime_function_contract() is the single source of truth, so
 * this suite proves the self-validation passes and the storage constants keep
 * their historical values.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class RuntimeApiContractTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	/**
	 * The storage contract and API version must match what the consumer
	 * accepts; any other value fails the consumer's boot validation.
	 */
	public function test_storage_constants_and_api_version_match_consumer_support(): void {
		$this->assertSame( '_ec_link_page_owner_reference', EC_LINK_PAGE_OWNER_META_KEY );
		$this->assertSame( '4', (string) EC_LINK_PAGES_RUNTIME_API_VERSION );
	}

	/**
	 * The post type resolver is always the dedicated-site type.
	 */
	public function test_post_type_resolves_to_the_dedicated_type(): void {
		$this->assertTrue( function_exists( 'ec_link_page_post_type' ) );
		$this->assertSame( 'ec_link_page', ec_link_page_post_type() );
	}

	/**
	 * The complete self-validation and the public readiness marker must pass
	 * in this exact runtime state.
	 */
	public function test_self_validation_and_readiness_marker_pass(): void {
		$this->assertTrue( ec_validate_link_pages_runtime() );
		$this->assertTrue( ec_link_pages_runtime_ready() );
	}

	/**
	 * When the consumer worktree is available, run the consumer's validator
	 * against this loaded runtime: it must trust the runtime's own answer.
	 */
	public function test_live_consumer_handoff_accepts_runtime(): void {
		$path = getenv( 'ARTIST_PLATFORM_WORKTREE' );
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path . '/inc/link-pages/runtime-handoff.php' ) ) {
			$this->markTestSkipped( 'The optional consumer handoff worktree is unavailable (set ARTIST_PLATFORM_WORKTREE).' );
		}

		require_once $path . '/inc/link-pages/runtime-handoff.php';

		update_option( 'active_plugins', array( 'extrachill-link-pages/extrachill-link-pages.php' ) );
		$this->assertTrue(
			extrachill_artist_platform_validate_link_pages_runtime(),
			'The consumer runtime validator rejected this runtime.'
		);
	}
}
