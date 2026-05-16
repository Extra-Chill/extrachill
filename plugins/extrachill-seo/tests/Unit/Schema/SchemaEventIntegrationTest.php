<?php
/**
 * Integration test against the real production post_content for the
 * 2nd Annual 420 Festival event page.
 *
 * Validates that the end-to-end extract -> build pipeline produces a
 * schema.org-compliant MusicEvent entity with the expected shape.
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

use function ExtraChill\SEO\Schema\ec_seo_extract_event_details_block;
use function ExtraChill\SEO\Schema\ec_seo_build_event_schema;

final class SchemaEventIntegrationTest extends TestCase {

	protected function setUp(): void {
		TermRegistry::reset();
		VenueDataRegistry::reset();
	}

	public function test_real_420_festival_post_emits_valid_schema(): void {
		$content = file_get_contents( __DIR__ . '/../../Fixtures/event-420-festival-content.html' );
		$this->assertNotFalse( $content, 'Fixture file must exist.' );

		$attrs = ec_seo_extract_event_details_block( $content );

		// Block extraction sanity.
		$this->assertSame( '2026-04-20', $attrs['startDate'] );
		$this->assertSame( '18:00', $attrs['startTime'] );
		$this->assertSame( '2026-04-20', $attrs['endDate'] );
		$this->assertSame( 'The Cabooze', $attrs['venue'] );
		$this->assertSame( '913 Cedar Ave', $attrs['address'] );
		$this->assertSame( 'EventScheduled', $attrs['eventStatus'] );
		$this->assertSame( 'PreOrder', $attrs['offerAvailability'] );
		$this->assertStringContainsString( 'etix.com', $attrs['ticketUrl'] );

		$post = new WP_Post( array(
			'ID'           => 175555,
			'post_title'   => '2nd Annual 420 Festival at The Cabooze',
			'post_content' => $content,
			'post_excerpt' => 'The Cabooze hosts its 2nd Annual 420 Festival.',
			'post_name'    => '2nd-annual-420-festival',
			'post_type'    => 'data_machine_events',
			'permalink'    => 'https://events.extrachill.com/events/2nd-annual-420-festival',
		) );

		// Wire a venue term so location.address pulls postal data.
		$term = new WP_Term( array(
			'term_id'  => 100,
			'name'     => 'The Cabooze',
			'slug'     => 'the-cabooze',
			'taxonomy' => 'venue',
		) );
		TermRegistry::set( $post->ID, 'venue', array( $term ) );
		VenueDataRegistry::set( 100, array(
			'name'     => 'The Cabooze',
			'address'  => '913 Cedar Ave',
			'city'     => 'Minneapolis',
			'state'    => 'MN',
			'zip'      => '55454',
			'country'  => 'US',
			'timezone' => 'America/Chicago',
		) );

		$schema = ec_seo_build_event_schema( $post, $attrs );

		$this->assertNotNull( $schema );
		$this->assertSame( 'MusicEvent', $schema['@type'] );
		$this->assertSame( 'https://events.extrachill.com/events/2nd-annual-420-festival#event', $schema['@id'] );
		$this->assertSame( '2nd Annual 420 Festival at The Cabooze', $schema['name'] );
		$this->assertSame( '2026-04-20T18:00:00-05:00', $schema['startDate'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $schema['eventStatus'] );
		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $schema['eventAttendanceMode'] );

		// Location structure.
		$this->assertSame( 'Place', $schema['location']['@type'] );
		$this->assertSame( 'The Cabooze', $schema['location']['name'] );
		$this->assertSame( 'PostalAddress', $schema['location']['address']['@type'] );
		$this->assertSame( '913 Cedar Ave', $schema['location']['address']['streetAddress'] );
		$this->assertSame( 'Minneapolis', $schema['location']['address']['addressLocality'] );

		// Offers — fixture has ticketUrl + PreOrder availability, no numeric price.
		$this->assertSame( 'Offer', $schema['offers']['@type'] );
		$this->assertSame( 'https://schema.org/PreOrder', $schema['offers']['availability'] );
		$this->assertSame( 'USD', $schema['offers']['priceCurrency'] );
		$this->assertStringContainsString( 'etix.com', $schema['offers']['url'] );
		$this->assertArrayNotHasKey( 'price', $schema['offers'] );

		// Description falls back to excerpt.
		$this->assertSame( 'The Cabooze hosts its 2nd Annual 420 Festival.', $schema['description'] );

		// JSON-encodable: this is the production-critical check — the whole
		// reason this work exists is to land valid JSON-LD in <script> tags.
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertIsString( $json );
		$this->assertJson( $json );
		$this->assertStringContainsString( '"@type":"MusicEvent"', $json );
	}
}
