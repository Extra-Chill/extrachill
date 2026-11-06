<?php
/**
 * A to Z door list order (extrachill#1142).
 *
 * Covers extrachill_events_sort_guest_rows(): case- and accent-insensitive
 * natural order by guest_name with display-name fallback, "+N" rows kept
 * right after their primary, owner's-list rows interleaved, and a stable
 * order for duplicate names.
 *
 * @package ExtraChillEvents\Tests
 */

require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-sort.php';

use PHPUnit\Framework\TestCase;

final class GuestListSortTest extends TestCase {

	/**
	 * Display names of sorted rows.
	 *
	 * @param array[] $rows Rows.
	 * @return string[]
	 */
	private function names( array $rows ): array {
		return array_map( 'extrachill_events_guest_row_name', extrachill_events_sort_guest_rows( $rows ) );
	}

	public function test_sorts_case_insensitively_and_naturally(): void {
		$rows = array(
			array( 'guest_name' => 'zoe' ),
			array( 'guest_name' => 'Amy' ),
			array( 'guest_name' => 'bob' ),
			array( 'guest_name' => 'Émile' ),
			array( 'guest_name' => 'Band 10' ),
			array( 'guest_name' => 'Band 2' ),
		);

		$this->assertSame( array( 'Amy', 'Band 2', 'Band 10', 'bob', 'Émile', 'zoe' ), $this->names( $rows ) );
	}

	public function test_falls_back_to_display_name(): void {
		$rows = array(
			array(
				'kind'         => 'attendee',
				'display_name' => 'Carla',
			),
			array( 'guest_name' => 'Ben' ),
			array(
				'guest_name'   => '',
				'display_name' => 'alex',
			),
		);

		$this->assertSame( array( 'alex', 'Ben', 'Carla' ), $this->names( $rows ) );
	}

	public function test_plus_one_follows_its_primary_even_when_it_would_sort_elsewhere(): void {
		$rows = array(
			array(
				'guest_name' => 'Jake+1',
				'pass_id'    => 4,
			),
			array(
				'guest_name' => 'Jake & Co',
				'pass_id'    => 2,
			),
			array(
				'guest_name' => 'jake +2',
				'pass_id'    => 5,
			),
			array(
				'guest_name' => 'Jake',
				'pass_id'    => 3,
			),
			array(
				'guest_name' => 'Aaron',
				'pass_id'    => 1,
			),
		);

		// By its own name "Jake+1" would land after "Jake & Co" ('+' > '&').
		$this->assertGreaterThan( 0, strnatcasecmp( 'jake+1', 'jake & co' ) );

		$sorted = extrachill_events_sort_guest_rows( $rows );
		$this->assertSame( array( 'Aaron', 'Jake', 'Jake+1', 'jake +2', 'Jake & Co' ), array_map( 'extrachill_events_guest_row_name', $sorted ) );
		$this->assertSame( array( 0, 1, 1, 1, 4 ), array_column( $sorted, 'door_group' ), 'A party shares its primary\'s group.' );
	}

	public function test_plus_one_without_a_primary_sorts_by_its_base_name(): void {
		$rows = array(
			array( 'guest_name' => 'Zed' ),
			array( 'guest_name' => 'Mona +1' ),
			array( 'guest_name' => 'Abe' ),
		);

		$this->assertSame( array( 'Abe', 'Mona +1', 'Zed' ), $this->names( $rows ) );
	}

	public function test_owner_list_rows_sort_in_by_name(): void {
		$rows = array(
			array(
				'kind'         => 'attendee',
				'display_name' => 'Nina',
			),
			array(
				'kind'       => 'guest',
				'guest_name' => 'Pete',
				'label'      => 'Pete (Slim S.O.U.L.)',
			),
			array(
				'kind'       => 'guest',
				'guest_name' => 'Dee',
				'label'      => 'Dee (Venue list)',
				'owner_list' => true,
			),
			array(
				'kind'       => 'guest',
				'guest_name' => 'Ole',
				'label'      => 'Ole (Promoter list)',
				'owner_list' => true,
			),
		);

		$sorted = extrachill_events_sort_guest_rows( $rows );
		$this->assertSame( array( 'Dee', 'Nina', 'Ole', 'Pete' ), array_map( 'extrachill_events_guest_row_name', $sorted ) );
		$this->assertSame( array( 'Dee (Venue list)', null, 'Ole (Promoter list)', 'Pete (Slim S.O.U.L.)' ), array_map( static fn( $row ) => $row['label'] ?? null, $sorted ), 'Rows keep every field.' );
	}

	public function test_duplicate_names_break_ties_by_pass_id_then_input_order(): void {
		$rows = array(
			array(
				'guest_name' => 'Sam',
				'code'       => 'C',
				'pass_id'    => 30,
			),
			array(
				'guest_name' => 'sam',
				'code'       => 'A',
				'pass_id'    => 10,
			),
			array(
				'guest_name' => 'Sam',
				'code'       => 'B',
				'pass_id'    => 20,
			),
			array(
				'guest_name' => 'SAM',
				'code'       => 'X',
			),
			array(
				'guest_name' => 'Sam',
				'code'       => 'Y',
			),
		);

		$sorted = extrachill_events_sort_guest_rows( $rows );
		$this->assertSame( array( 'X', 'Y', 'A', 'B', 'C' ), array_column( $sorted, 'code' ) );
	}

	public function test_empty_list(): void {
		$this->assertSame( array(), extrachill_events_sort_guest_rows( array() ) );
	}
}
