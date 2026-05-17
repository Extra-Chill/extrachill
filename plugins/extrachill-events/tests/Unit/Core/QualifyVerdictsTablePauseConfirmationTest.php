<?php
/**
 * Tests for QualifyVerdictsTable::meets_pause_confirmation() +
 * latest_verdicts_for_url().
 *
 * Stubs $wpdb to feed seeded rows to the helpers without booting the WP
 * test framework.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\QualifyVerdictsTable;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyVerdictsTable.php';
require_once __DIR__ . '/Stubs/FakeWpdb.php';

class QualifyVerdictsTablePauseConfirmationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new FakeWpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Helper — seed rows newest-first (matches ORDER BY qualified_at DESC).
	 *
	 * @param array<int,array{verdict:string, hours_ago:int}> $seeds
	 */
	private function seed( array $seeds ): void {
		$rows = array();
		foreach ( $seeds as $i => $seed ) {
			$ts     = gmdate( 'Y-m-d H:i:s', time() - ( (int) $seed['hours_ago'] * HOUR_IN_SECONDS ) );
			$rows[] = array(
				'verdict'      => (string) $seed['verdict'],
				'qualified_at' => $ts,
				'id'           => 1000 - $i,
			);
		}
		$GLOBALS['wpdb']->seed_rows( $rows );
	}

	public function test_returns_false_for_qualified_structured_verdict(): void {
		$table = new QualifyVerdictsTable();
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::QUALIFIED_STRUCTURED, 'hours_ago' => 0 ),
		) );
		$this->assertFalse(
			$table->meets_pause_confirmation( str_repeat( 'a', 40 ), QualifyVerdict::QUALIFIED_STRUCTURED )
		);
	}

	public function test_extraction_gap_requires_two_matching_verdicts_over_48h(): void {
		$table = new QualifyVerdictsTable();
		$hash  = str_repeat( 'b', 40 );

		// Only one row — not enough.
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::EXTRACTION_GAP, 'hours_ago' => 60 ),
		) );
		$this->assertFalse( $table->meets_pause_confirmation( $hash, QualifyVerdict::EXTRACTION_GAP ) );

		// Two rows but oldest is only 12h old — window not satisfied.
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::EXTRACTION_GAP, 'hours_ago' => 0 ),
			array( 'verdict' => QualifyVerdict::EXTRACTION_GAP, 'hours_ago' => 12 ),
		) );
		$this->assertFalse( $table->meets_pause_confirmation( $hash, QualifyVerdict::EXTRACTION_GAP ) );

		// Two rows and oldest is 60h old — confirmed.
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::EXTRACTION_GAP, 'hours_ago' => 1 ),
			array( 'verdict' => QualifyVerdict::EXTRACTION_GAP, 'hours_ago' => 60 ),
		) );
		$this->assertTrue( $table->meets_pause_confirmation( $hash, QualifyVerdict::EXTRACTION_GAP ) );
	}

	public function test_mismatched_verdict_in_window_blocks_pause(): void {
		$table = new QualifyVerdictsTable();
		$hash  = str_repeat( 'c', 40 );

		$this->seed( array(
			array( 'verdict' => QualifyVerdict::EXTRACTION_GAP, 'hours_ago' => 1 ),
			array( 'verdict' => QualifyVerdict::QUALIFIED_STRUCTURED, 'hours_ago' => 50 ),
		) );

		$this->assertFalse( $table->meets_pause_confirmation( $hash, QualifyVerdict::EXTRACTION_GAP ) );
	}

	public function test_bot_blocked_requires_three_matching_over_7_days(): void {
		$table = new QualifyVerdictsTable();
		$hash  = str_repeat( 'd', 40 );

		// Only two rows — short of N=3.
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::BOT_BLOCKED, 'hours_ago' => 1 ),
			array( 'verdict' => QualifyVerdict::BOT_BLOCKED, 'hours_ago' => 200 ),
		) );
		$this->assertFalse( $table->meets_pause_confirmation( $hash, QualifyVerdict::BOT_BLOCKED ) );

		// Three rows but window only 100h — short of 168h.
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::BOT_BLOCKED, 'hours_ago' => 1 ),
			array( 'verdict' => QualifyVerdict::BOT_BLOCKED, 'hours_ago' => 50 ),
			array( 'verdict' => QualifyVerdict::BOT_BLOCKED, 'hours_ago' => 100 ),
		) );
		$this->assertFalse( $table->meets_pause_confirmation( $hash, QualifyVerdict::BOT_BLOCKED ) );

		// Three matching rows and oldest beyond 168h — confirmed.
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::BOT_BLOCKED, 'hours_ago' => 1 ),
			array( 'verdict' => QualifyVerdict::BOT_BLOCKED, 'hours_ago' => 80 ),
			array( 'verdict' => QualifyVerdict::BOT_BLOCKED, 'hours_ago' => 200 ),
		) );
		$this->assertTrue( $table->meets_pause_confirmation( $hash, QualifyVerdict::BOT_BLOCKED ) );
	}

	public function test_reservation_only_pauses_on_single_verdict(): void {
		$table = new QualifyVerdictsTable();
		$hash  = str_repeat( 'e', 40 );
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::RESERVATION_ONLY, 'hours_ago' => 0 ),
		) );
		$this->assertTrue( $table->meets_pause_confirmation( $hash, QualifyVerdict::RESERVATION_ONLY ) );
	}

	public function test_covered_elsewhere_pauses_on_single_verdict(): void {
		$table = new QualifyVerdictsTable();
		$hash  = str_repeat( 'f', 40 );
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::COVERED_ELSEWHERE, 'hours_ago' => 0 ),
		) );
		$this->assertTrue( $table->meets_pause_confirmation( $hash, QualifyVerdict::COVERED_ELSEWHERE ) );
	}

	public function test_empty_url_hash_returns_empty_rows(): void {
		$table = new QualifyVerdictsTable();
		$this->assertSame( array(), $table->latest_verdicts_for_url( '' ) );
	}

	public function test_latest_verdicts_for_url_returns_rows(): void {
		$table = new QualifyVerdictsTable();
		$this->seed( array(
			array( 'verdict' => QualifyVerdict::EXTRACTION_GAP, 'hours_ago' => 1 ),
			array( 'verdict' => QualifyVerdict::EXTRACTION_GAP, 'hours_ago' => 50 ),
		) );

		$rows = $table->latest_verdicts_for_url( str_repeat( 'g', 40 ), 5 );
		$this->assertCount( 2, $rows );
		$this->assertSame( QualifyVerdict::EXTRACTION_GAP, $rows[0]['verdict'] );
	}
}
