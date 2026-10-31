<?php
/**
 * Qualified root location matching tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\QualifiedRootLocation;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifiedRootLocation.php';

/** Verifies exact U.S. and Canadian hierarchy matching. */
final class QualifiedRootLocationTest extends TestCase {

	/** U.S. states and Canadian provinces use the same hierarchy contract. */
	public function test_matches_us_and_canadian_qualified_roots_by_existing_hierarchy(): void {
		$terms = $this->terms();
		foreach ( array(
			10 => 3,
			11 => 6,
			12 => 7,
		) as $root_id => $canonical_id ) {
			$result = QualifiedRootLocation::match( $terms[ $root_id ], array_values( $terms ), $this->hierarchy_filter() );
			$this->assertSame( 'safe_match', $result['status'] );
			$this->assertSame( $canonical_id, $result['canonical']->term_id );
		}
	}

	/** Duplicate canonical candidates remain report-only. */
	public function test_reports_ambiguous_same_name_city_without_mutation_instruction(): void {
		$terms    = $this->terms();
		$terms[8] = $this->term( 8, 'Charleston', 2 );
		$result   = QualifiedRootLocation::match( $terms[10], array_values( $terms ), $this->hierarchy_filter() );

		$this->assertSame( 'ambiguous', $result['status'] );
		$this->assertNull( $result['canonical'] );
	}

	/** Unresolved candidates and roots with children remain report-only. */
	public function test_reports_unresolved_root_and_rejects_roots_with_children(): void {
		$terms      = $this->terms();
		$unresolved = $this->term( 20, 'Kingston, ZZ', 0 );
		$terms[20]  = $unresolved;
		$result     = QualifiedRootLocation::match( $unresolved, array_values( $terms ), $this->hierarchy_filter() );
		$this->assertSame( 'unresolved', $result['status'] );

		$terms[21] = $this->term( 21, 'District', 10 );
		$result    = QualifiedRootLocation::match( $terms[10], array_values( $terms ), $this->hierarchy_filter() );
		$this->assertSame( 'ambiguous', $result['status'] );
		$this->assertSame( 'candidate_has_children', $result['reason'] );
	}

	/** Build the hierarchy-filter test double. */
	private function hierarchy_filter(): callable {
		$parents = array(
			'SC' => 2,
			'QC' => 5,
			'ON' => 4,
		);
		return static function ( array $matches, string $subdivision ) use ( $parents ): array {
			$parent = $parents[ strtoupper( $subdivision ) ] ?? 0;
			return array_values( array_filter( $matches, static fn( object $term ): bool => $term->parent === $parent ) );
		};
	}

	/**
	 * Build canonical and qualified-root fixtures.
	 *
	 * @return array<int,object>
	 */
	private function terms(): array {
		return array(
			1  => $this->term( 1, 'United States', 0 ),
			2  => $this->term( 2, 'South Carolina', 1 ),
			3  => $this->term( 3, 'Charleston', 2 ),
			4  => $this->term( 4, 'Ontario', 9 ),
			5  => $this->term( 5, 'Quebec', 9 ),
			6  => $this->term( 6, 'Montreal', 5 ),
			7  => $this->term( 7, 'Toronto', 4 ),
			9  => $this->term( 9, 'Canada', 0 ),
			10 => $this->term( 10, 'Charleston, SC', 0 ),
			11 => $this->term( 11, 'Montreal, QC', 0 ),
			12 => $this->term( 12, 'Toronto, ON', 0 ),
		);
	}

	/**
	 * Build one term fixture.
	 *
	 * @param int    $id        Term ID.
	 * @param string $name      Term name.
	 * @param int    $parent_id Parent term ID.
	 */
	private function term( int $id, string $name, int $parent_id ): object {
		return (object) array(
			'term_id' => $id,
			'name'    => $name,
			'parent'  => $parent_id,
			'count'   => 0,
		);
	}
}
