<?php
/**
 * Qualification runs the admin-only scraper ability as a trusted internal
 * caller, so non-admin submitters are not silently qualified as zero events
 * (extrachill-events#927).
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyFingerprinter;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyVerdict.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyFingerprinter.php';

/**
 * Minimal WP_Ability double: denies by default and consults the core
 * `wp_ability_permission_result` filter exactly like WP_Ability::check_permissions().
 */
final class PermissionGatedScraperAbility {
	public int $executions = 0;

	/** @var array<int, mixed> */
	public array $inputs = array();

	public function get_name(): string {
		return 'data-machine-events/test-event-scraper';
	}

	public function check_permissions( $input = null ) {
		return apply_filters( 'wp_ability_permission_result', false, $this->get_name(), $input, $this );
	}

	public function execute( $input = null ) {
		if ( true !== $this->check_permissions( $input ) ) {
			return new \WP_Error( 'ability_invalid_permissions', 'Denied.' );
		}
		++$this->executions;
		$this->inputs[] = $input;
		$result         = array(
			'success'         => true,
			'extraction_info' => array(
				'extraction_method' => 'seated',
				'payload_type'      => 'event',
			),
			'event_data'      => array(
				'items' => array(
					array( 'title' => 'One' ),
					array( 'title' => 'Two' ),
					array( 'title' => 'Three' ),
				),
			),
		);
		if ( is_array( $input ) && 'raw' === ( $input['output_mode'] ?? '' ) ) {
			$result['output_mode'] = 'raw';
			$result['raw_events']  = array(
				array( 'title' => 'One', 'venue' => 'The Room' ),
				array( 'title' => 'Two', 'venue' => 'The Room' ),
				array( 'title' => 'Three', 'venue' => 'The Room' ),
				'not-an-event',
			);
			$result['packets']     = array(
				array(
					'type'     => 'event_import',
					'data'     => array( 'body' => '{"event":{"title":"One"}}' ),
					'metadata' => array( 'source_type' => 'universal_web_scraper', 'extraction_method' => 'seated' ),
				),
			);
		}
		return $result;
	}
}

class QualifyFingerprinterScraperPermissionTest extends TestCase {
	private PermissionGatedScraperAbility $ability;

	protected function setUp(): void {
		parent::setUp();
		$this->ability                       = new PermissionGatedScraperAbility();
		$ability                             = $this->ability;
		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) use ( $ability ) {
			return 'data-machine-events/test-event-scraper' === $name ? $ability : null;
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_test_ability_resolver'] );
		parent::tearDown();
	}

	public function test_extractor_attempt_runs_admin_only_scraper_for_non_admin_caller(): void {
		$attempt = QualifyFingerprinter::run_extractor_attempt( 'https://artist.test/tour' );

		$this->assertTrue( $attempt['ran'] );
		$this->assertTrue( $attempt['matched'] );
		$this->assertSame( 3, $attempt['events'] );
		$this->assertSame( 1, $this->ability->executions );
		$this->assertArrayNotHasKey( 'error', $attempt );
	}

	public function test_extractor_attempt_requests_raw_output_and_keeps_internal_scrape(): void {
		$attempt = QualifyFingerprinter::run_extractor_attempt( 'https://artist.test/tour' );

		$this->assertSame( 'raw', $this->ability->inputs[0]['output_mode'] );
		$this->assertSame( 'https://artist.test/tour', $this->ability->inputs[0]['target_url'] );
		$this->assertSame( 3, $attempt['events'] );
		$this->assertSame(
			array(
				'raw_events'      => array(
					array( 'title' => 'One', 'venue' => 'The Room' ),
					array( 'title' => 'Two', 'venue' => 'The Room' ),
					array( 'title' => 'Three', 'venue' => 'The Room' ),
				),
				'source_metadata' => array( 'source_type' => 'universal_web_scraper', 'extraction_method' => 'seated' ),
			),
			$attempt['_scrape']
		);
	}

	public function test_extractor_attempt_has_no_scrape_without_raw_output(): void {
		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) {
			return 'data-machine-events/test-event-scraper' === $name ? new class() {
				public function get_name(): string {
					return 'data-machine-events/test-event-scraper';
				}
				public function execute( $input = null ) {
					return array(
						'success'         => true,
						'extraction_info' => array( 'payload_type' => 'event' ),
						'event_data'      => array( 'items' => array( array( 'title' => 'One' ) ) ),
					);
				}
			} : null;
		};

		$attempt = QualifyFingerprinter::run_extractor_attempt( 'https://artist.test/tour' );

		$this->assertSame( 1, $attempt['events'] );
		$this->assertArrayNotHasKey( '_scrape', $attempt );
	}

	public function test_permission_grant_does_not_outlive_the_call(): void {
		QualifyFingerprinter::run_extractor_attempt( 'https://artist.test/tour' );

		$this->assertFalse( $this->ability->check_permissions() );
		$this->assertInstanceOf( \WP_Error::class, $this->ability->execute() );
	}

	public function test_permission_grant_is_scoped_to_the_scraper_ability(): void {
		$other_result = null;
		$probe        = static function ( $permission, $name ) use ( &$other_result ) {
			if ( 'some/other-ability' === $name ) {
				$other_result = $permission;
			}
			return $permission;
		};
		add_filter( 'wp_ability_permission_result', $probe, 20, 2 );

		// Evaluate another ability's permission while the scraper call is in flight.
		$GLOBALS['ec_test_ability_resolver'] = function ( $name ) {
			return 'data-machine-events/test-event-scraper' === $name ? new class() {
				public function get_name(): string {
					return 'data-machine-events/test-event-scraper';
				}
				public function execute( $input = null ) {
					apply_filters( 'wp_ability_permission_result', false, 'some/other-ability', $input, $this );
					return new \WP_Error( 'stop', 'stop' );
				}
			} : null;
		};

		$attempt = QualifyFingerprinter::run_extractor_attempt( 'https://artist.test/tour' );
		remove_filter( 'wp_ability_permission_result', $probe, 20 );

		$this->assertFalse( $other_result );
		$this->assertSame( 'stop', $attempt['error'] );
		$this->assertSame( 0, $attempt['events'] );
	}
}
