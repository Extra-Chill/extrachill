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
}
