<?php

use PHPUnit\Framework\TestCase;

final class VenueLinkPagesCoordinatedRuntimeTest extends TestCase {
	private function fixture( string $name = 'venue-link-pages-coordinated-runtime.php' ): array {
		$output      = array();
		$status      = 0;
		$environment = '';
		if ( in_array( $name, array( 'venue-link-pages-coordinated-runtime.php', 'venue-link-pages-registration-preflight.php' ), true ) ) {
			$dependency = $this->dependencyPath();
			if ( ! $dependency || ! is_file( $dependency . '/tests/bootstrap.php' ) ) {
				if ( getenv( 'CI' ) || is_dir( dirname( __DIR__ ) . '/.ci' ) ) {
					$this->fail( 'Declared standalone Link Pages CI dependency is missing.' );
				}
				$this->markTestSkipped( 'Optional local standalone checkout is unavailable.' );
			}
			$environment = 'LINK_PAGES_WORKTREE=' . escapeshellarg( $dependency ) . ' ';
		}
		$command = $environment . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/' . $name );
		exec( $command, $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	/** Resolve the declared companion checkout without host-specific paths. */
	private function dependencyPath(): string {
		$candidates = array(
			getenv( 'LINK_PAGES_WORKTREE' ),
			dirname( __DIR__ ) . '/.ci/extrachill-link-pages',
			getenv( 'WP_PLUGIN_DIR' ) ? rtrim( getenv( 'WP_PLUGIN_DIR' ), '/' ) . '/extrachill-link-pages' : '',
		);
		foreach ( $candidates as $candidate ) {
			if ( $candidate && is_file( $candidate . '/tests/bootstrap.php' ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/** A later duplicate is detected before any earlier registry mutates. */
	public function test_provider_registration_preflight_prevents_partial_state(): void {
		$result = $this->fixture( 'venue-link-pages-registration-preflight.php' );
		$this->assertSame( 'duplicate_link_page_operation_provider', $result['error'] );
		$this->assertSame( array(), $result['owners'] );
		$this->assertSame( array( 'events-venues' ), $result['operations'] );
		$this->assertSame( array(), $result['projections'] );
	}

	/** Every Events-owned ability is a deliberate core-runner contract. */
	public function test_ability_inputs_outputs_and_rest_visibility_are_closed_and_deliberate(): void {
		$contracts = $this->fixture( 'venue-link-pages-abilities.php' );
		$this->assertCount( 7, $contracts );
		foreach ( $contracts as $contract ) {
			$this->assertTrue( $contract['input_closed'] );
			$this->assertTrue( $contract['output_closed'] );
			$this->assertTrue( $contract['show_in_rest'] );
		}
	}

	public function test_real_standalone_runtime_provisions_reads_saves_projects_and_delegates_analytics(): void {
		$result = $this->fixture();
		$this->assertTrue( $result['created'] );
		$this->assertSame( array( 'term:7:venue:30' ), $result['owner'] );
		$this->assertSame( 'the-royal-american', $result['slug'] );
		$this->assertTrue( $result['read'] );
		$this->assertTrue( $result['saved'] );
		$this->assertTrue( $result['analytics'] );
		$this->assertSame( 4, $result['analytics_blog'] );
		$this->assertSame( 'venue_action_forbidden', $result['denied'] );
		$this->assertSame( 7, $result['caller_after'] );
		$this->assertSame( 'https://extrachill.link/other-venue-venue-31/', $result['collision_slug'] );
		$this->assertSame( 'venue_link_page_snapshot_save_failed', $result['rollback']['error'] );
		$this->assertSame( '', $result['rollback']['bio'] );
		$this->assertSame( 0, $result['rollback']['final_delta'] );
		$this->assertSame( 0, $result['rollback']['cache_delta'] );
		$this->assertSame( str_repeat( 'c', 64 ), $result['refresh']['version'] );
		$this->assertSame( 7, $result['refresh']['caller'] );
		$this->assertSame( 'venue_action_forbidden', $result['refresh']['revoked'] );
		$this->assertSame( str_repeat( 'd', 64 ), $result['trusted_refresh'] );
		$this->assertSame( 'The Royal American', $result['projection']['title'] );
		$this->assertSame( 'venue', $result['projection']['owner_type'] );
		$this->assertSame( 'MusicVenue', $result['projection']['schema_type'] );
		$this->assertFalse( $result['projection']['has_artist_id'] );
		$this->assertSame( array(), $result['projection']['components'] );
		$this->assertStringContainsString( 'extrch-link-page-socials', $result['social_html'] );
		$this->assertSame( 30, $result['snapshot_source']['venue_term_id'] );
		$this->assertSame( 'link_page_public_snapshot_corrupt', $result['corrupt'] );
		$this->assertSame( array( 'invalid_link_page_owner_object', 404 ), $result['deleted_owner'] );
		$this->assertSame( 'draft', $result['deletion']['status'] );
		$this->assertSame( 'draft_on_owner_deletion', $result['deletion']['audit']['policy'] );
		$this->assertGreaterThanOrEqual( 1, $result['deletion']['cache_delta'] );
		$this->assertSame( array( false, false ), $result['http_functions'] );
		$this->assertSame( $result['final_hooks'] + $result['deletion']['cache_delta'], $result['cache_hooks'] );
	}

	public function test_bootstrap_abilities_and_source_layering_contracts_are_explicit(): void {
		$root      = dirname( __DIR__ );
		$plugin    = file_get_contents( $root . '/extrachill-events.php' );
		$provider  = file_get_contents( $root . '/inc/Providers/VenueLinkPagesProvider.php' );
		$abilities = file_get_contents( $root . '/inc/Abilities/VenueLinkPageAbilities.php' );
		$core      = file_get_contents( $root . '/inc/Core/VenueLinkPages.php' );
		$this->assertStringContainsString( 'Requires Plugins: data-machine, data-machine-events', $plugin );
		$this->assertStringNotContainsString( 'Requires Plugins: data-machine, data-machine-events, extrachill-link-pages', $plugin );
		$this->assertStringContainsString( 'Add the native plugin dependency after Link Pages PR #4', $provider );
		$this->assertStringContainsString( "add_action( 'plugins_loaded', array( self::class, 'initialize' ), 30 )", $provider );
		$this->assertSame( 7, substr_count( $abilities, "'extrachill/" ) );
		$this->assertSame( 1, substr_count( $abilities, "'show_in_rest' => true" ) );
		$this->assertStringNotContainsString( 'register_rest_route', $core . $abilities . $provider );
		$this->assertStringNotContainsString( 'WP_CLI', $core . $abilities . $provider );
		$this->assertStringNotContainsString( 'wp_remote_', $core . $abilities . $provider );
		$this->assertStringNotContainsString( 'MusicGroup', $core . $abilities . $provider );
		$this->assertStringNotContainsString( 'subscribe', strtolower( $core ) );
		$this->assertStringNotContainsString( "add_filter( 'ec_link_page_storage_blog_id'", $provider );
		$this->assertStringContainsString( "'oneOf' => array(", $abilities );
		$this->assertStringContainsString( "'enum' => array( '' )", $abilities );
	}
}
