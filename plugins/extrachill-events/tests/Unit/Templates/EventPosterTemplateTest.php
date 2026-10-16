<?php
/**
 * Event poster template tests (#945).
 *
 * Covers the copy logic of EventPosterTemplate: the QR payload is the
 * event URL, event data is normalized into poster copy, and that copy is
 * mapped onto Data Machine's core `poster` fields for each orientation.
 * Layout and rendering are tested in Data Machine (#3614).
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

	public function test_default_rsvp_text_uses_event_host(): void {
		$this->assertSame( 'RSVP at events.extrachill.com', EventPosterTemplate::default_rsvp_text( self::PERMALINK ) );
	}

	/**
	 * Normalized meetup copy, as event_fields() plus address returns it.
	 *
	 * @return array<string, string>
	 */
	private static function meetup_fields(): array {
		return array(
			'title'     => 'BUILDING YOUR ONLINE PRESENCE IN THE AI ERA',
			'date_line' => "WED, OCT 21 / 6:30\u{2013}9PM",
			'venue'     => 'LO-FI BREWING',
			'banner'    => 'FREE. YOUR FIRST BEER IS ON EXTRA CHILL.',
			'qr_url'    => self::PERMALINK,
			'address'   => '2038 Meeting Street Rd, Charleston',
		);
	}

	public function test_portrait_poster_fields_carry_the_full_event_block(): void {
		$poster = EventPosterTemplate::poster_fields(
			self::meetup_fields(),
			array(
				'tagline'     => 'A free night for creatives.',
				'subline'     => 'No tech experience needed.',
				'footer_note' => 'An official WordPress meetup.',
			),
			false
		);

		$this->assertSame( 'BUILDING YOUR ONLINE PRESENCE IN THE AI ERA', $poster['title'] );
		$this->assertSame( array( "WED, OCT 21 / 6:30\u{2013}9PM" ), $poster['detail_lines'] );
		$this->assertSame( 'LO-FI BREWING', $poster['accent_line'] );
		$this->assertSame( '2038 Meeting Street Rd, Charleston', $poster['detail_note'] );
		$this->assertSame( 'No tech experience needed.', $poster['subline'] );
		$this->assertSame( 'FREE. YOUR FIRST BEER IS ON EXTRA CHILL.', $poster['banner_text'] );
		$this->assertSame( self::PERMALINK, $poster['qr_url'] );
		$this->assertSame( '', $poster['qr_label'] );
		$this->assertSame( 'RSVP at events.extrachill.com', $poster['cta_text'] );
		$this->assertSame( 'An official WordPress meetup.', $poster['footer_note'] );
	}

	public function test_landscape_poster_fields_label_the_qr_and_trim_the_left_column(): void {
		$poster = EventPosterTemplate::poster_fields(
			self::meetup_fields(),
			array(
				'tagline'   => 'A free night for creatives.',
				'subline'   => 'No tech experience needed.',
				'rsvp_text' => 'RSVP online',
			),
			true
		);

		$this->assertSame( 'A free night for creatives.', $poster['tagline'] );
		$this->assertSame( '', $poster['subline'] );
		$this->assertSame( '', $poster['detail_note'] );
		$this->assertSame( '', $poster['cta_text'] );
		$this->assertSame( EventPosterTemplate::LANDSCAPE_QR_LABEL, $poster['qr_label'] );
		$this->assertSame( 'LO-FI BREWING', $poster['accent_line'] );
	}

	public function test_poster_fields_honor_overrides_and_missing_dates(): void {
		$fields              = self::meetup_fields();
		$fields['date_line'] = '';

		$poster = EventPosterTemplate::poster_fields(
			$fields,
			array(
				'kicker'        => 'EXTRA CHILL x LO-FI',
				'rsvp_text'     => 'RSVP online',
				'cobrand_logos' => array( '/tmp/logo.png' ),
			),
			false
		);

		$this->assertSame( array(), $poster['detail_lines'] );
		$this->assertSame( 'EXTRA CHILL x LO-FI', $poster['kicker'] );
		$this->assertSame( 'RSVP online', $poster['cta_text'] );
		$this->assertSame( array( '/tmp/logo.png' ), $poster['cobrand_logos'] );
	}
}
