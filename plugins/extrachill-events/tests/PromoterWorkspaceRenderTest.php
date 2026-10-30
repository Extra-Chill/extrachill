<?php
/**
 * Promoter workspace block render tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

/** Covers the minimized browser render and its concrete management route. */
final class PromoterWorkspaceRenderTest extends TestCase {
	/** Browser rendering never adopts the active execution principal. */
	public function test_promoter_render_uses_browser_actor_and_minimizes_private_venue_context(): void {
		$output  = array();
		$status  = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/venue-settings-promoter-render.php' );
		exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated PHP render fixture.
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );

		$this->assertSame( 2, $result['context']['user']['id'] );
		$this->assertSame( 2, $result['context']['workspace']['actor']['id'] );
		$this->assertSame( 2, $result['calls']['workspace_user_id'] );
		$this->assertFalse( $result['calls']['uses_principal'] );
		$this->assertSame( 0, $result['calls']['private_loader_calls'] );
		foreach ( array( 'venues', 'claim_venues', 'selected_venue', 'booking_url', 'support_events', 'booking_id', 'booking_venue_id' ) as $private_key ) {
			$this->assertArrayNotHasKey( $private_key, $result['context'] );
		}
	}

	/** Malformed promoter-prefixed selections fail before private venue bootstrap. */
	public function test_malformed_promoter_prefix_emits_denied_minimized_context(): void {
		$output  = array();
		$status  = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/venue-settings-promoter-render.php' ) . ' ' . escapeshellarg( 'promoter:not-an-id' );
		exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated PHP render fixture.
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );

		$this->assertSame( 'denied', $result['context']['workspace']['selection']['state'] );
		$this->assertSame( 'invalid', $result['context']['workspace']['selection']['reason'] );
		$this->assertSame( 0, $result['calls']['private_loader_calls'] );
		foreach ( array( 'venues', 'claim_venues', 'selected_venue', 'booking_url', 'support_events' ) as $private_key ) {
			$this->assertArrayNotHasKey( $private_key, $result['context'] );
		}
	}

	/** The generated management URL resolves the registered workspace panel. */
	public function test_promoter_management_url_targets_registered_workspace_route_and_panel(): void {
		$output  = array();
		$status  = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/promoter-management-url.php' );
		exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated PHP URL fixture.
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );

		$this->assertSame( '/venue-settings/', $result['path'] );
		$this->assertSame( 'promoter:30', $result['identity'] );
		$this->assertSame( 'promoter-link-page', $result['fragment'] );
		$router = file_get_contents( dirname( __DIR__ ) . '/inc/core/router-pages.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
		$panel  = file_get_contents( dirname( __DIR__ ) . '/blocks/venue-settings/src/managed-workspace.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
		$this->assertStringContainsString( "add_rewrite_rule( '^venue-settings/?$'", $router );
		$this->assertStringContainsString( 'id="promoter-link-page"', $panel );
	}
}
