<?php
/** Venue expansion planning, CLI parsing, and reporting tests. */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Abilities\VenueExpansionAbilities;
use ExtraChillEvents\Cli\ExpandVenuesCommand;
use ExtraChillEvents\Core\VenueExpansionRunner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueExpansionRunner.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/VenueExpansionAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/Cli/ExpandVenuesCommand.php';
require_once __DIR__ . '/Stubs/system-task-base-stub.php';

class VenueExpansionPlanTest extends TestCase {
	public function test_city_file_parser_ignores_comments_blanks_and_duplicates(): void {
		$file = tempnam( sys_get_temp_dir(), 'ec-cities-' );
		file_put_contents( $file, "# rollout\n\n Charleston, SC \nAustin, TX\nCharleston, SC\n  # later\n" );
		$this->assertSame( array( 'Charleston, SC', 'Austin, TX' ), ExpandVenuesCommand::parseCitiesFile( $file ) );
		unlink( $file );
	}

	public function test_plan_enforces_city_venue_and_rate_bounds(): void {
		$plan = VenueExpansionAbilities::buildPlan(
			array(
				'cities'               => array( 'A', 'B', 'C', 'D' ),
				'max_cities'           => 3,
				'discovery_budget'     => 2,
				'max_venues_per_city'  => 99,
				'qualification_budget' => 25,
			)
		);
		$this->assertCount( 2, $plan['cities'] );
		$this->assertSame( 20, $plan['bounds']['max_venues_per_city'] );
		$this->assertSame( 25, $plan['rate_budget']['qualification_operations'] );
		$this->assertSame( 20, $plan['items'][0]['qualification_budget'] );
		$this->assertSame( 5, $plan['items'][1]['qualification_budget'] );
		$this->assertSame( 60, $plan['rate_budget']['city_start_interval'] );
		$this->assertNotSame( $plan['items'][0]['scheduled_at'], $plan['items'][1]['scheduled_at'] );
	}

	public function test_dry_run_is_default_and_never_schedules(): void {
		$calls   = 0;
		$ability = new VenueExpansionAbilities(
			static function () use ( &$calls ) {
				++$calls;
				return false;
			}
		);
		$result  = $ability->executeExpand( array( 'city' => 'Austin, TX' ) );
		$this->assertSame( 'plan', $result['mode'] );
		$this->assertTrue( $result['plan']['dry_run'] );
		$this->assertSame( 0, $calls );
	}

	public function test_apply_schedules_exactly_the_prebounded_items(): void {
		$scheduled = array();
		$ability   = new VenueExpansionAbilities(
			static function ( array $items ) use ( &$scheduled ) {
				$scheduled = $items;
				return array(
					'batch_id'     => 'test',
					'batch_job_id' => 9,
					'total'        => count( $items ),
					'scheduled'    => 0,
					'chunk_size'   => 1,
				);
			}
		);
		$result    = $ability->executeExpand(
			array(
				'cities'           => array( 'A', 'B', 'C' ),
				'max_cities'       => 2,
				'discovery_budget' => 2,
				'agent_slug'       => 'extra-chill-bot',
				'apply'            => true,
			)
		);
		$this->assertSame( 'apply', $result['mode'] );
		$this->assertCount( 2, $scheduled );
		$this->assertSame( $result['plan']['items'], $scheduled );
		$this->assertFalse( $result['plan']['dry_run'] );
	}

	public function test_apply_requires_agent_context_for_worker_permissions(): void {
		$ability = new VenueExpansionAbilities( static function () { return false; } );
		$result  = $ability->executeExpand( array( 'city' => 'Austin, TX', 'apply' => true ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agent_context_required', $result->get_error_code() );
	}

	public function test_expansion_task_can_be_loaded_before_registry_hydration(): void {
		$method = new \ReflectionMethod( VenueExpansionAbilities::class, 'ensureExpansionTaskAvailable' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null ) );
		$this->assertTrue( class_exists( \ExtraChillEvents\Steps\VenueExpansion\VenueExpansionSystemTask::class ) );
	}

	public function test_report_schema_preserves_per_city_and_aggregate_evidence(): void {
		$one                                     = VenueExpansionRunner::emptyReport( 'A', 3 );
		$one['status']                           = 'completed';
		$one['counts']['added']                  = 2;
		$one['counts']['rejected']               = 1;
		$one['rejection_reasons']['bot_blocked'] = 1;
		$two                                     = VenueExpansionRunner::emptyReport( 'B', 2 );
		$two['status']                           = 'completed';
		$two['counts']['skipped']                = 4;
		$two['rejection_reasons']['unsupported_source'] = 2;

		$report = VenueExpansionAbilities::aggregateReports( array( $one, $two ), 2 );
		$this->assertSame( VenueExpansionRunner::REPORT_SCHEMA, $report['schema'] );
		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame( 2, $report['counts']['added'] );
		$this->assertSame( 4, $report['counts']['skipped'] );
		$this->assertSame( 1, $report['rejection_reasons']['bot_blocked'] );
		$this->assertCount( 2, $report['cities'] );
	}

	public function test_report_exposes_processing_and_failed_city_state(): void {
		$processing           = VenueExpansionRunner::emptyReport( 'A', 1 );
		$failed               = VenueExpansionRunner::emptyReport( 'B', 1 );
		$failed['status']      = 'failed';
		$report                = VenueExpansionAbilities::aggregateReports( array( $processing, $failed ), 2 );
		$this->assertSame( 'processing', $report['status'] );
		$this->assertSame( 1, $report['city_statuses']['failed'] );
		$this->assertSame( 1, $report['city_statuses']['processing'] );
		$this->assertSame( 1, $report['cities_reported'] );
	}
}
