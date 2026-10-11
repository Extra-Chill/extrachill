<?php
/**
 * Event poster template tests (#945).
 *
 * Covers the pure layout and copy logic of EventPosterTemplate: content
 * never overlaps the banner on any supported preset, the QR payload is
 * the event URL, and event data is normalized into poster copy.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

use ExtraChillEvents\Templates\EventPosterTemplate;

require_once dirname( __DIR__, 2 ) . '/fixtures/data-machine-template-interface.php';
require_once dirname( __DIR__, 3 ) . '/inc/Templates/EventPosterTemplate.php';

/** Verifies poster layout planning and copy normalization. */
final class EventPosterTemplateTest extends BookingTestCase {

	private const PERMALINK = 'https://events.extrachill.com/events/wordpress-meetup-charleston-october-2026';

	/**
	 * Supported presets as width => height, scaled the way the template
	 * scales (all metrics are design pixels at 1080 wide).
	 *
	 * Content heights are measured from the real 2026-10-21 meetup event
	 * (four-line title, tagline + subline, date, venue, address) at 1080
	 * wide: 1076px at the 84pt title, 792px after the template shrinks the
	 * title to 60pt, which it does on the shorter feed canvas.
	 *
	 * @return array<string, array{int, int, int}>
	 */
	public static function presets(): array {
		return array(
			'instagram_feed_portrait' => array( 1080, 1350, 792 ),
			'instagram_story'         => array( 1080, 1920, 1076 ),
			'print_tabloid'           => array( 1650, 2550, 1076 ),
		);
	}

	/**
	 * Content stays above the banner and the footer inside the margin.
	 *
	 * @dataProvider presets
	 */
	public function test_content_clears_the_banner_on_every_preset( int $width, int $height, int $content ): void {
		$u      = $width / 1080;
		$margin = (int) round( 80 * $u );
		$plan   = EventPosterTemplate::plan_layout(
			$height,
			$margin,
			(int) round( $content * $u ),
			(int) round( 120 * $u ),
			(int) round( 210 * $u ),
			(int) round( 40 * $u )
		);

		$this->assertTrue( $plan['fits'] );
		$this->assertLessThanOrEqual( $plan['banner_top'] - (int) round( 40 * $u ), $plan['content_bottom'] );
		$this->assertGreaterThanOrEqual( $margin, $plan['content_top'] );
		$this->assertSame( $height - $margin, $plan['footer_bottom'] );
	}

	public function test_spare_space_is_distributed_on_tall_canvases(): void {
		$feed  = EventPosterTemplate::plan_layout( 1350, 80, 792, 120, 210, 40 );
		$story = EventPosterTemplate::plan_layout( 1920, 80, 792, 120, 210, 40 );

		$this->assertGreaterThan( $feed['content_top'], $story['content_top'], 'Tall canvases push content down instead of stranding it at the top' );
		$this->assertGreaterThan( $story['content_bottom'], $story['banner_top'] - 40, 'Some slack still remains below the content' );
	}

	public function test_full_size_title_does_not_fit_the_feed_so_the_template_must_shrink_it(): void {
		$plan = EventPosterTemplate::plan_layout( 1350, 80, 1076, 120, 210, 40 );

		$this->assertFalse( $plan['fits'] );
		$this->assertSame( 80, $plan['content_top'] );
	}

	public function test_qr_payload_is_the_event_url(): void {
		$fields = EventPosterTemplate::event_fields( array(), 'Show', self::PERMALINK, '' );

		$this->assertSame( self::PERMALINK, $fields['qr_url'] );
	}

	public function test_event_fields_normalize_poster_copy(): void {
		$fields = EventPosterTemplate::event_fields(
			array(
				'startDate' => '2026-10-21',
				'startTime' => '18:30',
				'endTime'   => '21:00',
				'venue'     => 'Lo-Fi Brewing',
				'price'     => 'Free',
			),
			'Building Your Online Presence &amp; More',
			self::PERMALINK,
			'Your first beer is on Extra Chill.'
		);

		$this->assertSame( 'BUILDING YOUR ONLINE PRESENCE & MORE', $fields['title'] );

		$override = EventPosterTemplate::event_fields( array(), 'Long Event Title', self::PERMALINK, '', 'Short' );
		$this->assertSame( 'SHORT', $override['title'] );
		$this->assertSame( "WED, OCT 21 / 6:30\u{2013}9PM", $fields['date_line'] );
		$this->assertSame( 'LO-FI BREWING', $fields['venue'] );
		$this->assertSame( 'FREE. YOUR FIRST BEER IS ON EXTRA CHILL.', $fields['banner'] );
	}

	public function test_time_range_formats(): void {
		$this->assertSame( "6:30\u{2013}9PM", EventPosterTemplate::time_range( '18:30', '21:00' ) );
		$this->assertSame( "11AM\u{2013}1PM", EventPosterTemplate::time_range( '11:00:00', '13:00:00' ) );
		$this->assertSame( '8PM', EventPosterTemplate::time_range( '20:00', '' ) );
		$this->assertSame( '', EventPosterTemplate::time_range( '', '21:00' ) );
	}

	public function test_banner_and_date_handle_missing_data(): void {
		$this->assertSame( '', EventPosterTemplate::banner_text( '', '' ) );
		$this->assertSame( '$10.', EventPosterTemplate::banner_text( '$10', '' ) );
		$this->assertSame( '', EventPosterTemplate::date_line( array() ) );
	}

	public function test_print_preset_is_registered_at_tabloid_size(): void {
		$presets = EventPosterTemplate::register_print_preset( array() );

		$this->assertSame( 1650, $presets[ EventPosterTemplate::PRINT_PRESET ]['width'] );
		$this->assertSame( 2550, $presets[ EventPosterTemplate::PRINT_PRESET ]['height'] );
	}

	public function test_landscape_preset_is_registered_at_16_9(): void {
		$presets = EventPosterTemplate::register_print_preset( array() );

		$this->assertSame( 1920, $presets[ EventPosterTemplate::LANDSCAPE_PRESET ]['width'] );
		$this->assertSame( 1080, $presets[ EventPosterTemplate::LANDSCAPE_PRESET ]['height'] );
	}

	/**
	 * Landscape: the left text column never reaches the right column, and
	 * content measured from the real 2026-10-21 meetup clears the banner.
	 */
	public function test_landscape_columns_do_not_overlap_and_content_clears_banner(): void {
		$width   = 1920;
		$margin  = 100;
		$right_w = max( (int) round( 1088 * 200 / 923 ), 260 );
		$left_w  = EventPosterTemplate::landscape_left_width( $width, $margin, $right_w, 80 );

		$this->assertLessThanOrEqual( $width - $margin - $right_w - 80, $margin + $left_w );

		$plan = EventPosterTemplate::plan_layout( 1080, $margin, 597, 110, 60, 40 );
		$this->assertTrue( $plan['fits'] );
		$this->assertLessThanOrEqual( $plan['banner_top'] - 40, $plan['content_bottom'] );
	}

	public function test_landscape_left_column_uses_full_width_without_a_right_column(): void {
		$this->assertSame( 1720, EventPosterTemplate::landscape_left_width( 1920, 100, 0, 80 ) );
	}

	public function test_default_rsvp_text_uses_event_host(): void {
		$this->assertSame( 'RSVP at events.extrachill.com', EventPosterTemplate::default_rsvp_text( self::PERMALINK ) );
	}
}
