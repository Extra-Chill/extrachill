<?php

require_once __DIR__ . '/support/base-test-case.php';
require_once __DIR__ . '/fixtures/link-pages-runtime-fixture.php';

final class LinkPageRuntimeHandoffTest extends EC_Artist_Platform_TestCase {
	/** Require the same dependency path used by the child fixtures. */
	private function require_real_runtime_fixture_source(): void {
		ec_test_link_pages_runtime_path( dirname( __DIR__ ) );
	}

	/**
	 * Whether a PHP subprocess can run here. The WordPress sandbox (php.wasm)
	 * cannot spawn processes; these fixtures also run as standalone PHP smokes
	 * (homeboy.json standalone_php_smokes), which is their coverage there.
	 */
	private static function canRunPhpSubprocess(): bool {
		static $available = null;
		if ( null === $available ) {
			$available = false;
			if ( function_exists( 'exec' ) && '' !== (string) PHP_BINARY ) {
				$probe  = array();
				$status = 1;
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- probes whether the runtime can spawn the helper binary.
				@exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( 'echo "ok";' ) . ' 2>/dev/null', $probe, $status ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$available = 0 === $status && array( 'ok' ) === $probe;
			}
		}
		return $available;
	}

	private function runSmokeFixture( $fixture, $argument = '' ) {
		if ( ! self::canRunPhpSubprocess() ) {
			$this->markTestSkipped( 'No PHP subprocess in this runtime; the fixture runs as a standalone PHP smoke instead.' );
		}
		$output  = array();
		$status  = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/' . $fixture );
		if ( '' !== $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- intentionally shells out to a helper binary for the runtime-handoff proof.
		exec( $command . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	public function test_artist_runtime_requires_exact_composed_mutation_signatures(): void {
		$signatures = extrachill_artist_platform_link_pages_runtime_signatures();

		$this->assertSame( array(
			'total'    => 3,
			'required' => 3,
		), $signatures['ec_save_link_page_persistence_composed'] );
		$this->assertSame( array(
			'total'    => 6,
			'required' => 4,
		), $signatures['ec_provision_owned_link_page_composed'] );
	}

	public function test_fake_external_runtime_owns_generic_symbols_and_cpt_while_artist_adapter_registers_once(): void {
		$result = $this->runSmokeFixture( 'link-pages-external-runtime-smoke.php' );

		$this->assertTrue( $result['booted'] );
		$this->assertSame( 'artist_link_page', $result['post_type_constant'] );
		$this->assertSame( '_ec_link_page_owner_reference', $result['owner_meta_constant'] );
		$this->assertSame( 1, $result['owner_providers'] );
		$this->assertSame( 1, $result['operation_providers'] );
		$this->assertSame( 1, $result['projection_providers'] );
		$this->assertSame( 'external-runtime', $result['link_page_cpt_owner'] );
		$this->assertFalse( $result['artist_platform_registers_link_page_cpt'] );
		$this->assertTrue( $result['artist_profile_exists'] );
		$this->assertSame( 0, $result['write_calls'] );
	}

	public function test_missing_runtime_skips_adapters_with_an_admin_notice(): void {
		$result = $this->runSmokeFixture( 'link-pages-missing-runtime-smoke.php' );

		$this->assertSame( 'extrachill_link_pages_runtime_missing', $result['error_code'] );
		$this->assertTrue( $result['notice_hooked'] );
		$this->assertFalse( $result['adapters_loaded'] );
		$this->assertFalse( $result['generic_api_defined'] );
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
			'missing runtime'       => array( 'missing', 'requires the Extra Chill Link Pages plugin' ),
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
			'owner meta contract'      => array( 'owner-meta', 'extrachill_link_pages_runtime_incompatible' ),
			'no API marker'            => array( 'no-marker', 'extrachill_link_pages_runtime_incomplete' ),
			'stale API version'        => array( 'stale-version', 'extrachill_link_pages_runtime_incompatible' ),
			'wrong generic arity'      => array( 'wrong-arity', 'extrachill_link_pages_runtime_incompatible' ),
			'wrong required arity'     => array( 'wrong-required-arity', 'extrachill_link_pages_runtime_incompatible' ),
			'no readiness marker'      => array( 'no-readiness', 'extrachill_link_pages_runtime_incomplete' ),
			'readiness arity'          => array( 'readiness-arity', 'extrachill_link_pages_runtime_incompatible' ),
			'not ready'                => array( 'readiness', 'extrachill_link_pages_runtime_incomplete' ),
			'v3 runtime'               => array( 'v3', 'extrachill_link_pages_runtime_incompatible' ),
			'v4 without post type fn'  => array( 'v4-no-resolver', 'extrachill_link_pages_runtime_incomplete' ),
		);
	}

	/**
	 * extrachill-link-pages#34: the handoff must stop pinning the
	 * EC_LINK_PAGE_POST_TYPE constant's value. A runtime that declares a
	 * different value (as the dedicated Link Pages site does post-cutover)
	 * is compatible now, not rejected.
	 */
	public function test_handoff_no_longer_pins_the_post_type_constant_value(): void {
		$result = $this->runSmokeFixture( 'link-pages-runtime-validation-smoke.php', 'post-type' );

		$this->assertSame( '', $result['error_code'] );
	}

	/**
	 * The monorepo deploys Artist Platform and Link Pages from one commit, so
	 * the handoff accepts only the current runtime API version ('4').
	 */
	public function test_handoff_accepts_the_current_api_version(): void {
		$v4 = $this->runSmokeFixture( 'link-pages-runtime-validation-smoke.php' );
		$this->assertSame( '', $v4['error_code'] );
	}

	/**
	 * A v4 runtime declaring a different post-type constant value (e.g. the
	 * dedicated Link Pages site returning 'ec_link_page') is compatible: only
	 * the resolver function's callability is required, not the constant.
	 */
	public function test_v4_handoff_accepts_a_different_post_type_constant_value(): void {
		$result = $this->runSmokeFixture( 'link-pages-runtime-validation-smoke.php', 'v4-mismatched-post-type-constant' );

		$this->assertSame( '', $result['error_code'] );
	}

	public function test_real_runtime_smoke_defaults_to_activation(): void {
		$result = $this->runSmokeFixture( 'link-pages-real-runtime-smoke.php' );

		$this->assertTrue( $result['ready'] );
		$this->assertTrue( $result['contract_matches'] );
		$this->assertSame( 1, $result['flushes'] );
	}

	public function test_first_activation_and_next_request_use_the_real_standalone_runtime_contract(): void {
		$this->require_real_runtime_fixture_source();
		// Activation request: Artist Platform boots before Link Pages is loaded.
		// It carries no fallback copy, so it declares no generic API, skips its
		// adapters with an explicit error, and Link Pages still activates cleanly.
		$activation = $this->runSmokeFixture( 'link-pages-real-runtime-smoke.php', 'activation' );
		$this->assertFalse( $activation['before_boot'] );
		$this->assertFalse( $activation['after_boot'] );
		$this->assertSame( 'extrachill_link_pages_runtime_missing', $activation['boot_error'] );
		$this->assertTrue( $activation['ready'] );
		$this->assertTrue( $activation['contract_matches'] );
		$this->assertSame( 1, $activation['link_registrations'] );
		$this->assertSame( 0, $activation['owner_providers'] );
		$this->assertSame( 0, $activation['operation_providers'] );
		$this->assertSame( 1, $activation['flushes'] );
		$this->assertTrue( $activation['storage_provider_before_standalone'] );
		$this->assertSame( 4, $activation['storage_blog_id'] );
		$site_activation = $this->runSmokeFixture( 'link-pages-real-runtime-smoke.php', 'activation-site' );
		$this->assertTrue( $site_activation['storage_provider_before_standalone'] );
		$this->assertSame( 4, $site_activation['storage_blog_id'] );
		$this->assertSame( 1, $site_activation['flushes'] );

		$external = $this->runSmokeFixture( 'link-pages-real-runtime-smoke.php', 'external' );
		$this->assertFalse( $external['before_standalone'] );
		$this->assertTrue( $external['after_standalone'] );
		$this->assertTrue( $external['ready'] );
		$this->assertTrue( $external['contract_matches'] );
		$this->assertTrue( $external['second_boot'] );
		$this->assertSame( 1, $external['link_registrations'] );
		$this->assertSame( 1, $external['owner_providers'] );
		$this->assertSame( 1, $external['operation_providers'] );
		$this->assertSame( 1, $external['projection_providers'] );
	}

	public function test_real_combined_runtime_executes_artist_lifecycle_against_standalone(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php' );

		$this->assertIsInt( $result['created'] );
		$this->assertSame( array( 'ec_link_page_created', 'ec_owned_link_page_created' ), $result['creation_hooks'] );
		$this->assertTrue( $result['read_shape'] );
		$this->assertTrue( $result['saved'] );
		$this->assertSame( array( 'ec_link_page_save', 'ec_link_page_persistence_saved' ), $result['save_hooks'] );
		$this->assertTrue( $result['owner_writes_locked'] );
		$this->assertSame( 'Updated bio', $result['saved_bio'] );
		$this->assertSame( 'Ability bio', $result['persisted_bio'] );
		$this->assertSame( array(
			'links'    => true,
			'styles'   => true,
			'settings' => true,
			'socials'  => true,
		), $result['ability_results'] );
		$this->assertSame( 1, $result['social_count'] );
		$this->assertSame( 55, $result['thumbnail'] );
		$this->assertSame( 'Combined Artist', $result['projection_title'] );
		$this->assertTrue( $result['projection_schema'] );
		$this->assertSame( 'Combined Artist portrait', $result['projection_alt'] );
		$this->assertSame( 'https://extrachill.link/combined-artist/', $result['projection_canonical'] );
		$this->assertTrue( $result['rendered_body'] );
		$this->assertTrue( $result['rendered_subscribe'] );
		$this->assertTrue( $result['snapshot_parity'] );
		$this->assertTrue( $result['head_hook'] );
		$this->assertSame( 'https://artist.extrachill.com/login/?from_join=true', $result['join_redirect'] );
		$this->assertIsInt( $result['forced'] );
		$this->assertTrue( $result['legacy_detached'] );
		$this->assertSame( 5, $result['final_hook_count'] );
		$this->assertSame( 1, $result['delete_purge_delta'] );
		$this->assertContains( 'https://extrachill.link/combined-artist/', $result['cache_deleted_urls'] );
		$this->assertGreaterThanOrEqual( 7, $result['cache_action_count'] );
		$this->assertSame( 1, $result['save_cache_delta'] );
		$this->assertSame( 4, $result['ability_cache_delta'] );
		$this->assertSame( 4, $result['caller_blog'] );
		foreach ( array( 1, 7 ) as $caller_blog_id ) {
			$this->assertSame( $result['created'], $result['cross_blog_resolution'][ $caller_blog_id ]['id'] );
			$this->assertSame( $result['created'], $result['cross_blog_resolution'][ $caller_blog_id ]['read'] );
			$this->assertSame( array( 'https://extrachill.link/combined-artist/' ), $result['cross_blog_resolution'][ $caller_blog_id ]['urls'] );
			$this->assertSame( $caller_blog_id, $result['cross_blog_resolution'][ $caller_blog_id ]['caller'] );
			$this->assertSame( 40, $result['cross_blog_resolution'][ $caller_blog_id ]['existing'] );
		}
	}

	public function test_real_combined_runtime_compensates_force_detach_failure(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'force-failure' );

		$this->assertSame( 'link_page_previous_detach_failed', $result['forced'] );
		$this->assertSame( array(), $result['force_hooks'] );
		$this->assertSame( $result['created'], $result['force_restored_id'] );
		$this->assertSame( 'combined-artist', $result['force_restored_slug'] );
		$this->assertSame( 1, $result['force_new_count'] );
		$this->assertFalse( $result['legacy_detached'] );
		$this->assertSame( 5, $result['final_hook_count'] );
	}

	/**
	 * Guards extrachill-artist-platform#225 against the real runtime guard
	 * added for extrachill-artist-platform#224: the ability must thread
	 * `allow_empty` through to storage, and only a strict boolean `true`
	 * may satisfy the refusal.
	 */
	public function test_ability_threads_strict_allow_empty_through_the_real_runtime_guard(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'allow-empty-links' );
		$allow_empty = $result['allow_empty_result'];

		$this->assertSame( 1, $allow_empty['before_count'] );
		$this->assertSame( 'link_page_refuses_silent_empty', $allow_empty['refused_code'] );
		$this->assertSame( 'link_page_refuses_silent_empty', $allow_empty['coerced_code'] );
		$this->assertTrue( $allow_empty['unchanged_after_refusals'] );
		$this->assertSame( array(), $allow_empty['cleared_result'] );
		$this->assertSame( 0, $allow_empty['after_count'] );
	}

