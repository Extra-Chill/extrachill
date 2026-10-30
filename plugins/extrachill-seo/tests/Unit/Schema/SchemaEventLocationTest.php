<?php
/**
 * Event location url + geo, and single-Event ownership (#73).
 *
 * The graph Event is the only Event entity on event pages, so it carries the
 * venue website and coordinates the Event Details block used to emit.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ExtraChill\SEO\Tests\Support\TermMetaRegistry;
use ExtraChill\SEO\Tests\Support\TermRegistry;
use ExtraChill\SEO\Tests\Support\VenueDataRegistry;
use WP_Post;
use WP_Term;

use function ExtraChill\SEO\Schema\ec_seo_build_event_schema;
use function ExtraChill\SEO\Schema\ec_seo_parse_geo_coordinates;

final class SchemaEventLocationTest extends TestCase {

	protected function setUp(): void {
		TermRegistry::reset();
		TermMetaRegistry::reset();
		VenueDataRegistry::reset();
	}

	private function build_with_venue( array $venue ): array {
		$post = new WP_Post( array(
			'ID'        => 486727,
			'post_title' => 'Meetup',
			'post_name' => 'meetup',
			'permalink' => 'https://events.example.com/events/meetup',
		) );
		TermRegistry::set( $post->ID, 'venue', array(
			new WP_Term( array( 'term_id' => 7, 'name' => 'Lo-Fi Brewing', 'slug' => 'lo-fi-brewing', 'taxonomy' => 'venue' ) ),
		) );
		VenueDataRegistry::set( 7, array_merge( array( 'name' => 'Lo-Fi Brewing', 'city' => 'Charleston' ), $venue ) );

		return ec_seo_build_event_schema( $post, array( 'startDate' => '2026-10-21', 'venue' => 'Lo-Fi Brewing' ) );
	}

	public function test_location_carries_venue_url_and_geo(): void {
		$schema = $this->build_with_venue( array(
			'website'     => 'https://lofibrewing.com',
			'coordinates' => '32.8337927, -79.9536861',
		) );

		$this->assertSame( 'https://lofibrewing.com', $schema['location']['url'] );
		$this->assertSame(
			array( '@type' => 'GeoCoordinates', 'latitude' => '32.8337927', 'longitude' => '-79.9536861' ),
			$schema['location']['geo']
		);
	}

	public function test_location_omits_url_and_geo_when_venue_lacks_them(): void {
		$schema = $this->build_with_venue( array() );

		$this->assertArrayNotHasKey( 'url', $schema['location'] );
		$this->assertArrayNotHasKey( 'geo', $schema['location'] );
	}

	public function test_malformed_coordinates_produce_no_geo(): void {
		foreach ( array( '32.8', '32.8,', 'a,b', '1,2,3', '' ) as $bad ) {
			$this->assertNull( ec_seo_parse_geo_coordinates( $bad ), "coordinates {$bad}" );
		}

		$schema = $this->build_with_venue( array( 'coordinates' => '32.8' ) );
		$this->assertArrayNotHasKey( 'geo', $schema['location'] );
	}

	public function test_plugin_claims_single_event_ownership(): void {
		$this->assertFalse( apply_filters( 'data_machine_events_output_event_schema', true, 486727 ) );
	}
}
