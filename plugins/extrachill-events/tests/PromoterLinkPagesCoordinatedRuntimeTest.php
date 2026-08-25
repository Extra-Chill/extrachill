<?php

use PHPUnit\Framework\TestCase;

final class PromoterLinkPagesCoordinatedRuntimeTest extends TestCase {
	private function fixture( string $name ): array {
		$dependency = getenv( 'LINK_PAGES_WORKTREE' );
		if ( ! $dependency || ! is_file( $dependency . '/tests/bootstrap.php' ) ) {
			$this->markTestSkipped( 'Set LINK_PAGES_WORKTREE to the standalone API-v3 checkout.' );
		}
		$output  = array();
		$status  = 0;
		$command = 'LINK_PAGES_WORKTREE=' . escapeshellarg( $dependency ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/' . $name );
		exec( $command, $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	/** A duplicate promoter provider is rejected before any registry mutates. */
	public function test_provider_registration_preflight_prevents_partial_state(): void {
		$result = $this->fixture( 'promoter-link-pages-registration-preflight.php' );
		$this->assertSame( 'duplicate_link_page_operation_provider', $result['error'] );
		$this->assertSame( array(), $result['owners'] );
		$this->assertSame( array( 'events-promoters' ), $result['operations'] );
		$this->assertSame( array(), $result['projections'] );
	}

	public function test_abilities_are_closed_and_deliberately_rest_visible(): void {
		$result = $this->fixture( 'promoter-link-pages-abilities.php' );
		$this->assertCount( 8, $result );
		foreach ( $result as $contract ) {
			$this->assertTrue( $contract['input_closed'] );
			$this->assertTrue( $contract['output_closed'] );
			$this->assertTrue( $contract['show_in_rest'] );
		}
	}

	/** Failed drafting restores owner audit metadata or reports failed compensation. */
	public function test_revocation_draft_failure_compensates_owner_audit_metadata(): void {
		$result = $this->fixture( 'promoter-link-pages-revocation-compensation.php' );
		$this->assertSame( 'promoter_link_page_revocation_draft_failed', $result['restored']['error'] );
		$this->assertSame( array( 'state' => 'before' ), $result['restored']['audit'] );
		$this->assertSame( 'promoter_link_page_revocation_compensation_failed', $result['restore_failed']['error'] );
		$this->assertSame( 'promoter_link_page_revocation_draft_failed', $result['restore_failed']['cause'] );
	}

	public function test_promoter_and_venue_adapters_coordinate_with_stored_public_runtime(): void {
		$result = $this->fixture( 'promoter-link-pages-coordinated-runtime.php' );
		$this->assertTrue( $result['created'] );
		$this->assertSame( array( 'legacy_save' => 1, 'generic_save' => 0, 'generic_create' => 1 ), $result['created_hooks'] );
		$this->assertFalse( $result['winner_created'] );
		$this->assertSame( array( 'legacy_save' => 1, 'generic_save' => 0, 'generic_create' => 0 ), $result['winner_hooks'] );
		$this->assertSame( array( 'term:7:promoter:30' ), $result['owner'] );
		$this->assertTrue( $result['read'] );
		$this->assertTrue( $result['saved'] );
		$this->assertSame( array( 'legacy_save' => 1, 'generic_save' => 1, 'generic_create' => 0 ), $result['saved_hooks'] );
		$this->assertTrue( $result['flat_output_valid'] );
		$this->assertTrue( $result['analytics'] );
		$this->assertSame( 4, $result['analytics_blog'] );
		$this->assertSame( 7, $result['caller_after'] );
		$this->assertSame( array_fill_keys( array( 1, 20, 21, 22, 12 ), 'promoter_authority_forbidden' ), $result['denials'] );
		$this->assertTrue( $result['principals']['allowed'] );
		$this->assertSame( 'promoter_authority_forbidden', $result['principals']['denied'] );
		$this->assertSame( 'promoter_authority_forbidden', $result['principals']['anonymous'] );
		$this->assertTrue( $result['principals']['fallback'] );
		$this->assertSame( 'promoter_link_page_final_hook_failed', $result['final_hook']['error'] );
		$this->assertSame( 'Promoter managed bio.', $result['final_hook']['bio'] );
		$this->assertSame( 'promoter_authority_forbidden', $result['unverified'] );
		$this->assertSame( 2, $result['discovery']['count'] );
		$this->assertSame( array( 30, 31 ), array_column( $result['discovery']['promoters'], 'promoter_term_id' ) );
		$this->assertArrayNotHasKey( 'followers', $result['discovery']['promoters'][0] );
		$this->assertSame( 'promoter_authority_forbidden', $result['revoked_during_lock'] );
		$this->assertSame( 'promoter_authority_forbidden', $result['revoked_before_lock'] );
		$this->assertTrue( $result['revocation_withdrawal'] );
		$this->assertSame( 'promoter_link_page_snapshot_save_failed', $result['rollback']['error'] );
		$this->assertSame( 'Promoter managed bio.', $result['rollback']['bio'] );
		$this->assertSame( array( 'legacy_save' => 0, 'generic_save' => 0, 'generic_create' => 0 ), $result['rollback']['hooks'] );
		$this->assertSame( 'promoter_link_page_snapshot_save_failed', $result['failed_creation']['error'] );
		$this->assertSame( 0, $result['failed_creation']['page_id'] );
		$this->assertSame( array( 'legacy_save' => 0, 'generic_save' => 0, 'generic_create' => 0 ), $result['failed_creation']['hooks'] );
		$this->assertSame( '3:2026-08-24 13:00:00', $result['trusted_version'] );
		$this->assertSame( 'promoter_link_page_refresh_compensation_failed', $result['refresh_compensation'] );
		$this->assertSame( 'promoter_link_page_provision_compensation_failed', $result['provision_compensation'] );
		$this->assertSame( '1:2026-08-24 12:00:00', $result['cross_blog_refresh_version'] );
		$this->assertSame( array(), $result['principal_context'] );
		$this->assertSame( 'publish', $result['membership_status'] );
		$this->assertSame( 'promoter', $result['stored_projection']['body_attributes']['data-extrch-owner-type'] );
		$this->assertSame( 'Organization', $result['stored_projection']['seo']['schema'][0]['@type'] );
		$this->assertSame( 'ProfilePage', $result['stored_projection']['seo']['schema'][1]['@type'] );
		$this->assertArrayNotHasKey( 'data-extrch-artist-id', $result['stored_projection']['body_attributes'] );
		$this->assertArrayNotHasKey( 'data-extrch-venue-id', $result['stored_projection']['body_attributes'] );
		$this->assertSame( 'draft', $result['revoked_status'] );
		$this->assertSame( 'draft', $result['reverified_status'] );
		$this->assertSame( 'draft', $result['deleted_status'] );
		$this->assertSame( 'publish', $result['cross_blog_deleted_status'] );
		$this->assertSame( '', $result['cross_blog_deleted_audit'] );
		$this->assertSame( 'term:7:promoter:31', $result['deleted_audit']['owner_reference'] );
		$this->assertSame( 'draft_on_term_deleted', $result['deleted_audit']['policy'] );
		$this->assertSame( array( 'events-venues', 'events-promoters' ), $result['registries'][0] );
		$this->assertSame( array( 'events-venues', 'events-promoters' ), $result['registries'][1] );
		$this->assertSame( array( false, false ), $result['http_functions'] );
	}

	public function test_layering_contract_has_no_generic_runtime_or_artist_venue_conflation(): void {
		$root  = dirname( __DIR__ );
		$files = file_get_contents( $root . '/inc/Core/PromoterLinkPages.php' ) . file_get_contents( $root . '/inc/Abilities/PromoterLinkPageAbilities.php' ) . file_get_contents( $root . '/inc/Providers/PromoterLinkPagesProvider.php' );
		$runtime_provider = file_get_contents( $root . '/inc/Providers/VenueLinkPagesProvider.php' );
		$this->assertStringNotContainsString( 'register_rest_route', $files );
		$this->assertStringNotContainsString( 'wp_remote_', $files );
		$this->assertStringNotContainsString( 'MusicGroup', $files );
		$this->assertStringNotContainsString( 'MusicVenue', $files );
		$this->assertStringNotContainsString( 'PostalAddress', $files );
		$this->assertStringNotContainsString( 'subscribe', strtolower( $files ) );
		$this->assertSame( 8, substr_count( file_get_contents( $root . '/inc/Abilities/PromoterLinkPageAbilities.php' ), "'extrachill/" ) );
		$this->assertStringContainsString( "add_action( 'plugins_loaded', array( self::class, 'initialize' ), 31 )", $files );
		$this->assertStringContainsString( 'ec_save_link_page_persistence_composed(', $files );
		$this->assertStringContainsString( 'ec_provision_owned_link_page_composed(', $files );
		$this->assertStringNotContainsString( 'ec_save_link_page_persistence_locked(', $files );
		$this->assertStringNotContainsString( 'ec_provision_owned_link_page(', $files );
		$this->assertStringContainsString( "'ec_save_link_page_persistence_composed'       => array( 3, 3 )", $runtime_provider );
		$this->assertStringContainsString( "'ec_provision_owned_link_page_composed'        => array( 6, 4 )", $runtime_provider );
	}
}
