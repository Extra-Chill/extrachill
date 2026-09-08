<?php
/**
 * Tests for the ClassifyVenueTierCommand pure heuristic scorer.
 *
 * score(), detect_ticket_platform(), and extract_name_tokens() are pure
 * static methods (no WordPress dependencies), so the tier rules behind
 * the venue tier proposal are asserted without a WP test fixture.
 *
 * @package ExtraChillEvents\Tests\Unit\Cli
 */

namespace ExtraChillEvents\Tests\Unit\Cli;

use ExtraChillEvents\Cli\ClassifyVenueTierCommand;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Cli/ClassifyVenueTierCommand.php';

class ClassifyVenueTierScoreTest extends TestCase {

	/**
	 * A signals array with everything neutral, at/above min-events.
	 *
	 * @return array<string,mixed>
	 */
	private function neutral_signals(): array {
		return array(
			'total_events'           => 10,
			'events_per_week'        => 0.2,
			'priced_ratio'           => 0.0,
			'ticketed_ratio'         => 0.0,
			'platform_ratio'         => 0.0,
			'repeat_performer_rate'  => 0.0,
			'performer_coverage'     => 0.0,
			'time_range_title_ratio' => 0.0,
			'early_start_ratio'      => 0.0,
			'name_bar_tokens'        => 0,
			'name_hall_tokens'       => 0,
			'name_amphitheater'      => false,
			'name_listening'         => false,
			'capacity'               => 0,
			'min_events'             => 3,
		);
	}

	public function test_too_few_events_proposes_nothing(): void {
		$signals            = $this->neutral_signals();
		$signals['total_events'] = 2;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( '', $result['tier'] );
		$this->assertSame( 0.0, $result['confidence'] );
		$this->assertNotEmpty( $result['reasons'] );
	}

	public function test_min_events_respected(): void {
		$signals                 = $this->neutral_signals();
		$signals['total_events'] = 4;
		$signals['min_events']   = 5;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( '', $result['tier'] );
	}

	public function test_ambiguous_neutral_signals_propose_nothing(): void {
		$result = ClassifyVenueTierCommand::score( $this->neutral_signals() );

		$this->assertSame( '', $result['tier'] );
		$this->assertSame( 0.0, $result['confidence'] );
	}

