<?php
/**
 * Event roundup slide packing tests (#931).
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

use ExtraChillEvents\Templates\EventRoundupTemplate;

require_once dirname( __DIR__, 2 ) . '/fixtures/data-machine-template-interface.php';
require_once dirname( __DIR__, 3 ) . '/inc/Templates/EventRoundupTemplate.php';

/** Verifies roundup slides never overflow the canvas or the footer CTA. */
final class EventRoundupSlidePlanTest extends BookingTestCase {

	private const TOP         = 60;
	private const BOTTOM      = 1000;
	private const HEADER      = 60;
	private const EVENT       = 70;
	private const TRAILING    = 30;
	private const TITLE_BLOCK = 100;

	/**
	 * Build a day group of N events starting at 18:00, shuffled to prove sorting.
	 *
	 * @param string $date  Date key.
	 * @param int    $count Event count.
	 * @return array
	 */
	private function day( string $date, int $count ): array {
		$events = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$events[] = array(
				'post'       => (object) array( 'post_title' => "{$date} event {$i}" ),
				'event_data' => array( 'startTime' => sprintf( '%02d:%02d:00', 12 + intdiv( $i, 4 ), ( $i % 4 ) * 15 ) ),
			);
		}
		return array(
			'date_obj' => new DateTime( $date ),
			'events'   => array_reverse( $events ),
		);
	}

	/**
	 * Run the planner with fixed heights.
	 *
	 * @param array $day_groups Day groups.
	 * @param int   $title      Title block height.
	 * @return array
	 */
	private function plan( array $day_groups, int $title = 0 ): array {
		return EventRoundupTemplate::plan_slides(
			$day_groups,
			self::TOP,
			self::BOTTOM,
			$title,
			self::HEADER,
			static fn(): int => self::EVENT
		);
	}

	/**
	 * Replay render_slide() y-advance and return the max content y per slide.
	 *
	 * @param array $slides Planned slides.
	 * @param int   $title  Title block height.
	 * @return int[]
	 */
	private function content_extents( array $slides, int $title ): array {
		$extents = array();
		foreach ( $slides as $index => $slide ) {
			$y   = self::TOP + ( 0 === $index ? $title : 0 );
			$max = $y;
			foreach ( $slide as $day ) {
				$y  += self::HEADER + count( $day['events'] ) * self::EVENT;
				$max = $y;
				$y  += self::TRAILING;
			}
			$extents[] = $max;
		}
		return $extents;
	}

	/** A day too tall for one slide is split, never clipped. */
	public function test_oversized_day_splits_across_slides(): void {
		$days   = array( '2026-10-02' => $this->day( '2026-10-02', 17 ) );
		$slides = $this->plan( $days, self::TITLE_BLOCK );

		$this->assertGreaterThan( 1, count( $slides ) );
		foreach ( $this->content_extents( $slides, self::TITLE_BLOCK ) as $extent ) {
			$this->assertLessThanOrEqual( self::BOTTOM, $extent );
		}

		$placed = 0;
		foreach ( $slides as $slide ) {
			foreach ( $slide as $day ) {
				$placed += count( $day['events'] );
			}
		}
		$this->assertSame( 17, $placed, 'Every event lands on some slide.' );
	}

	/** A continued day repeats its header (same date) on the next slide, in time order. */
	public function test_continued_day_keeps_date_and_time_order(): void {
		$slides = $this->plan( array( '2026-10-02' => $this->day( '2026-10-02', 17 ) ) );

		$times = array();
		foreach ( $slides as $slide ) {
			$this->assertSame( '2026-10-02', $slide[0]['date_obj']->format( 'Y-m-d' ) );
			foreach ( $slide as $day ) {
				foreach ( $day['events'] as $event ) {
					$times[] = $event['event_data']['startTime'];
				}
			}
		}
		$sorted = $times;
		sort( $sorted );
		$this->assertSame( $sorted, $times );
	}

	/** Many days with ~20 events each never cross the content bottom. */
	public function test_multi_day_weekend_never_overflows(): void {
		$days   = array(
			'2026-10-02' => $this->day( '2026-10-02', 20 ),
			'2026-10-03' => $this->day( '2026-10-03', 13 ),
			'2026-10-04' => $this->day( '2026-10-04', 9 ),
		);
		$slides = $this->plan( $days, self::TITLE_BLOCK );

		foreach ( $this->content_extents( $slides, self::TITLE_BLOCK ) as $extent ) {
			$this->assertLessThanOrEqual( self::BOTTOM, $extent );
		}
	}

	/** Small roundups stay on one slide. */
	public function test_small_roundup_is_one_slide(): void {
		$slides = $this->plan(
			array(
				'2026-10-03' => $this->day( '2026-10-03', 3 ),
				'2026-10-04' => $this->day( '2026-10-04', 3 ),
			),
			self::TITLE_BLOCK
		);
		$this->assertCount( 1, $slides );
		$this->assertCount( 2, $slides[0] );
	}

	/** An event taller than a slide is still placed, without looping forever. */
	public function test_oversized_single_event_is_placed(): void {
		$slides = EventRoundupTemplate::plan_slides(
			array( '2026-10-02' => $this->day( '2026-10-02', 2 ) ),
			self::TOP,
			self::BOTTOM,
			0,
			self::HEADER,
			static fn(): int => 5000
		);
		$this->assertCount( 2, $slides );
	}

	/** The CTA reserves space above the bottom padding. */
	public function test_content_bottom_reserves_cta_space(): void {
		$with_cta    = EventRoundupTemplate::content_bottom( 1350, 'MORE SHOWS AT EVENTS.EXTRACHILL.COM' );
		$without_cta = EventRoundupTemplate::content_bottom( 1350, '' );

		$this->assertSame( 1290, $without_cta );
		// CTA rule sits at 1350 - 60 - 18 - 18 = 1254; content must stop above it.
		$this->assertLessThan( 1254, $with_cta );
	}

	/** Entities stored in titles render as plain text. */
	public function test_decode_text_decodes_entities(): void {
		$this->assertSame( 'Tom Crowley & The Speakers', EventRoundupTemplate::decode_text( 'Tom Crowley &amp; The Speakers' ) );
		$this->assertSame( 'Burgundy: "Karaoke Nite"', EventRoundupTemplate::decode_text( 'Burgundy: &quot;Karaoke Nite&quot;' ) );
	}

	public function test_split_day_never_strands_a_single_event(): void {
		// One slide fits 13 events after a header (60 + 60 + 13*70 = 1030 > 1000,
		// so 12). A 2-event day followed by a 12-event day would leave the
		// second day's header with 1 event at the bottom of slide one.
		$slides = $this->plan(
			array(
				'2026-10-02' => $this->day( '2026-10-02', 2 ),
				'2026-10-03' => $this->day( '2026-10-03', 12 ),
			)
		);

		foreach ( $slides as $index => $slide ) {
			foreach ( $slide as $day ) {
				$this->assertGreaterThanOrEqual( 2, count( $day['events'] ), "Slide {$index} has a stranded day fragment." );
			}
		}
		foreach ( $this->content_extents( $slides, 0 ) as $extent ) {
			$this->assertLessThanOrEqual( self::BOTTOM, $extent );
		}
	}

	public function test_split_day_never_carries_a_single_event(): void {
		// 13 events: 12 fit on slide one, so a naive split carries 1.
		$slides = $this->plan( array( '2026-10-02' => $this->day( '2026-10-02', 13 ) ) );

		$this->assertCount( 2, $slides );
		$this->assertGreaterThanOrEqual( 2, count( $slides[1][0]['events'] ) );
		$this->assertSame( 13, count( $slides[0][0]['events'] ) + count( $slides[1][0]['events'] ) );
	}

	public function test_cover_stats_count_shows_venues_and_date_range(): void {
		$friday   = $this->day( '2026-10-16', 3 );
		$saturday = $this->day( '2026-10-17', 2 );
		$venues   = array( 'The Royal American', 'Pour House', 'the royal american ' );
		foreach ( $friday['events'] as $i => &$event ) {
			$event['event_data']['venue'] = $venues[ $i ];
		}
		unset( $event );
		foreach ( $saturday['events'] as &$event ) {
			$event['event_data']['venue'] = 'Pour House';
		}
		unset( $event );

		$stats = EventRoundupTemplate::cover_stats(
			array(
				'2026-10-17' => $saturday,
				'2026-10-16' => $friday,
				'2026-10-18' => array(
					'date_obj' => new DateTime( '2026-10-25' ),
					'events'   => array(),
				),
			)
		);

		$this->assertSame( 5, $stats['shows'] );
		$this->assertSame( 2, $stats['venues'] );
		$this->assertSame( 'FRI OCT 16 – SAT 17', $stats['date_label'] );
	}

	public function test_content_bottom_reserves_brand_mark_space(): void {
		$plain     = EventRoundupTemplate::content_bottom( 1350, '' );
		$with_mark = EventRoundupTemplate::content_bottom( 1350, '', 72 );
		$both      = EventRoundupTemplate::content_bottom( 1350, 'MORE SHOWS AT EVENTS.EXTRACHILL.COM', 72 );

		$this->assertSame( 1350 - 60, $plain );
		$this->assertSame( 1350 - 60 - 72 - 24, $with_mark );
		$this->assertSame( $with_mark, $both, 'The taller of the CTA and the mark sets the limit.' );
	}

	/**
	 * Build an event item.
	 *
	 * @param string $title Title.
	 * @param string $venue Venue.
	 * @param string $time  H:i:s.
	 * @return array
	 */
	private function show( string $title, string $venue, string $time ): array {
		return array(
			'post'       => (object) array( 'post_title' => $title ),
			'event_data' => array(
				'venue'     => $venue,
				'startTime' => $time,
			),
		);
	}

	public function test_repeat_sets_collapse_into_one_row_with_both_times(): void {
		$groups = EventRoundupTemplate::merge_repeat_sets(
			array(
				'2026-11-06' => array(
					'date_obj' => new DateTime( '2026-11-06' ),
					'events'   => array(
						$this->show( 'The Garcia Project', 'Pour House', '20:30:00' ),
						$this->show( 'Opener', 'Pour House', '19:00:00' ),
						$this->show( 'The Garcia Project', 'Pour House', '18:00:00' ),
						$this->show( 'The Garcia Project', 'Royal American', '21:00:00' ),
					),
				),
			)
		);

		$events = $groups['2026-11-06']['events'];
		$this->assertCount( 3, $events, 'Same show, same venue, same day merges; another venue stays separate.' );
		$this->assertSame( array( '18:00:00', '20:30:00' ), $events[0]['times'] );
		$this->assertSame( '6:00 & 8:30 PM', EventRoundupTemplate::format_times( $events[0] ) );
		$this->assertSame( '7:00 PM', EventRoundupTemplate::format_times( $events[1] ) );
	}

	public function test_format_times_spells_out_both_halves_across_noon(): void {
		$this->assertSame( '11:00 AM & 2:00 PM', EventRoundupTemplate::format_times( array( 'times' => array( '11:00:00', '14:00:00' ) ) ) );
		$this->assertSame( '', EventRoundupTemplate::format_times( array( 'event_data' => array() ) ) );
	}

	public function test_planner_honors_a_custom_trailing_gap(): void {
		$days = array();
		for ( $d = 1; $d <= 10; $d++ ) {
			$date          = sprintf( '2026-11-%02d', $d );
			$days[ $date ] = $this->day( $date, 1 );
		}

		$daily    = EventRoundupTemplate::plan_slides( $days, self::TOP, self::BOTTOM, 0, self::HEADER, static fn(): int => self::EVENT );
		$calendar = EventRoundupTemplate::plan_slides( $days, self::TOP, self::BOTTOM, 0, 0, static fn(): int => self::EVENT, 10 );

		$this->assertGreaterThan( count( $calendar ), count( $daily ) );
		$this->assertCount( 1, $calendar, '10 headerless rows of 80px fit in 940px.' );
	}

	public function test_balance_slides_avoids_a_stranded_last_slide(): void {
		$days = array();
		for ( $d = 1; $d <= 13; $d++ ) {
			$date          = sprintf( '2026-11-%02d', $d );
			$days[ $date ] = $this->day( $date, 1 );
		}
		$plan = fn( int $bottom ): array => EventRoundupTemplate::plan_slides( $days, self::TOP, $bottom, 0, 0, static fn(): int => self::EVENT, 10 );

		// 13 rows of 80px: a full-height plan is 11 + 2.
		$greedy = $plan( self::BOTTOM );
		$this->assertCount( 2, $greedy );
		$this->assertCount( 2, $greedy[1] );

		$balanced = EventRoundupTemplate::balance_slides( $plan, self::BOTTOM, self::TOP );
		$this->assertCount( 2, $balanced );
		$this->assertGreaterThanOrEqual( 6, count( $balanced[1] ) );
		$this->assertSame( 13, count( $balanced[0] ) + count( $balanced[1] ) );
	}
}
