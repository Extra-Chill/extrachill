<?php

use ExtraChillEvents\Core\LocationIntegrityAuditor;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/LocationIntegrityAuditor.php';

class LocationIntegrityAuditorTest extends TestCase {

	public function test_reports_exact_and_state_qualified_root_overlaps(): void {
		$findings = LocationIntegrityAuditor::audit( $this->terms() );
		$root_findings = array_values( array_filter( $findings, static fn( array $finding ): bool => 'root_city_overlap' === $finding['issue'] ) );

		$this->assertCount( 4, $root_findings );
		$this->assertSame(
			array( 'exact_name', 'state_qualified_name', 'state_qualified_name', 'state_qualified_name' ),
			array_column( $root_findings, 'reason' )
		);
	}

	public function test_reports_same_named_hierarchical_cities_for_operator_review(): void {
		$findings = LocationIntegrityAuditor::audit( $this->terms() );
		$canonical = array_values( array_filter( $findings, static fn( array $finding ): bool => 'canonical_city_overlap' === $finding['issue'] ) );

		$this->assertCount( 1, $canonical );
		$this->assertSame( 'Greensboro', $canonical[0]['candidate_name'] );
		$this->assertSame( 'Greensboro', $canonical[0]['canonical_name'] );
		$this->assertSame( 'exact_name', $canonical[0]['reason'] );
	}

	public function test_does_not_use_fuzzy_city_matching(): void {
		$terms = $this->terms();
		$terms[] = array( 'term_id' => 20, 'name' => 'Charlestown', 'slug' => 'charlestown', 'parent' => 0, 'count' => 2 );
		$terms[] = array( 'term_id' => 21, 'name' => 'North Charleston', 'slug' => 'north-charleston', 'parent' => 2, 'count' => 4 );

		$findings = LocationIntegrityAuditor::audit( $terms );

		$this->assertNotContains( 20, array_column( $findings, 'candidate_id' ) );
		$this->assertNotContains( 21, array_column( $findings, 'canonical_id' ) );
	}

	public function test_ignores_root_regions_and_state_nodes(): void {
		$findings = LocationIntegrityAuditor::audit(
			array(
				array( 'term_id' => 1, 'name' => 'United States', 'slug' => 'united-states', 'parent' => 0, 'count' => 0 ),
				array( 'term_id' => 2, 'name' => 'South Carolina', 'slug' => 'south-carolina', 'parent' => 1, 'count' => 0 ),
				array( 'term_id' => 3, 'name' => 'Charleston', 'slug' => 'charleston', 'parent' => 2, 'count' => 10 ),
			)
		);

		$this->assertSame( array(), $findings );
	}

	private function terms(): array {
		return array(
			array( 'term_id' => 1, 'name' => 'United States', 'slug' => 'united-states', 'parent' => 0, 'count' => 0 ),
			array( 'term_id' => 2, 'name' => 'South Carolina', 'slug' => 'south-carolina', 'parent' => 1, 'count' => 0 ),
			array( 'term_id' => 3, 'name' => 'Georgia', 'slug' => 'georgia', 'parent' => 1, 'count' => 0 ),
			array( 'term_id' => 4, 'name' => 'North Carolina', 'slug' => 'north-carolina', 'parent' => 1, 'count' => 0 ),
			array( 'term_id' => 5, 'name' => 'Charleston', 'slug' => 'charleston', 'parent' => 2, 'count' => 10 ),
			array( 'term_id' => 6, 'name' => 'Isle of Palms', 'slug' => 'isle-of-palms', 'parent' => 2, 'count' => 5 ),
			array( 'term_id' => 7, 'name' => 'Greensboro', 'slug' => 'greensboro-ga', 'parent' => 3, 'count' => 2 ),
			array( 'term_id' => 8, 'name' => 'Greensboro', 'slug' => 'greensboro', 'parent' => 4, 'count' => 8 ),
			array( 'term_id' => 10, 'name' => 'Charleston', 'slug' => 'charleston-old', 'parent' => 0, 'count' => 1 ),
			array( 'term_id' => 11, 'name' => 'Isle of Palms, SC', 'slug' => 'isle-of-palms-sc', 'parent' => 0, 'count' => 1 ),
			array( 'term_id' => 12, 'name' => 'Greensboro, GA', 'slug' => 'greensboro-ga-old', 'parent' => 0, 'count' => 1 ),
		);
	}
}
