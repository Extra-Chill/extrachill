<?php
/**
 * Provided-entries (paid raffle) source tests for extrachill/run-giveaway.
 *
 * @package ExtraChillStudio
 */

/**
 * Verify note parsing, weighted ticket ranges, Venmo CSV import, and draws.
 */
class Test_Giveaway_Entries_Source extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing

	private function row( string $name, $amount, string $note ): array {
		return array(
			'name'   => $name,
			'amount' => $amount,
			'note'   => $note,
		);
	}

	private function rules(): array {
		return ec_studio_normalize_raffle_rules( array() );
	}

	public function test_single_lot_note(): void {
		$this->assertSame( array( 'A' ), ec_studio_classify_raffle_note( 'Lot A', $this->rules() )['lots'] );
		$this->assertSame( array( 'C' ), ec_studio_classify_raffle_note( 'raffle c ❤️', $this->rules() )['lots'] );
		$this->assertSame( array( 'B' ), ec_studio_classify_raffle_note( 'b', $this->rules() )['lots'] );
	}

	public function test_multiple_lot_notes(): void {
		$this->assertSame( array( 'A', 'B' ), ec_studio_classify_raffle_note( 'Lot A + Lot B', $this->rules() )['lots'] );
		$this->assertSame( array( 'B', 'C' ), ec_studio_classify_raffle_note( 'C & B 🎟', $this->rules() )['lots'] );
		$this->assertSame( array( 'A', 'B', 'C' ), ec_studio_classify_raffle_note( 'all three lots!', $this->rules() )['lots'] );
		$this->assertSame( array( 'A', 'C' ), ec_studio_classify_raffle_note( 'lots a and c', $this->rules() )['lots'] );
	}

	public function test_donation_and_empty_notes(): void {
		$this->assertSame( 'donation', ec_studio_classify_raffle_note( 'Donation for Amanda', $this->rules() )['kind'] );
		$this->assertSame( 'donation', ec_studio_classify_raffle_note( '', $this->rules() )['kind'] );
	}

	public function test_unclear_notes(): void {
		$this->assertSame( 'unclear', ec_studio_classify_raffle_note( 'for amanda ❤️', $this->rules() )['kind'] );
		// The article "a" is not lot A.
		$this->assertSame( 'unclear', ec_studio_classify_raffle_note( 'a raffle ticket', $this->rules() )['kind'] );
		// Lot plus donation keyword is a conflict, not a guess.
		$this->assertSame( 'unclear', ec_studio_classify_raffle_note( 'Lot A donation', $this->rules() )['kind'] );
	}

	public function test_lot_aliases_match_prize_names(): void {
		$rules = ec_studio_normalize_raffle_rules( array( 'lot_aliases' => array( 'B' => array( 'Fade to Black' ) ) ) );
		$this->assertSame( array( 'B' ), ec_studio_classify_raffle_note( 'fade to black tattoo pls', $rules )['lots'] );
	}

	public function test_tally_ranges_totals_donations_and_unclear(): void {
		$tally = ec_studio_tally_raffle_entries(
			array(
				$this->row( 'Sam', 20, 'Lot A' ),
				$this->row( 'Jo', '$50.00', 'Lot A + Lot B' ),
				$this->row( 'Al', 15, 'donation' ),
				$this->row( 'Bo', 5, 'Lot C' ),
				$this->row( 'Cy', 30, 'for amanda' ),
				$this->row( 'sam', 10, 'lot a' ),
				$this->row( 'Di', 25, 'A + B' ),
			),
			array()
		);

		$a = $tally['lots']['A'];
		$this->assertSame( 67, $a['total_tickets'] );
		$this->assertSame(
			array(
				array(
					'name'    => 'Sam',
					'tickets' => 30,
					'from'    => 1,
					'to'      => 30,
				),
				array(
					'name'    => 'Jo',
					'tickets' => 25,
					'from'    => 31,
					'to'      => 55,
				),
				array(
					'name'    => 'Di',
					'tickets' => 12,
					'from'    => 56,
					'to'      => 67,
				),
			),
			array_slice( $a['entrants'], 0, 3 )
		);

		$this->assertSame( 37, $tally['lots']['B']['total_tickets'] );
		$this->assertSame( 0, $tally['lots']['C']['total_tickets'] );

		// Al donation 15 + Bo under minimum 5 + Di leftover 1.
		$this->assertEqualsWithDelta( 21.0, $tally['donations']['total'], 0.001 );
		$reasons = array_column( $tally['donations']['rows'], 'reason', 'name' );
		$this->assertSame( 'donation_note', $reasons['Al'] );
		$this->assertSame( 'under_minimum', $reasons['Bo'] );
		$this->assertSame( 'split_leftover', $reasons['Di'] );

		$this->assertSame( array( 'Cy' ), array_column( $tally['unclear'], 'name' ) );
	}

	public function test_a_payment_under_minimum_gets_no_tickets(): void {
		$tally = ec_studio_tally_raffle_entries( array( $this->row( 'Bo', 9.99, 'Lot A' ) ), array() );
		$this->assertSame( 0, $tally['lots']['A']['total_tickets'] );
		$this->assertSame( 'under_minimum', $tally['donations']['rows'][0]['reason'] );
	}

	public function test_rules_are_configurable(): void {
		$tally = ec_studio_tally_raffle_entries(
			array( $this->row( 'Sam', 20, 'Lot X' ) ),
			array(
				'price_per_entry' => 5,
				'minimum'         => 5,
				'lots'            => array( 'X' ),
			)
		);
		$this->assertSame( 4, $tally['lots']['X']['total_tickets'] );
	}

	public function test_venmo_csv_keeps_incoming_payments_only(): void {
		$csv = implode(
			"\n",
			array(
				'Account Statement - (@Haley-Beadle)',
				',ID,Datetime,Type,Status,Note,From,To,Amount (total),Amount (fee)',
				',1,2026-11-21T10:00:00,Payment,Complete,Lot A,Sam S,Haley Beadle,+ $20.00,',
				',2,2026-11-21T10:01:00,Payment,Complete,coffee,Haley Beadle,Joe,- $5.00,',
				',3,2026-11-21T10:02:00,Standard Transfer,Issued,,,,- $100.00,',
				',4,2026-11-21T10:03:00,Charge,Complete,"Lot B, Lot C",Jo J,Haley Beadle,"+ $1,000.00",',
				',,,,,,,,$1015.00,',
			)
		);

		$parsed = ec_studio_parse_venmo_csv( $csv );
		$this->assertSame( array( 'Sam S', 'Jo J' ), array_column( $parsed['rows'], 'name' ) );
		$this->assertEqualsWithDelta( 1000.0, $parsed['rows'][1]['amount'], 0.001 );
		$this->assertSame( 'Lot B, Lot C', $parsed['rows'][1]['note'] );
		$this->assertSame( 3, $parsed['skipped'] );
	}

	public function test_draw_stays_in_range_and_is_not_deterministic(): void {
		$tally = ec_studio_tally_raffle_entries(
			array(
				$this->row( 'Sam', 20, 'Lot A' ),
				$this->row( 'Jo', 30, 'Lot A' ),
				$this->row( 'Di', 50, 'Lot A' ),
			),
			array()
		);

		$tickets = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$winner = ec_studio_draw_raffle_winners( $tally['lots'] )[0];
			$this->assertSame( 'A', $winner['lot'] );
			$this->assertGreaterThanOrEqual( 1, $winner['ticket'] );
			$this->assertLessThanOrEqual( 100, $winner['ticket'] );
			$entrant = array_values( array_filter( $tally['lots']['A']['entrants'], static fn( $e ) => $e['name'] === $winner['name'] ) )[0];
			$this->assertGreaterThanOrEqual( $entrant['from'], $winner['ticket'] );
			$this->assertLessThanOrEqual( $entrant['to'], $winner['ticket'] );
			$tickets[ $winner['ticket'] ] = true;
		}

		// 300 draws over 100 tickets: a deterministic draw would hit one ticket.
		$this->assertGreaterThan( 30, count( $tickets ) );
	}

	public function test_lots_are_independent_by_default_and_distinct_on_request(): void {
		$tally = ec_studio_tally_raffle_entries( array( $this->row( 'Sam', 30, 'all lots' ) ), array() );

		$independent = ec_studio_draw_raffle_winners( $tally['lots'] );
		$this->assertSame( array( 'Sam', 'Sam', 'Sam' ), array_column( $independent, 'name' ) );

		$distinct = ec_studio_draw_raffle_winners( $tally['lots'], true );
		$this->assertSame( array( 'Sam', null, null ), array_column( $distinct, 'name' ) );
	}

	public function test_ability_preview_and_draw_through_entries_source(): void {
		$input = array(
			'source'  => 'entries',
			'entries' => array(
				$this->row( 'Sam', 20, 'Lot A' ),
				$this->row( 'Jo', 50, 'Lot A + Lot B' ),
			),
		);

		$preview = ec_studio_execute_run_giveaway( $input );
		$this->assertSame( 'preview', $preview['mode'] );
		$this->assertSame( array(), $preview['winners'] );
		$this->assertSame( 45, $preview['lots']['A']['total_tickets'] );

		$draw = ec_studio_execute_run_giveaway( $input + array( 'mode' => 'draw' ) );
		$this->assertSame( array( 'A', 'B', 'C' ), array_column( $draw['winners'], 'lot' ) );
		$this->assertSame( 'Jo', $draw['winners'][1]['name'] );
		$this->assertNull( $draw['winners'][2]['name'] );

		$this->assertInstanceOf( WP_Error::class, ec_studio_execute_run_giveaway( array( 'source' => 'entries' ) ) );
		$this->assertInstanceOf( WP_Error::class, ec_studio_execute_run_giveaway( array() ) );
	}
}