	public function test_owner_save_failure_rolls_back_generic_fields_without_final_hook(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'owner-save-failure' );

		$this->assertSame( 'social_failure', $result['saved'] );
		$this->assertSame( array(), $result['save_hooks'] );
		$this->assertSame( '', $result['persisted_bio'] );
		$this->assertSame( 0, $result['social_count'] );
		$this->assertSame( 0, $result['final_hook_count'] );
		$this->assertSame( 0, $result['save_cache_delta'] );
		$this->assertSame( 0, $result['ability_cache_delta'] );
		$this->assertTrue( $result['owner_writes_locked'] );
		$this->assertSame( '0', (string) $result['failure_contention'] );
	}

	public function test_combined_authorized_saves_serialize_without_stale_compensation(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'contention' );

		$this->assertTrue( $result['contention']['first'] );
		$this->assertSame( '0', (string) $result['contention']['blocked'] );
		$this->assertTrue( $result['contention']['second'] );
		$this->assertSame( 'Second authorized save', $result['contention']['final'] );
		$this->assertTrue( $result['owner_writes_locked'] );
	}

	public function test_failed_save_compensates_before_next_authorized_save_wins(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'interleaving-failure' );

		$this->assertSame( 'social_failure', $result['saved'] );
		$this->assertSame( '', $result['persisted_after_failed_save'] );
		$this->assertTrue( $result['post_failure_save'] );
		$this->assertSame( 'https://bandcamp.com/authoritative', $result['post_failure_social_url'] );
		$this->assertSame( '0', (string) $result['failure_contention'] );
		$this->assertTrue( $result['owner_writes_locked'] );
	}

	public function test_separate_and_combined_mutations_reject_reverse_interleaving(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'separate-contention' );

		$this->assertSame( 'link_page_lock_scope_conflict', $result['separate_contention']['during_combined_social'] );
		$this->assertSame( 'link_page_lock_scope_conflict', $result['separate_contention']['during_combined_profile'] );
		$this->assertSame( 'link_page_lock_scope_conflict', $result['separate_contention']['during_separate'] );
		$this->assertTrue( $result['separate_contention']['social_after'] );
		$this->assertTrue( $result['separate_contention']['profile_after'] );
		$this->assertTrue( $result['separate_contention']['combined_after'] );
		$this->assertSame( 'Combined after separate release', $result['separate_contention']['final_bio'] );
		$this->assertSame( 'https://example.com/after', $result['separate_contention']['final_social_url'] );
		$this->assertSame( 'Profile after release', $result['separate_contention']['final_profile_title'] );
	}

	/** @dataProvider crossBlogMutationProvider */
	public function test_cross_blog_social_mutation_uses_blog_four_lock_and_cache( $mode, $caller_blog ): void {
		$this->require_real_runtime_fixture_source();
		$result   = $this->runSmokeFixture( 'combined-runtime-smoke.php', $mode );
		$mutation = $result['cross_blog_mutation'];

		$this->assertTrue( $mutation['result'] );
		$this->assertSame( 4, $mutation['lock_blog'] );
		$this->assertSame( $result['created'], $mutation['lock_page'] );
		$this->assertSame( 4, $mutation['mutation_blog'] );
		$this->assertTrue( $mutation['profile_result'] );
		$this->assertSame( 4, $mutation['profile_lock_blog'] );
		$this->assertSame( $result['created'], $mutation['profile_lock_page'] );
		$this->assertSame( 4, $mutation['profile_mutation_blog'] );
		$this->assertSame( 4, $mutation['cache_blog'] );
		$this->assertSame( 2, $mutation['cache_delta'] );
		$this->assertSame( '0', (string) $mutation['contender'] );
		$this->assertSame( $caller_blog, $mutation['caller_after'] );
		$this->assertTrue( $mutation['stack_restored'] );
	}

	public function crossBlogMutationProvider(): array {
		return array(
			'blog one'   => array( 'cross-blog-1', 1 ),
			'blog seven' => array( 'cross-blog-7', 7 ),
		);
	}

	public function test_cross_blog_social_failure_restores_context_without_purge(): void {
		$this->require_real_runtime_fixture_source();
		$result   = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'cross-blog-failure' );
		$mutation = $result['cross_blog_mutation'];

		$this->assertSame( 'social_failure', $mutation['result'] );
		$this->assertSame( 4, $mutation['lock_blog'] );
		$this->assertSame( 4, $mutation['mutation_blog'] );
		$this->assertSame( 0, $mutation['cache_delta'] );
		$this->assertSame( '0', (string) $mutation['contender'] );
		$this->assertSame( 7, $mutation['caller_after'] );
		$this->assertTrue( $mutation['stack_restored'] );
	}

	public function test_orphaned_or_unpublished_owner_renders_404(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'orphan-owner' );

		$this->assertSame( 'link_page_public_owner_unavailable', $result['projection_title'] );
		$this->assertSame( 404, $result['public_status'] );
	}

	public function test_deleted_canonical_owner_metadata_renders_404(): void {
		$this->require_real_runtime_fixture_source();
		$result = $this->runSmokeFixture( 'combined-runtime-smoke.php', 'deleted-owner' );

		$this->assertSame( 'invalid_link_page_owner_object', $result['projection_title'] );
		$this->assertSame( 404, $result['public_status'] );
	}

	public function test_editor_get_reports_setup_without_provisioning(): void {
		$result = $this->runSmokeFixture( 'editor-no-write-smoke.php' );

		$this->assertSame( 0, $result['writes'] );
		$this->assertTrue( $result['setup_state'] );
		$this->assertTrue( $result['cta'] );
	}

	public function test_link_pages_is_a_hard_plugin_dependency(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source-file read, not a remote URL.
		$source = file_get_contents( dirname( __DIR__ ) . '/extrachill-artist-platform.php' );

		$this->assertMatchesRegularExpression( '/^\s*\*\s*Requires Plugins:\s*extrachill-link-pages\s*$/m', $source );
	}

	/**
	 * Artist Platform carries no copy of the generic Link Pages runtime: no
	 * ec_* function it declares may also be declared by extrachill-link-pages,
	 * and the bundled/standalone switch is gone.
	 */
	public function test_artist_platform_declares_no_generic_link_pages_functions(): void {
		$artist_root = dirname( __DIR__ );
		$runtime     = ec_test_link_pages_runtime_path( $artist_root );

		$artist_functions  = self::declaredEcFunctions( $artist_root );
		$runtime_functions = self::declaredEcFunctions( $runtime );

		$this->assertNotEmpty( $runtime_functions );
		$this->assertSame( array(), array_values( array_intersect( $artist_functions, $runtime_functions ) ) );
		$this->assertSame( array(), array_keys( array_filter( array_count_values( $artist_functions ), static function ( $count ) { return $count > 1; } ) ) );
		$this->assertFileDoesNotExist( $artist_root . '/inc/link-pages/operations.php' );
		$this->assertFileDoesNotExist( $artist_root . '/inc/link-pages/owner-reference.php' );
		$this->assertFalse( function_exists( 'extrachill_artist_platform_uses_external_link_pages_runtime' ) );
	}

	/**
	 * Every ec_* function declared in a plugin's shipped PHP source.
	 *
	 * @param string $root Plugin root.
	 * @return string[]
	 */
	private static function declaredEcFunctions( string $root ): array {
		$functions = array();
		$files     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$path = $file->getPathname();
			if ( 'php' !== $file->getExtension() || preg_match( '#/(tests|vendor|build|node_modules)/#', substr( $path, strlen( $root ) ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source-file read, not a remote URL.
			preg_match_all( '/^\s*function\s+(ec_[a-z0-9_]+)\s*\(/m', (string) file_get_contents( $path ), $matches );
			$functions = array_merge( $functions, $matches[1] );
		}
		sort( $functions );
		return $functions;
	}

	public function test_post_type_resolver_matches_no_post_without_the_runtime_function(): void {
		$result = $this->runSmokeFixture( 'link-pages-post-type-resolver-smoke.php', 'missing' );

		// Never '' (WP_Query reads an empty post_type as 'post') and never the
		// legacy artist_link_page literal.
		$this->assertSame( 'ec_link_page_missing', $result['resolved'] );
	}

	public function test_post_type_resolver_defers_to_the_runtime_function(): void {
		$result = $this->runSmokeFixture( 'link-pages-post-type-resolver-smoke.php', 'resolved' );

		$this->assertSame( 'ec_link_page', $result['resolved'] );
	}
}
