<?php
/**
 * Tests for the BackfillEventTypeCommand heuristic classifier.
 *
 * classify() and its helpers are pure static methods (no WordPress
 * dependencies), so the resolution rules behind the event_type backfill
 * are asserted without a WP test fixture.
 *
 * @package ExtraChillEvents\Tests\Unit\Cli
 */

namespace ExtraChillEvents\Tests\Unit\Cli;

use ExtraChillEvents\Cli\BackfillEventTypeCommand;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Cli/BackfillEventTypeCommand.php';

class BackfillEventTypeClassifyTest extends TestCase {

	public function test_legacy_music_event_maps_to_concert(): void {
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Any Title', 'musicevent' ) );
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Any Title', 'MusicEvent' ) );
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Any Title', 'Music Event' ) );
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Any Title', 'music-event' ) );
	}

	public function test_legacy_comedy_event_maps_to_comedy(): void {
		$this->assertSame( 'Comedy', BackfillEventTypeCommand::classify( 'Any Title', 'comedyevent' ) );
		$this->assertSame( 'Comedy', BackfillEventTypeCommand::classify( 'Any Title', 'ComedyEvent' ) );
	}

	public function test_legacy_festival_maps_to_concert(): void {
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Riverfront Jazz Festival', 'festival' ) );
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Any Title', 'Festival' ) );
	}

	public function test_legacy_dance_event_maps_to_dance_party(): void {
		$this->assertSame( 'Dance Party', BackfillEventTypeCommand::classify( 'Any Title', 'danceevent' ) );
		$this->assertSame( 'Dance Party', BackfillEventTypeCommand::classify( 'Any Title', 'DanceEvent' ) );
	}

	public function test_fall_through_legacy_types_ignore_legacy_value(): void {
		foreach ( array( 'theaterevent', 'sportsevent', 'exhibitionevent', 'event' ) as $legacy ) {
			$this->assertSame(
				'Karaoke',
				BackfillEventTypeCommand::classify( 'Friday Karaoke Night', $legacy ),
				"{$legacy} must fall through to title heuristics"
			);
		}
	}

	public function test_secondary_legacy_used_when_primary_falls_through(): void {
		$this->assertSame(
			'Concert',
			BackfillEventTypeCommand::classify( 'Any Title', 'theaterevent', 'MusicEvent' )
		);
	}

	public function test_primary_legacy_wins_over_secondary(): void {
		$this->assertSame(
			'Comedy',
			BackfillEventTypeCommand::classify( 'Any Title', 'comedyevent', 'MusicEvent' )
		);
	}

	public function test_legacy_beats_title_heuristics(): void {
		$this->assertSame(
			'Concert',
			BackfillEventTypeCommand::classify( 'Karaoke Night', 'musicevent' )
		);
	}

	public function test_karaoke_keyword(): void {
		$this->assertSame( 'Karaoke', BackfillEventTypeCommand::classify( 'Live Band KARAOKE!' ) );
	}

	public function test_trivia_and_bingo_keywords(): void {
		$this->assertSame( 'Trivia & Games', BackfillEventTypeCommand::classify( 'Trivia Tuesday' ) );
		$this->assertSame( 'Trivia & Games', BackfillEventTypeCommand::classify( 'Bingo Night at the Hall' ) );
	}

	public function test_open_mic_variants(): void {
		$this->assertSame( 'Open Mic', BackfillEventTypeCommand::classify( 'Open Mic Friday' ) );
		$this->assertSame( 'Open Mic', BackfillEventTypeCommand::classify( 'OPEN-MIC special' ) );
		$this->assertSame( 'Open Mic', BackfillEventTypeCommand::classify( 'Jam Session with the trio' ) );
		$this->assertSame( 'Open Mic', BackfillEventTypeCommand::classify( 'Jam Night at the den' ) );
	}

	public function test_dance_party_keyword(): void {
		$this->assertSame( 'Dance Party', BackfillEventTypeCommand::classify( 'Shake It: Pop Punk Dance Party' ) );
		$this->assertSame( 'Dance Party', BackfillEventTypeCommand::classify( '90s dance  party' ) );
	}

