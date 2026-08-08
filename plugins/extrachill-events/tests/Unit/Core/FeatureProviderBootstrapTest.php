<?php
/**
 * Feature-provider bootstrap matrix coverage.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/** Verifies site, CLI, idempotence, and optional dependency boundaries. */
final class FeatureProviderBootstrapTest extends TestCase {

	/** Owner-site requests retain the complete public and ingestion surfaces. */
	public function test_owner_site_boots_public_and_ingestion_features(): void {
		$result = $this->run_fixture( 'owner' );

		$this->assertTrue( $result['owner_site'] );
		$this->assertTrue( $result['public_registered'] );
		$this->assertTrue( $result['ingestion_hooked'] );
		$this->assertTrue( $result['artist_url_registered'] );
		$this->assertTrue( $result['booking_registered'] );
		$this->assertTrue( $result['ability_lifecycle_hooked'] );
	}

	/** Unrelated subsites load safe hooks while owner-only callbacks remain guarded. */
	public function test_unrelated_subsite_retains_registration_without_claiming_ownership(): void {
		$result = $this->run_fixture( 'unrelated' );

		$this->assertFalse( $result['owner_site'] );
		$this->assertTrue( $result['public_registered'] );
		$this->assertTrue( $result['ingestion_hooked'] );
		$this->assertTrue( $result['ability_lifecycle_hooked'] );
		$this->assertTrue( $result['artist_url_registered'] );
		$this->assertTrue( $result['booking_registered'] );
	}

	/** CLI registration remains available and provider calls are idempotent. */
	public function test_cli_boot_registers_commands_once(): void {
		$result = $this->run_fixture( 'cli' );

		$this->assertSame( 14, $result['cli_commands'] );
		$this->assertSame(
			array(
				'extrachill events ramp',
				'extrachill-events add-city',
				'extrachill-events expand',
				'extrachill-events expand-report',
				'extrachill venues qualify-stats',
				'extrachill venues requalify-pending',
				'extrachill venues requalify-flow',
				'extrachill venues unqualifiable-flows',
				'extrachill events flows audit-pipelines',
				'extrachill events flows repair-locations',
				'extrachill events locations prune-orphans',
				'extrachill events locations audit-integrity',
				'extrachill events venues backfill-meta',
				'extrachill events backfill-authorship',
			),
			$result['cli_command_names']
		);
		$this->assertSame( $result['hooks_before_repeat'], $result['hooks_after_repeat'] );
	}

	/** A missing optional sibling does not suppress unrelated providers. */
	public function test_optional_dependency_absence_does_not_suppress_other_features(): void {
		$result = $this->run_fixture( 'optional-absent' );

		$this->assertFalse( $result['optional_dependency_seen'] );
		$this->assertFalse( $result['optional_scheduler_seen'] );
		$this->assertFalse( $result['optional_users_seen'] );
		$this->assertTrue( $result['optional_loader_present'] );
		$this->assertTrue( $result['artist_url_registered'] );
		$this->assertTrue( $result['booking_registered'] );
		$this->assertTrue( $result['public_registered'] );
		$this->assertTrue( $result['ingestion_hooked'] );
	}

	/** A provider exception is reported while later independent providers still boot. */
	public function test_provider_failure_does_not_suppress_later_features(): void {
		$result = $this->run_fixture( 'provider-throwing' );

		$this->assertSame( array( 'ingestion' ), $result['provider_failures'] );
		$this->assertFalse( $result['ingestion_hooked'] );
		$this->assertTrue( $result['artist_url_registered'] );
		$this->assertTrue( $result['booking_registered'] );
		$this->assertTrue( $result['public_registered'] );
		$this->assertTrue( $result['ability_lifecycle_hooked'] );
	}

	/** Public hook names remain unchanged across the structural extraction. */
	public function test_public_hook_contracts_are_unchanged(): void {
		$result = $this->run_fixture( 'owner' );

		$this->assertSame(
			array(
				'extrachill_homepage_content',
				'extrachill_template_archive',
				'template_redirect',
				'admin_post_ec_accept_venue_invitation',
				'admin_post_nopriv_ec_accept_venue_invitation',
				'ec_points_sources',
			),
			$result['public_contract_hooks']
		);
	}

	/** Provider composition keeps unconditional features ahead of the optional adapter. */
	public function test_provider_order_is_explicit(): void {
		$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/inc/Core/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.

		$this->assertIsString( $bootstrap );
		$cli       = strpos( $bootstrap, 'CliProvider::register()' );
		$ingestion = strpos( $bootstrap, 'IngestionProvider::register()' );
		$core      = strpos( $bootstrap, 'CoreRuntimeProvider::register()' );
		$artist    = strpos( $bootstrap, 'ArtistUrlImportProvider::register()' );
		$booking   = strpos( $bootstrap, 'VenueBookingProvider::register()' );
		$public    = strpos( $bootstrap, 'PublicExperienceProvider::register()' );
		$abilities = strpos( $bootstrap, 'AbilitiesProvider::register()' );
		$optional  = strpos( $bootstrap, 'DataMachineEventsProvider::register()' );

		$this->assertIsInt( $cli );
		$this->assertIsInt( $ingestion );
		$this->assertIsInt( $core );
		$this->assertIsInt( $artist );
		$this->assertIsInt( $booking );
		$this->assertIsInt( $public );
		$this->assertIsInt( $abilities );
		$this->assertIsInt( $optional );
		$this->assertLessThan( $ingestion, $cli );
		$this->assertLessThan( $public, $ingestion );
		$this->assertLessThan( $artist, $core );
		$this->assertLessThan( $booking, $artist );
		$this->assertLessThan( $public, $booking );
		$this->assertLessThan( $abilities, $public );
		$this->assertLessThan( $optional, $public );
	}

	/** The entrypoint remains a composition manifest rather than a feature owner. */
	public function test_entrypoint_cannot_regress_to_central_feature_loading(): void {
		$entrypoint = file_get_contents( dirname( __DIR__, 3 ) . '/extrachill-events.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local architecture contract.

		$this->assertIsString( $entrypoint );
		$this->assertLessThanOrEqual( 70, substr_count( $entrypoint, "\n" ) + 1 );
		$this->assertSame( 1, substr_count( $entrypoint, "'/inc/Core/" ) );
		$this->assertStringContainsString( "'/inc/Core/Plugin.php'", $entrypoint );
		$this->assertStringNotContainsString( "'/inc/Abilities/", $entrypoint );
		$this->assertStringNotContainsString( "'/inc/admin/", $entrypoint );
		$this->assertStringNotContainsString( 'add_action(', $entrypoint );
		$this->assertStringNotContainsString( 'add_filter(', $entrypoint );
	}

	/**
	 * Execute one isolated bootstrap scenario.
	 *
	 * @param string $scenario Fixture scenario name.
	 * @return array<string, mixed>
	 */
	private function run_fixture( string $scenario ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/fixtures/bootstrap-matrix.php' ) . ' ' . escapeshellarg( $scenario );
		$output  = array();
		$status  = 0;
		exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated bootstrap process is the behavior under test.

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );

		return $result;
	}
}
