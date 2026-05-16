<?php
/**
 * Tests for ec_seo_build_event_schema() and supporting helpers.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ExtraChill\SEO\Tests\Support\TermRegistry;
use ExtraChill\SEO\Tests\Support\VenueDataRegistry;
use WP_Post;
use WP_Term;

use function ExtraChill\SEO\Schema\ec_seo_build_event_schema;
use function ExtraChill\SEO\Schema\ec_seo_parse_event_price;
use function ExtraChill\SEO\Schema\ec_seo_format_event_datetime;

final class SchemaEventBuildTest extends TestCase {

	protected function setUp(): void {
		TermRegistry::reset();
		VenueDataRegistry::reset();
	}

	private function make_post( array $overrides = array() ): WP_Post {
		return new WP_Post( array_merge( array(
			'ID'           => 175555,
			'post_title'   => '2nd Annual 420 Festival',
			'post_content' => '',
			'post_excerpt' => '',
			'post_name'    => '2nd-annual-420-festival',
			'post_type'    => 'data_machine_events',
			'permalink'    => 'https://events.example.com/events/2nd-annual-420-festival',
		), $overrides ) );
	}

	public function test_builds_music_event_with_minimal_attrs(): void {
		$post   = $this->make_post();
		$attrs  = array( 'startDate' => '2026-04-20' );

		$schema = ec_seo_build_event_schema( $post, $attrs );

		$this->assertNotNull( $schema );
		$this->assertSame( 'MusicEvent', $schema['@type'] );
		$this->assertSame( 'https://events.example.com/events/2nd-annual-420-festival#event', $schema['@id'] );
		$this->assertSame( '2nd Annual 420 Festival', $schema['name'] );
		$this->assertSame( '2026-04-20', $schema['startDate'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $schema['eventStatus'] );
	}

	public function test_default_event_attendance_mode_is_offline(): void {
		$post   = $this->make_post();
		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );

		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $schema['eventAttendanceMode'] );
	}

	public function test_returns_null_without_start_date(): void {
		$post   = $this->make_post();
		$schema = ec_seo_build_event_schema( $post, array() );

		$this->assertNull( $schema );
	}

	public function test_handles_price_range_as_aggregate_offer(): void {
		$post   = $this->make_post();
		$attrs  = array(
			'startDate'     => '2026-04-20',
			'price'         => '$25-$30',
			'priceCurrency' => 'USD',
		);

		$schema = ec_seo_build_event_schema( $post, $attrs );

		$this->assertArrayHasKey( 'offers', $schema );
		$this->assertSame( 'AggregateOffer', $schema['offers']['@type'] );
		$this->assertSame( '25.00', $schema['offers']['lowPrice'] );
		$this->assertSame( '30.00', $schema['offers']['highPrice'] );
		$this->assertSame( 'USD', $schema['offers']['priceCurrency'] );
	}

	public function test_handles_single_price_as_offer(): void {
		$post   = $this->make_post();
		$attrs  = array(
			'startDate' => '2026-04-20',
			'price'     => '$15',
		);

		$schema = ec_seo_build_event_schema( $post, $attrs );

		$this->assertSame( 'Offer', $schema['offers']['@type'] );
		$this->assertSame( '15.00', $schema['offers']['price'] );
		$this->assertSame( 'USD', $schema['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $schema['offers']['availability'] );
	}

	public function test_offer_includes_ticket_url_and_custom_availability(): void {
		$post   = $this->make_post();
		$attrs  = array(
			'startDate'         => '2026-04-20',
			'ticketUrl'         => 'https://tickets.example.com/buy/1',
			'offerAvailability' => 'PreOrder',
		);

		$schema = ec_seo_build_event_schema( $post, $attrs );

		$this->assertSame( 'Offer', $schema['offers']['@type'] );
		$this->assertSame( 'https://tickets.example.com/buy/1', $schema['offers']['url'] );
		$this->assertSame( 'https://schema.org/PreOrder', $schema['offers']['availability'] );
		$this->assertArrayNotHasKey( 'price', $schema['offers'] );
	}

	public function test_omits_offers_when_no_ticket_and_no_price(): void {
		$post   = $this->make_post();
		$attrs  = array( 'startDate' => '2026-04-20', 'venue' => 'Some Hall' );

		$schema = ec_seo_build_event_schema( $post, $attrs );

		$this->assertArrayNotHasKey( 'offers', $schema );
	}

	public function test_includes_location_postal_address_when_venue_term_has_meta(): void {
		$post = $this->make_post();
		$term = new WP_Term( array( 'term_id' => 42, 'name' => 'The Cabooze', 'slug' => 'the-cabooze', 'taxonomy' => 'venue' ) );
		TermRegistry::set( $post->ID, 'venue', array( $term ) );
		VenueDataRegistry::set( 42, array(
			'name'     => 'The Cabooze',
			'address'  => '917 Cedar Ave',
			'city'     => 'Minneapolis',
			'state'    => 'MN',
			'zip'      => '55454',
			'country'  => 'US',
			'timezone' => 'America/Chicago',
		) );

		$schema = ec_seo_build_event_schema(
			$post,
			array( 'startDate' => '2026-04-20', 'venue' => 'The Cabooze' )
		);

		$this->assertSame( 'Place', $schema['location']['@type'] );
		$this->assertSame( 'The Cabooze', $schema['location']['name'] );
		$this->assertSame( 'PostalAddress', $schema['location']['address']['@type'] );
		$this->assertSame( '917 Cedar Ave', $schema['location']['address']['streetAddress'] );
		$this->assertSame( 'Minneapolis', $schema['location']['address']['addressLocality'] );
		$this->assertSame( 'MN', $schema['location']['address']['addressRegion'] );
		$this->assertSame( '55454', $schema['location']['address']['postalCode'] );
		$this->assertSame( 'US', $schema['location']['address']['addressCountry'] );
	}

	public function test_location_falls_back_to_block_address_when_no_venue_term(): void {
		$post   = $this->make_post();
		$schema = ec_seo_build_event_schema( $post, array(
			'startDate' => '2026-04-20',
			'venue'     => 'Some Hall',
			'address'   => '123 Main St',
		) );

		$this->assertSame( 'Some Hall', $schema['location']['name'] );
		$this->assertSame( '123 Main St', $schema['location']['address']['streetAddress'] );
		$this->assertArrayNotHasKey( 'addressLocality', $schema['location']['address'] );
	}

	public function test_performer_uses_block_attrs(): void {
		$post   = $this->make_post();
		$schema = ec_seo_build_event_schema( $post, array(
			'startDate'     => '2026-04-20',
			'performer'     => 'Various Artists',
			'performerType' => 'MusicGroup',
		) );

		$this->assertSame( 'MusicGroup', $schema['performer']['@type'] );
		$this->assertSame( 'Various Artists', $schema['performer']['name'] );
	}

	public function test_performer_omitted_when_empty(): void {
		$post   = $this->make_post();
		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );

		$this->assertArrayNotHasKey( 'performer', $schema );
	}

	public function test_organizer_includes_url(): void {
		$post   = $this->make_post();
		$schema = ec_seo_build_event_schema( $post, array(
			'startDate'     => '2026-04-20',
			'organizer'     => 'DC Foundation Events',
			'organizerType' => 'Organization',
			'organizerUrl'  => 'https://www.dcfoundationevents.com',
		) );

		$this->assertSame( 'Organization', $schema['organizer']['@type'] );
		$this->assertSame( 'DC Foundation Events', $schema['organizer']['name'] );
		$this->assertSame( 'https://www.dcfoundationevents.com', $schema['organizer']['url'] );
	}

	public function test_falls_back_to_post_excerpt_for_description(): void {
		$post   = $this->make_post( array( 'post_excerpt' => 'A sharp little summary.' ) );
		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );

		$this->assertSame( 'A sharp little summary.', $schema['description'] );
	}

	public function test_falls_back_to_trimmed_content_when_no_excerpt(): void {
		$post   = $this->make_post( array(
			'post_content' => str_repeat( 'word ', 80 ),
		) );
		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );

		$this->assertArrayHasKey( 'description', $schema );
		$this->assertStringEndsWith( '...', $schema['description'] );
	}

	public function test_start_date_includes_timezone_when_venue_provides_one(): void {
		$post = $this->make_post();
		$term = new WP_Term( array( 'term_id' => 9, 'name' => 'Hall', 'slug' => 'hall', 'taxonomy' => 'venue' ) );
		TermRegistry::set( $post->ID, 'venue', array( $term ) );
		VenueDataRegistry::set( 9, array( 'timezone' => 'America/Chicago' ) );

		$schema = ec_seo_build_event_schema( $post, array(
			'startDate' => '2026-04-20',
			'startTime' => '18:00',
		) );

		// 18:00 Chicago in April = UTC-5 (CDT).
		$this->assertSame( '2026-04-20T18:00:00-05:00', $schema['startDate'] );
	}

	public function test_end_date_emitted_when_present(): void {
		$post   = $this->make_post();
		$schema = ec_seo_build_event_schema( $post, array(
			'startDate' => '2026-04-20',
			'startTime' => '13:00',
			'endDate'   => '2026-04-20',
			'endTime'   => '23:00',
		) );

		$this->assertArrayHasKey( 'endDate', $schema );
		$this->assertStringStartsWith( '2026-04-20T23:00:00', $schema['endDate'] );
	}

	// ---- price parser unit checks ----

	public function test_price_parser_handles_free_or_tba(): void {
		$this->assertSame( 'none', ec_seo_parse_event_price( '' )['type'] );
		$this->assertSame( 'none', ec_seo_parse_event_price( 'Free' )['type'] );
		$this->assertSame( 'none', ec_seo_parse_event_price( 'TBA' )['type'] );
	}

	public function test_price_parser_handles_em_dash_range(): void {
		$parsed = ec_seo_parse_event_price( '$25 – $30' );
		$this->assertSame( 'range', $parsed['type'] );
		$this->assertSame( '25.00', $parsed['low'] );
		$this->assertSame( '30.00', $parsed['high'] );
	}

	public function test_price_parser_collapses_equal_bounds_to_single(): void {
		$parsed = ec_seo_parse_event_price( '$25-$25' );
		$this->assertSame( 'single', $parsed['type'] );
		$this->assertSame( '25.00', $parsed['price'] );
	}

	public function test_datetime_formatter_omits_time_when_absent(): void {
		$this->assertSame( '2026-04-20', ec_seo_format_event_datetime( '2026-04-20', '', '' ) );
	}
}