	public function test_dj_keyword_requires_word_boundary(): void {
		$this->assertSame( 'DJ Set', BackfillEventTypeCommand::classify( 'DJ Dave all night long' ) );
		$this->assertSame( 'DJ Set', BackfillEventTypeCommand::classify( 'Warehouse Rave' ) );
		$this->assertSame( 'DJ Set', BackfillEventTypeCommand::classify( 'Club Night: neon edition' ) );
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Djembe drum circle' ) );
	}

	public function test_comedy_keywords(): void {
		$this->assertSame( 'Comedy', BackfillEventTypeCommand::classify( 'Stand-Up Showcase' ) );
		$this->assertSame( 'Comedy', BackfillEventTypeCommand::classify( 'standup night' ) );
		$this->assertSame( 'Comedy', BackfillEventTypeCommand::classify( 'Local comedian hour' ) );
		$this->assertSame( 'Comedy', BackfillEventTypeCommand::classify( 'Comedy brunch' ) );
	}

	public function test_other_keywords(): void {
		$this->assertSame( 'Other', BackfillEventTypeCommand::classify( 'Sunday Brunch Social' ) );
		$this->assertSame( 'Other', BackfillEventTypeCommand::classify( 'Community Theater presents' ) );
		$this->assertSame( 'Other', BackfillEventTypeCommand::classify( 'Theatre company gala' ) );
		$this->assertSame( 'Other', BackfillEventTypeCommand::classify( 'Film Screening: doc night' ) );
		$this->assertSame( 'Other', BackfillEventTypeCommand::classify( 'Drag Showcase' ) );
		$this->assertSame( 'Other', BackfillEventTypeCommand::classify( 'Farmers Market' ) );
		$this->assertSame( 'Other', BackfillEventTypeCommand::classify( 'Pottery Workshop' ) );
	}

	public function test_other_word_boundaries_do_not_overreach(): void {
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Dragonfruit Fest with the band' ) );
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'Filmorassic themed night' ) );
	}

	public function test_unmatched_title_defaults_to_concert(): void {
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( 'ECLIPSE: A Tribute To Pink Floyd' ) );
		$this->assertSame( 'Concert', BackfillEventTypeCommand::classify( '' ) );
	}

	public function test_priority_order(): void {
		$this->assertSame( 'Karaoke', BackfillEventTypeCommand::classify( 'Karaoke Trivia Night' ) );
		$this->assertSame( 'Trivia & Games', BackfillEventTypeCommand::classify( 'Trivia Bingo Dance Party' ) );
		$this->assertSame( 'Open Mic', BackfillEventTypeCommand::classify( 'Open Mic Dance Party' ) );
		$this->assertSame( 'Open Mic', BackfillEventTypeCommand::classify( 'Comedy open mic' ) );
		$this->assertSame( 'Dance Party', BackfillEventTypeCommand::classify( 'Dance Party with DJ Dave' ) );
		$this->assertSame( 'DJ Set', BackfillEventTypeCommand::classify( 'DJ comedy hour' ) );
		$this->assertSame( 'Comedy', BackfillEventTypeCommand::classify( 'Comedy brunch' ) );
	}

	public function test_classify_title_returns_empty_when_no_rule_matches(): void {
		$this->assertSame( '', BackfillEventTypeCommand::classify_title( 'Random Band Show' ) );
	}

	public function test_resolve_legacy_fall_through_returns_empty(): void {
		$this->assertSame( '', BackfillEventTypeCommand::resolve_legacy( 'theaterevent' ) );
		$this->assertSame( '', BackfillEventTypeCommand::resolve_legacy( 'sportsevent' ) );
		$this->assertSame( '', BackfillEventTypeCommand::resolve_legacy( 'exhibitionevent' ) );
		$this->assertSame( '', BackfillEventTypeCommand::resolve_legacy( 'event' ) );
		$this->assertSame( '', BackfillEventTypeCommand::resolve_legacy( 'invented-type' ) );
		$this->assertSame( '', BackfillEventTypeCommand::resolve_legacy( '' ) );
	}
}
