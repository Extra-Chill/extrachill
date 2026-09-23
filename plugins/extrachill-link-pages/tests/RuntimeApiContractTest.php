<?php
/**
 * Assert the exported runtime API contract demanded by the consumer handoff.
 *
 * The consumer (Artist Platform) validates this runtime fail-closed at boot:
 * every demanded function must exist with an exact parameter arity, the API
 * version must match, and the storage constants must keep their historical
 * values. This suite pins those demands here so signature drift is caught in
 * this repository before a release, not as a boot failure after deployment.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class RuntimeApiContractTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	/**
	 * Demanded signatures, transcribed from the consumer's runtime handoff
	 * contract: extrachill-artist-platform inc/link-pages/runtime-handoff.php,
	 * extrachill_artist_platform_link_pages_runtime_signatures(), artist-platform
	 * main as of 2026-09-23 (runtime API version 4; the consumer accepts both
	 * '3' and '4' during the parallel rollout — see extrachill-link-pages#34).
	 * Keys are function names; values are array( 'total' => int, 'required' =>
	 * int ) parameter counts.
	 *
	 * When the consumer publishes new demands, update this fixture in the same
	 * commit that satisfies them. The live cross-check below fails if this
	 * fixture drifts from the consumer's handoff file when that worktree is
	 * available (set ARTIST_PLATFORM_WORKTREE).
	 *
	 * @return array<string,array{total:int,required:int}>
	 */
	private function demanded_signatures(): array {
		return array(
			'ec_link_page_owner_compatibility_registry'        => array(
				'total'    => 0,
				'required' => 0,
			),
			'ec_register_link_page_owner_compatibility_provider' => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_can_register_link_page_owner_compatibility_provider' => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_parse_link_page_owner_reference'               => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_format_link_page_owner_reference'              => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_normalize_link_page_owner_reference'           => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_get_stored_link_page_owner_references'         => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_validate_link_page_owner_compatibility_claim'  => array(
				'total'    => 3,
				'required' => 3,
			),
			'ec_restore_link_page_owner_provider_context'      => array(
				'total'    => 3,
				'required' => 3,
			),
			'ec_invoke_link_page_owner_compatibility_provider' => array(
				'total'    => 3,
				'required' => 3,
			),
			'ec_collect_raw_link_page_owner_compatibility_claims' => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_reconcile_link_page_owner_candidate'           => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_collect_link_page_owner_compatibility_claims'  => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_get_link_page_owner'                           => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_get_link_page_id_for_owner'                    => array(
				'total'    => 2,
				'required' => 1,
			),
			'ec_validate_link_page_owner_candidate_ids'        => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_assign_link_page_owner'                        => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_compensate_link_page_owner_assignment'         => array(
				'total'    => 4,
				'required' => 4,
			),
			'ec_halt_link_page_owner_backfill'                 => array(
				'total'    => 4,
				'required' => 4,
			),
			'ec_backfill_link_page_owner_references'           => array(
				'total'    => 2,
				'required' => 0,
			),
			'ec_link_page_operation_provider_registry'         => array(
				'total'    => 0,
				'required' => 0,
			),
			'ec_register_link_page_operation_provider'         => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_can_register_link_page_operation_provider'     => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_resolve_link_page_operation_target'            => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_invoke_link_page_operation_callback'           => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_get_link_page_operation_provider'              => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_prepare_link_page_operation'                   => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_read_link_page'                                => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_save_link_page'                                => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_link_page_defaults'                            => array(
				'total'    => 0,
				'required' => 0,
			),
			'ec_link_page_defaults_for'                        => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_link_page_default'                             => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_sanitize_link_page_links'                      => array(
				'total'    => 2,
				'required' => 1,
			),
			'ec_sanitize_link_page_css_vars'                   => array(
				'total'    => 2,
				'required' => 1,
			),
			'ec_sanitize_link_page_settings'                   => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_read_link_page_persistence'                    => array(
				'total'    => 2,
				'required' => 1,
			),
			'ec_save_link_page_persistence'                    => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_save_link_page_persistence_composed'           => array(
				'total'    => 3,
				'required' => 3,
			),
			'ec_create_owned_link_page'                        => array(
				'total'    => 4,
				'required' => 3,
			),
			'ec_provision_owned_link_page'                     => array(
				'total'    => 5,
				'required' => 3,
			),
			'ec_provision_owned_link_page_composed'            => array(
				'total'    => 6,
				'required' => 4,
			),
			'ec_invoke_link_page_provision_precondition'       => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_create_owned_link_page_unlocked'               => array(
				'total'    => 4,
				'required' => 3,
			),
			'ec_with_link_page_lock_scope'                     => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_link_page_public_projection_registry'          => array(
				'total'    => 0,
				'required' => 0,
			),
			'ec_register_link_page_public_projection_provider' => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_can_register_link_page_public_projection_provider' => array(
				'total'    => 3,
				'required' => 2,
			),
			'ec_sanitize_link_page_public_projection_snapshot' => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_save_link_page_public_projection_snapshot'     => array(
				'total'    => 3,
				'required' => 3,
			),
			'ec_read_link_page_public_projection_snapshot'     => array(
				'total'    => 2,
				'required' => 1,
			),
			'ec_render_stored_link_page_social_links'          => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_get_link_page_public_projection'               => array(
				'total'    => 2,
				'required' => 1,
			),
			'ec_render_link_page_public_components'            => array(
				'total'    => 2,
				'required' => 2,
			),
			'ec_get_link_page_public_url'                      => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_link_page_public_urls'                         => array(
				'total'    => 1,
				'required' => 1,
			),
			'ec_link_page_post_type'                           => array(
				'total'    => 1,
				'required' => 0,
			),
		);
	}

	/**
	 * Every demanded function must exist with the exact demanded arity.
	 */
	public function test_every_demanded_function_is_exported_with_exact_arity(): void {
		foreach ( $this->demanded_signatures() as $function => $signature ) {
			$this->assertTrue(
				function_exists( $function ),
				sprintf( 'The runtime handoff demands %s(), but this runtime did not define it.', $function )
			);
			$reflection = new ReflectionFunction( $function );
			$this->assertSame(
				$signature['total'],
				$reflection->getNumberOfParameters(),
				sprintf( '%s() total parameter count drifted from the runtime handoff contract.', $function )
			);
			$this->assertSame(
				$signature['required'],
				$reflection->getNumberOfRequiredParameters(),
				sprintf( '%s() required parameter count drifted from the runtime handoff contract.', $function )
			);
		}
	}

	/**
	 * The declared function contract must cover every demanded name with the
	 * same arity, so the runtime self-validation and the consumer demands
	 * cannot diverge.
	 */
	public function test_declared_contract_covers_every_demand_with_matching_arity(): void {
		$declared = ec_link_pages_runtime_function_contract();
		foreach ( $this->demanded_signatures() as $function => $signature ) {
			$this->assertArrayHasKey(
				$function,
				$declared,
				sprintf( 'The declared runtime contract is missing the demanded %s().', $function )
			);
			$this->assertSame( $signature['total'], $declared[ $function ]['total'], $function );
			$this->assertSame( $signature['required'], $declared[ $function ]['required'], $function );
		}
	}

	/**
	 * The storage contract and API version must match what the consumer
	 * accepts; any other value fails the consumer's boot validation.
	 */
	public function test_storage_constants_and_api_version_match_consumer_support(): void {
		$this->assertSame( 'artist_link_page', EC_LINK_PAGE_POST_TYPE );
		$this->assertSame( '_ec_link_page_owner_reference', EC_LINK_PAGE_OWNER_META_KEY );
		$this->assertSame( '4', (string) EC_LINK_PAGES_RUNTIME_API_VERSION );
	}

	/**
	 * The post type follows the storage site, resolved at call time — never
	 * pinned to a literal, and never read at file-load time.
	 */
	public function test_post_type_resolves_from_storage_site_not_a_pinned_literal(): void {
		$this->assertTrue( function_exists( 'ec_link_page_post_type' ) );
		$this->assertSame( 'artist_link_page', ec_link_page_post_type() );
		$this->assertContains( ec_link_page_post_type(), array( 'artist_link_page', 'ec_link_page' ) );
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
	 * When the consumer worktree is available, prove the fixture is faithful
	 * to the live handoff file and run the consumer's own fail-closed
	 * validator against this loaded runtime.
	 */
	public function test_live_consumer_handoff_matches_fixture_and_accepts_runtime(): void {
		$path = getenv( 'ARTIST_PLATFORM_WORKTREE' );
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path . '/inc/link-pages/runtime-handoff.php' ) ) {
			$this->markTestSkipped( 'The optional consumer handoff worktree is unavailable (set ARTIST_PLATFORM_WORKTREE).' );
		}

		require_once $path . '/inc/link-pages/runtime-handoff.php';

		$this->assertSame(
			$this->demanded_signatures(),
			extrachill_artist_platform_link_pages_runtime_signatures(),
			'The transcribed demand fixture drifted from the consumer runtime handoff file.'
		);

		update_option( 'active_plugins', array( 'extrachill-link-pages/extrachill-link-pages.php' ) );
		$this->assertTrue(
			extrachill_artist_platform_validate_link_pages_runtime(),
			'The consumer runtime validator rejected this runtime.'
		);
	}
}
