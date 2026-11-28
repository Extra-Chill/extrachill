<?php
/**
 * Regression coverage for offer sale dates and venue-organizer websites (#1797).
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ExtraChill\SEO\Tests\Support\TermRegistry;
use ExtraChill\SEO\Tests\Support\TermMetaRegistry;
use ExtraChill\SEO\Tests\Support\VenueDataRegistry;
use WP_Post;
use WP_Term;

use function ExtraChill\SEO\Schema\ec_seo_build_event_schema;

final class SchemaEventOfferMetadataTest extends TestCase {

	protected function setUp(): void {
		TermRegistry::reset();
		TermMetaRegistry::reset();
		VenueDataRegistry::reset();
	}

	private function build( array $attrs, array $venue = array() ): array {
		$post = new WP_Post( array(
			'ID' => 1797,
			'post_title' => 'Show',
			'post_content' => '',
			'post_excerpt' => '',
			'permalink' => 'https://events.example.com/events/show',
		) );
		TermRegistry::set( 1797, 'venue', array(
			new WP_Term( array( 'term_id' => 7, 'name' => 'LO-Fi Brewing', 'taxonomy' => 'venue' ) ),
		) );
		VenueDataRegistry::set( 7, $venue );
		return ec_seo_build_event_schema( $post, array_merge( array( 'startDate' => '2026-11-25' ), $attrs ) );
	}

	public function test_valid_from_on_each_offer_type_and_timezone(): void {
		foreach ( array(
			array( 'price' => '$15' ),
			array( 'price' => '$15-$30' ),
			array( 'ticketUrl' => 'https://tickets.example.com/show' ),
		) as $offer ) {
			foreach ( array(
				array( '2026-07-01T10:30:00', 'America/New_York', '2026-07-01T10:30:00-04:00' ),
				array( '2026-01-01 10:30:00', 'America/New_York', '2026-01-01T10:30:00-05:00' ),
				array( '2026-07-01t10:30:00', '', '2026-07-01T10:30:00+00:00' ),
				array( '2026-07-01T10:30:00Z', 'America/New_York', '2026-07-01T10:30:00+00:00' ),
				array( '2026-07-01T10:30:00.123+0530', 'America/New_York', '2026-07-01T10:30:00+05:30' ),
			) as list( $input, $timezone, $expected ) ) {
				$schema = $this->build( array_merge( $offer, array( 'validFrom' => $input ) ), array( 'timezone' => $timezone ) );
				$this->assertSame( $expected, $schema['offers']['validFrom'] );
				$this->assertSame( isset( $offer['price'] ) && '$15-$30' === $offer['price'] ? 'AggregateOffer' : 'Offer', $schema['offers']['@type'] );
			}
		}
	}

	public function test_absent_or_invalid_valid_from_is_never_synthesized(): void {
		foreach ( array( null, '', 'tomorrow', '2026-02-30T10:00:00Z', '2026-07-01', '2026-07-01T10:30', '2026-07-01T24:00:00Z', '2026-07-01T10:60:00Z', '2026-07-01T10:30:00+25:00' ) as $input ) {
			foreach ( array( array( 'price' => '$15' ), array( 'price' => '$15-$30' ), array( 'ticketUrl' => 'https://tickets.example.com/show' ) ) as $offer ) {
				$attrs = null === $input ? $offer : array_merge( $offer, array( 'validFrom' => $input ) );
				$schema = $this->build( $attrs );
				$this->assertArrayNotHasKey( 'validFrom', $schema['offers'] );
			}
		}
		$schema = $this->build( array( 'validFrom' => '2026-07-01T10:00:00Z' ) );
		$this->assertArrayNotHasKey( 'offers', $schema );
	}

	public function test_matching_venue_attribute_or_term_supplies_website(): void {
		foreach ( array( 'lo fi brewing', 'Other venue label' ) as $venue_name ) {
			$schema = $this->build( array(
				'venue' => $venue_name,
				'organizer' => '  LO–FI   Brewing! ',
				'organizerUrl' => '   ',
			), array( 'website' => 'https://lofibrewing.com' ) );
			$this->assertSame( 'https://lofibrewing.com', $schema['organizer']['url'] );
		}
		$schema = $this->build( array( 'venue' => 'The Hall', 'organizer' => 'the hall' ), array( 'website' => 'https://hall.example.com' ) );
		$this->assertSame( 'https://hall.example.com', $schema['organizer']['url'] );
	}

	public function test_website_is_not_invented_for_missing_website_or_distinct_organizer(): void {
		foreach ( array(
			array( 'LO-Fi Brewing', array() ),
			array( 'Distinct Promoter', array( 'website' => 'https://lofibrewing.com' ) ),
			array( '!!!', array( 'website' => 'https://lofibrewing.com' ) ),
		) as list( $organizer, $venue ) ) {
			$schema = $this->build( array( 'organizer' => $organizer, 'ticketUrl' => 'https://tickets.example.com/show' ), $venue );
			$this->assertArrayNotHasKey( 'url', $schema['organizer'] );
		}
	}

	public function test_explicit_organizer_url_wins(): void {
		$schema = $this->build( array( 'organizer' => 'LO-Fi Brewing', 'organizerUrl' => 'https://organizer.example.com' ), array( 'website' => 'https://lofibrewing.com' ) );
		$this->assertSame( 'https://organizer.example.com', $schema['organizer']['url'] );
	}
}
