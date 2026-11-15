<?php
/**
 * Tests for the venue re-qualification service.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\VenueRequalifier;
use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/FlowHelpers.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueRequalifier.php';

class VenueRequalifierTest extends TestCase {

	public function test_auto_pause_passes_flow_source_url_for_recheck_scheduling(): void {
		$flow = array(
			'flow_id'    => 42,
			'source_url' => 'https://venue.example/events',
		);

		$command = new class() extends VenueRequalifier {
			public array $paused = array();

			public function pause_from_requalification( array $flow, string $verdict ): bool {
				return $this->pause_requalified_flow( $flow, $verdict );
			}

			protected function pause_flow_by_verdict( int $flow_id, string $verdict, string $source_url = '' ): bool {
				$this->paused = compact( 'flow_id', 'verdict', 'source_url' );
				return true;
			}
		};
		$command->pause_from_requalification( $flow, QualifyVerdict::UNSUPPORTED_SOURCE );

		$this->assertSame(
			array(
				'flow_id'    => 42,
				'verdict'    => QualifyVerdict::UNSUPPORTED_SOURCE,
				'source_url' => 'https://venue.example/events',
			),
			$command->paused
		);
	}

	public function test_apply_repair_requires_explicit_confirmation(): void {
		$this->assertTrue( VenueRequalifier::validate_audit_options( false, false, false ) );
		$this->assertTrue( VenueRequalifier::validate_audit_options( true, false, true ) );

		$unconfirmed = VenueRequalifier::validate_audit_options( true, false, false );
		$this->assertInstanceOf( \WP_Error::class, $unconfirmed );
		$this->assertStringContainsString( '--apply-repair requires --yes', $unconfirmed->get_error_message() );
		$this->assertInstanceOf( \WP_Error::class, VenueRequalifier::validate_audit_options( true, true, true ) );
	}

	public function test_requalify_flows_dry_run_reports_without_qualifying(): void {
		$requalifier = new class() extends VenueRequalifier {
			protected function qualify_ability() {
				return null;
			}
		};
		$flow        = array(
			'flow_id'    => 7,
			'flow_name'  => 'Venue',
			'source_url' => 'https://venue.example/events',
		);

		$rows = $requalifier->requalify_flows( array( $flow ), true, true );
		$this->assertSame( 'would_requalify', $rows[0]['action'] );
		$this->assertSame( 'https://venue.example/events', $rows[0]['source_url'] );

		$error = $requalifier->requalify_flows( array( $flow ), false, true );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'extrachill/qualify-venue ability not available.', $error->get_error_message() );
	}
}