	public function test_amphitheater_name_wins_over_other_signals(): void {
		$signals                     = $this->neutral_signals();
		$signals['name_amphitheater'] = true;
		$signals['platform_ratio']   = 0.5;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'amphitheater', $result['tier'] );
		$this->assertGreaterThanOrEqual( 0.7, $result['confidence'] );
		$this->assertLessThanOrEqual( 0.95, $result['confidence'] );
	}

	public function test_amphitheater_cap_bounded(): void {
		$signals                     = $this->neutral_signals();
		$signals['name_amphitheater'] = true;
		$signals['platform_ratio']   = 0.5;
		$signals['capacity']         = 20000;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'amphitheater', $result['tier'] );
		$this->assertLessThanOrEqual( 0.95, $result['confidence'] );
	}

	public function test_large_capacity_implies_amphitheater(): void {
		$signals           = $this->neutral_signals();
		$signals['capacity'] = 5000;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'amphitheater', $result['tier'] );
	}

	public function test_hall_name_with_ticketing_evidence_is_concert_hall(): void {
		$signals                    = $this->neutral_signals();
		$signals['name_hall_tokens'] = 1;
		$signals['platform_ratio']  = 0.4;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'concert_hall', $result['tier'] );
		$this->assertGreaterThanOrEqual( 0.65, $result['confidence'] );
		$this->assertLessThanOrEqual( 0.95, $result['confidence'] );
	}

	public function test_hall_name_alone_is_not_concert_hall(): void {
		// "Hall" in the name without any business evidence must fall
		// through — the same name-token discipline as bar tokens.
		$signals                    = $this->neutral_signals();
		$signals['name_hall_tokens'] = 1;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( '', $result['tier'] );
	}

	public function test_large_room_capacity_is_concert_hall(): void {
		$signals              = $this->neutral_signals();
		$signals['capacity'] = 1200;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'concert_hall', $result['tier'] );
	}

	public function test_dinghy_profile_is_bar_gig(): void {
		// The #91 profile: ~2 sets/day, same rotating acts, titles like
		// "Derek Cribb 6-9pm", afternoon starts, no ticketing evidence.
		$signals                          = $this->neutral_signals();
		$signals['events_per_week']       = 8.0;
		$signals['repeat_performer_rate'] = 0.6;
		$signals['performer_coverage']    = 0.9;
		$signals['time_range_title_ratio'] = 0.4;
		$signals['early_start_ratio']     = 0.6;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'bar_gig', $result['tier'] );
		$this->assertGreaterThanOrEqual( 0.7, $result['confidence'] );
		$this->assertLessThanOrEqual( 0.9, $result['confidence'] );
	}

	public function test_bar_gig_requires_volume(): void {
		$signals                          = $this->neutral_signals();
		$signals['events_per_week']       = 1.0;
		$signals['repeat_performer_rate'] = 0.6;
		$signals['performer_coverage']    = 0.9;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertNotSame( 'bar_gig', $result['tier'] );
	}

	public function test_bar_gig_requires_a_repetition_signal(): void {
		$signals                    = $this->neutral_signals();
		$signals['events_per_week'] = 8.0;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertNotSame( 'bar_gig', $result['tier'] );
	}

	public function test_bar_gig_blocked_by_platform_evidence(): void {
		// High volume + repetition but a real ticketing presence: the
		// ticketing evidence must win and the venue should not be called
		// a bar gig.
		$signals                          = $this->neutral_signals();
		$signals['events_per_week']       = 8.0;
		$signals['repeat_performer_rate'] = 0.6;
		$signals['performer_coverage']    = 0.9;
		$signals['platform_ratio']        = 0.4;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'club', $result['tier'] );
	}

	public function test_cafe_name_alone_never_bar_gig(): void {
		// The #801 acceptance check: The Bluebird Cafe-style name must not
		// be misclassified purely by name tokens.
		$signals                     = $this->neutral_signals();
		$signals['name_bar_tokens'] = 1;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertNotSame( 'bar_gig', $result['tier'] );
	}

	public function test_cafe_name_with_music_first_evidence_is_listening_room(): void {
		$signals                     = $this->neutral_signals();
		$signals['name_bar_tokens'] = 1;
		$signals['platform_ratio']  = 0.3;
		$signals['events_per_week'] = 2.0;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'listening_room', $result['tier'] );
		$this->assertLessThanOrEqual( 0.85, $result['confidence'] );
	}

	public function test_explicit_listening_room_name(): void {
		$signals                    = $this->neutral_signals();
		$signals['name_listening'] = true;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'listening_room', $result['tier'] );
	}

	public function test_cafe_name_at_high_volume_with_platform_falls_to_club(): void {
		// A brewpub running ticketed shows nightly is a club, not a
		// listening room: the moderate-volume gate keeps R4 away.
		$signals                     = $this->neutral_signals();
		$signals['name_bar_tokens'] = 1;
		$signals['platform_ratio']  = 0.4;
		$signals['events_per_week'] = 10.0;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'club', $result['tier'] );
	}

	public function test_platform_evidence_alone_is_club(): void {
		$signals                    = $this->neutral_signals();
		$signals['platform_ratio'] = 0.3;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'club', $result['tier'] );
		$this->assertLessThanOrEqual( 0.85, $result['confidence'] );
	}

	public function test_priced_ticketed_with_rotating_acts_is_club(): void {
		$signals                          = $this->neutral_signals();
		$signals['priced_ratio']          = 0.4;
		$signals['ticketed_ratio']        = 0.5;
		$signals['repeat_performer_rate'] = 0.3;
		$signals['performer_coverage']    = 0.8;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'club', $result['tier'] );
	}

	public function test_priced_without_rotation_is_not_club(): void {
		$signals                   = $this->neutral_signals();
		$signals['priced_ratio']  = 0.4;
		$signals['ticketed_ratio'] = 0.5;
		// No performer data at all -> coverage 0, rotation unknown.
		$signals['performer_coverage'] = 0.0;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( '', $result['tier'] );
	}

	public function test_rule_priority_amphitheater_beats_bar_behavior(): void {
		$signals                          = $this->neutral_signals();
		$signals['name_amphitheater']     = true;
		$signals['events_per_week']       = 9.0;
		$signals['repeat_performer_rate'] = 0.8;
		$signals['performer_coverage']    = 1.0;
		$signals['early_start_ratio']     = 0.9;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'amphitheater', $result['tier'] );
	}

	public function test_rule_priority_concert_hall_beats_club_platform(): void {
		$signals                     = $this->neutral_signals();
		$signals['name_hall_tokens'] = 1;
		$signals['platform_ratio']   = 0.5;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'concert_hall', $result['tier'] );
	}

	public function test_confidence_never_exceeds_one(): void {
		$signals                          = $this->neutral_signals();
		$signals['events_per_week']       = 20.0;
		$signals['repeat_performer_rate'] = 0.9;
		$signals['performer_coverage']    = 1.0;
		$signals['time_range_title_ratio'] = 0.9;
		$signals['early_start_ratio']     = 0.9;
		$signals['name_bar_tokens']       = 3;

		$result = ClassifyVenueTierCommand::score( $signals );

		$this->assertSame( 'bar_gig', $result['tier'] );
		$this->assertLessThanOrEqual( 0.9, $result['confidence'] );
	}

	public function test_all_tiers_have_reasons(): void {
		$cases = array(
			'amphitheater'   => array( 'name_amphitheater' => true ),
			'concert_hall'   => array(
				'name_hall_tokens' => 1,
				'platform_ratio'   => 0.4,
			),
			'bar_gig'        => array(
				'events_per_week'        => 8.0,
				'time_range_title_ratio' => 0.4,
			),
			'listening_room' => array( 'name_listening' => true ),
			'club'           => array( 'platform_ratio' => 0.4 ),
		);

		foreach ( $cases as $expected_tier => $overrides ) {
			$signals = array_merge( $this->neutral_signals(), $overrides );
			$result  = ClassifyVenueTierCommand::score( $signals );

			$this->assertSame( $expected_tier, $result['tier'], "tier mismatch for {$expected_tier}" );
			$this->assertNotEmpty( $result['reasons'], "reasons required for {$expected_tier}" );
		}
	}

	public function test_return_shape(): void {
		$result = ClassifyVenueTierCommand::score( $this->neutral_signals() );

		$this->assertArrayHasKey( 'tier', $result );
		$this->assertArrayHasKey( 'confidence', $result );
		$this->assertArrayHasKey( 'reasons', $result );
		$this->assertIsString( $result['tier'] );
		$this->assertIsFloat( $result['confidence'] );
		$this->assertIsArray( $result['reasons'] );
	}

	public function test_detect_ticket_platform_known_platforms(): void {
		$this->assertSame( 'ticketmaster', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www1.ticketmaster.com/event/123' ) );
		$this->assertSame( 'axs', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.axs.com/events/456' ) );
		$this->assertSame( 'etix', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.etix.com/ticket/e/789' ) );
		$this->assertSame( 'eventbrite', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.eventbrite.com/e/show-999' ) );
		$this->assertSame( 'dice_fm', ClassifyVenueTierCommand::detect_ticket_platform( 'https://dice.fm/event/abc' ) );
		$this->assertSame( 'seetickets', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.seetickets.us/event/x' ) );
		$this->assertSame( 'ticketweb', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.ticketweb.com/event/y' ) );
		$this->assertSame( 'prekindle', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.prekindle.com/z' ) );
		$this->assertSame( 'tixr', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.tixr.com/groups/1' ) );
		$this->assertSame( 'showclix', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.showclix.com/event/2' ) );
		$this->assertSame( 'freshtix', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.freshtix.com/events/3' ) );
		$this->assertSame( 'livenation', ClassifyVenueTierCommand::detect_ticket_platform( 'https://concerts.livenation.com/event/4' ) );
	}

	public function test_detect_ticket_platform_unrecognized_or_empty(): void {
		$this->assertSame( '', ClassifyVenueTierCommand::detect_ticket_platform( 'https://www.theroyalamerican.com/schedule' ) );
		$this->assertSame( '', ClassifyVenueTierCommand::detect_ticket_platform( '' ) );
		// Substring hygiene: "axs" without the .com domain must not match.
		$this->assertNotSame( 'axs', ClassifyVenueTierCommand::detect_ticket_platform( 'https://axsome-band.com/tickets' ) );
	}

	public function test_extract_name_tokens_counts(): void {
		$tokens = ClassifyVenueTierCommand::extract_name_tokens( 'The Bluebird Cafe' );

		$this->assertSame( 1, $tokens['name_bar_tokens'] );
		$this->assertSame( 0, $tokens['name_hall_tokens'] );
		$this->assertFalse( $tokens['name_amphitheater'] );
		$this->assertFalse( $tokens['name_listening'] );

		$hall = ClassifyVenueTierCommand::extract_name_tokens( 'Charleston Music Hall' );
		$this->assertSame( 0, $hall['name_bar_tokens'] );
		$this->assertSame( 1, $hall['name_hall_tokens'] );

		$amphi = ClassifyVenueTierCommand::extract_name_tokens( 'Red Rocks Amphitheatre' );
		$this->assertTrue( $amphi['name_amphitheater'] );

		$listening = ClassifyVenueTierCommand::extract_name_tokens( 'The Listening Room Cafe' );
		$this->assertTrue( $listening['name_listening'] );
		$this->assertSame( 1, $listening['name_bar_tokens'] );
	}
}
