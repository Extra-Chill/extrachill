<?php
/**
 * Tests for the requalify-flow CLI path.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Cli\RequalifyFlowCommand;
use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Cli/FlowHelpers.php';
require_once dirname( __DIR__, 3 ) . '/inc/Cli/RequalifyFlowCommand.php';

class RequalifyFlowCommandTest extends TestCase {

	public function test_auto_pause_passes_flow_source_url_for_recheck_scheduling(): void {
		$flow = array(
			'flow_id'    => 42,
			'source_url' => 'https://venue.example/events',
		);

		$command = new class() extends RequalifyFlowCommand {
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
}
