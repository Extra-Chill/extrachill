<?php
/**
 * Artist coverage selection tests.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\ArtistCoverageSync;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/ArtistCoverageSync.php';

/** Covers ID selection, recheck scheduling, and name resolution. */
class ArtistCoverageSyncTest extends TestCase {

	/**
	 * Build a stored match.
	 *
	 * @param string $name     Artist name.
	 * @param string $id       Attraction ID.
	 * @param int    $upcoming Upcoming events.
	 * @param int    $checked  Checked timestamp.
	 * @return array
	 */
	private function match( string $name, string $id, int $upcoming, int $checked = 1000 ): array {
		return array(
			'name'            => $name,
			'id'              => $id,
			'attraction_name' => $name,
			'upcoming'        => $upcoming,
			'checked'         => $checked,
		);
	}

	/** Manual first, then busiest matches; no idle, unmatched, or excluded acts. */
	public function test_select_orders_manual_then_busiest_and_drops_excluded(): void {
		$matches = array(
			1 => $this->match( 'Phish', 'P', 5 ),
			2 => $this->match( 'Bob Dylan', 'B', 18 ),
			3 => $this->match( 'Grateful Dead', 'G', 0 ),
			4 => $this->match( 'Unknown', '', 0 ),
			5 => $this->match( 'Damn Skippy', 'D', 9 ),
		);

		$ids = ArtistCoverageSync::select_attraction_ids( $matches, array( 'M' => 'Manual Act' ), array( 'D' ), 10 );

		$this->assertSame( array( 'M', 'B', 'P' ), $ids );
	}

	/** The cap keeps the busiest acts. */
	public function test_select_caps_list(): void {
		$matches = array(
			1 => $this->match( 'A', 'A', 1 ),
			2 => $this->match( 'B', 'B', 3 ),
			3 => $this->match( 'C', 'C', 2 ),
		);

		$this->assertSame( array( 'B', 'C' ), ArtistCoverageSync::select_attraction_ids( $matches, array(), array(), 2 ) );
	}

	/** Never-checked and renamed artists come first; fresh ones are skipped. */
	public function test_due_artists_orders_unchecked_then_oldest(): void {
		$now     = 10 * WEEK_IN_SECONDS;
		$artists = array(
			1 => 'Fresh',
			2 => 'Stale',
			3 => 'New',
			4 => 'Renamed Now',
		);
		$matches = array(
			1 => $this->match( 'Fresh', 'F', 1, $now - DAY_IN_SECONDS ),
			2 => $this->match( 'Stale', 'S', 1, $now - 2 * WEEK_IN_SECONDS ),
			4 => $this->match( 'Renamed Before', 'R', 1, $now ),
		);

		$this->assertSame( array( 3, 4, 2 ), array_keys( ArtistCoverageSync::due_artists( $artists, $matches, $now ) ) );
	}

	/** Exclusion resolves by artist name, attraction name, manual name, or ID. */
	public function test_find_known_id(): void {
		$matches = array( 7 => array_merge( $this->match( 'Grateful Dead', 'G', 1 ), array( 'attraction_name' => 'The Grateful Dead' ) ) );
		$manual  = array( 'M' => 'Phish' );

		$this->assertSame( 'G', ArtistCoverageSync::find_known_id( 'grateful dead', $matches, $manual ) );
		$this->assertSame( 'G', ArtistCoverageSync::find_known_id( 'The Grateful Dead', $matches, $manual ) );
		$this->assertSame( 'G', ArtistCoverageSync::find_known_id( 'G', $matches, $manual ) );
		$this->assertSame( 'M', ArtistCoverageSync::find_known_id( 'PHISH', $matches, $manual ) );
		$this->assertSame( '', ArtistCoverageSync::find_known_id( 'Nobody', $matches, $manual ) );
	}
}
