<?php
/**
 * Flow location guard tests: ambiguous location-name handling.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\FlowLocationGuard;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/FlowLocationGuard.php';

/** Covers the pure decision logic behind repair-locations and venue add. */
final class FlowLocationGuardTest extends TestCase {

	private const NORTHAMPTON_MA = 10988;
	private const NORTHAMPTON_PA = 92533;

	/** A name shared by several location terms is ambiguous, not clean. */
	public function test_classifies_shared_name_as_ambiguous(): void {
		$this->assertSame( 'ambiguous_name', $this->classify( 'Northampton' ) );
	}

	/** Unique names and live term IDs stay clean; stale values need repair. */
	public function test_classifies_clean_stale_and_rejected_values(): void {
		$this->assertSame( '', $this->classify( 'Amherst' ) );
		$this->assertSame( '', $this->classify( (string) self::NORTHAMPTON_MA ) );
		$this->assertSame( 'missing_term', $this->classify( '999999' ) );
		$this->assertSame( 'stale_name', $this->classify( 'Atlantis' ) );
		$this->assertSame( 'rejected_value', $this->classify( 'ai_decides' ) );
		$this->assertSame( 'rejected_value', $this->classify( '' ) );
	}

	/** A state-suffixed pipeline name picks the candidate under that state. */
	public function test_state_suffix_picks_matching_parent(): void {
		$picked = $this->pick( 'MA', null );
		$this->assertSame( self::NORTHAMPTON_MA, $picked->term_id );

		$picked = $this->pick( 'PA', null );
		$this->assertSame( self::NORTHAMPTON_PA, $picked->term_id );
	}

	/** Without a state, pipeline coordinates pick the nearby candidate. */
	public function test_coordinates_disambiguate_when_name_has_no_state(): void {
		$picked = $this->pick(
			'',
			array(
				'lat' => 42.3178989,
				'lon' => -72.6311006,
			)
		);
		$this->assertSame( self::NORTHAMPTON_MA, $picked->term_id );
	}

	/** No state and no nearby candidate stays unresolved rather than guessing. */
	public function test_refuses_to_guess_without_state_or_nearby_coordinates(): void {
		$this->assertNull( $this->pick( '', null ) );
		$this->assertNull(
			$this->pick(
				'',
				array(
					'lat' => 32.7765,
					'lon' => -79.9311,
				)
			)
		);
	}

	/** Repair predicate rewrites an ambiguous name; default coerce leaves it. */
	public function test_coerce_rewrites_ambiguous_name_only_with_repair_predicate(): void {
		$config = array(
			'88_bundle_step_2_364' => array(
				'handler_configs' => array(
					'upsert_event' => array( 'taxonomy_location_selection' => 'Northampton' ),
				),
			),
		);

		$default = FlowLocationGuard::coerceUpsertEventLocation( $config, (string) self::NORTHAMPTON_MA );
		$this->assertSame( array(), $default['coerced'] );

		$repair = FlowLocationGuard::coerceUpsertEventLocation(
			$config,
			(string) self::NORTHAMPTON_MA,
			fn( string $value ): bool => '' !== $this->classify( $value )
		);
		$this->assertCount( 1, $repair['coerced'] );
		$this->assertSame(
			(string) self::NORTHAMPTON_MA,
			$repair['config']['88_bundle_step_2_364']['handler_configs']['upsert_event']['taxonomy_location_selection']
		);
	}

	/** Pipeline coordinates come from the Ticketmaster flow's location. */
	public function test_extracts_ticketmaster_coordinates(): void {
		$config = array(
			'88_bundle_step_0_364' => array(
				'handler_configs' => array(
					'ticketmaster' => array( 'location' => '42.3178989,-72.6311006' ),
				),
			),
		);
		$this->assertSame(
			array(
				'lat' => 42.3178989,
				'lon' => -72.6311006,
			),
			FlowLocationGuard::extractPipelineCoordinates( $config )
		);
		$this->assertNull( FlowLocationGuard::extractPipelineCoordinates( array( 'x' => array( 'handler_configs' => array() ) ) ) );
		$this->assertNull( FlowLocationGuard::parseCoordinates( 'not coords' ) );
	}

	/**
	 * Classify against a fixed location index.
	 *
	 * @param string $value Stored selection value.
	 */
	private function classify( string $value ): string {
		$names = array(
			'Northampton' => 2,
			'Amherst'     => 1,
		);
		return FlowLocationGuard::classifySelectionValue(
			$value,
			static fn( int $id ): bool => in_array( $id, array( self::NORTHAMPTON_MA, self::NORTHAMPTON_PA ), true ),
			static fn( string $name ): int => $names[ $name ] ?? 0
		);
	}

	/**
	 * Pick among the two Northampton terms.
	 *
	 * @param string     $state       State abbreviation or ''.
	 * @param array|null $coordinates Pipeline coordinates.
	 */
	private function pick( string $state, ?array $coordinates ) {
		$ma      = (object) array(
			'term_id' => self::NORTHAMPTON_MA,
			'parent'  => 1592,
		);
		$pa      = (object) array(
			'term_id' => self::NORTHAMPTON_PA,
			'parent'  => 1614,
		);
		$parents = array(
			1592 => 'Massachusetts',
			1614 => 'Pennsylvania',
		);
		$coords  = array(
			self::NORTHAMPTON_MA => array(
				'lat' => 42.3178989,
				'lon' => -72.6311006,
			),
		);

		return FlowLocationGuard::pickLocationCandidate(
			array( $pa, $ma ),
			$state,
			$coordinates,
			static fn( $c ): string => $parents[ $c->parent ] ?? '',
			static fn( $c ): ?array => $coords[ $c->term_id ] ?? null
		);
	}
}
