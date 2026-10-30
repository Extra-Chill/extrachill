<?php
/**
 * Tests for Event offers.url affiliate resolution.
 *
 * Covers the ticketing-affiliate-compliance contract: an affiliate
 * wrapper URL must never be emitted as offers.url. It resolves to the
 * de-affiliated vendor destination when parseable, and to the event
 * permalink otherwise. Fixture URLs use neutral example hosts so no
 * affiliate host or vendor literals exist in this plugin.
 *
 * @see https://github.com/Extra-Chill/extrachill-seo/issues/57
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ExtraChill\SEO\Tests\Support\TicketUrlRegistry;
use WP_Post;

use function ExtraChill\SEO\Schema\ec_seo_build_event_schema;
use function ExtraChill\SEO\Schema\ec_seo_compute_offer_url;
use function ExtraChill\SEO\Schema\ec_seo_resolve_offer_url;
use const ExtraChill\SEO\Schema\RESOLUTION_FALLBACK_HELPERS_UNAVAILABLE;
use const ExtraChill\SEO\Schema\RESOLUTION_FALLBACK_UNPARSEABLE;
use const ExtraChill\SEO\Schema\RESOLUTION_PASSTHROUGH;
use const ExtraChill\SEO\Schema\RESOLUTION_VENDOR_URL;

final class SchemaEventOfferUrlTest extends TestCase {

	private const WRAPPER_URL = 'https://go.affiliate-wrapper.test/c/9999999/264167/4272?u=https%3A%2F%2Fwww.vendor.example%2Fevent%2Fabc123&utm_medium=affiliate';
	private const VENDOR_URL  = 'https://www.vendor.example/event/abc123';
	private const PERMALINK   = 'https://events.example.com/events/test-event';

	protected function setUp(): void {
		TicketUrlRegistry::reset();
	}

	private function make_post(): WP_Post {
		return new WP_Post(
			array(
				'ID'           => 175556,
				'post_title'   => 'Test Event',
				'post_content' => '',
				'post_excerpt' => '',
				'post_name'    => 'test-event',
				'post_type'    => 'data_machine_events',
				'permalink'    => self::PERMALINK,
			)
		);
	}

	private function make_attrs( string $ticket_url ): array {
		return array(
			'startDate'     => '2026-04-20',
			'price'         => '$15',
			'priceCurrency' => 'USD',
			'ticketUrl'     => $ticket_url,
		);
	}

	// ---- pure resolution step ----

	public function test_affiliate_url_resolves_to_de_affiliated_vendor_url(): void {
		$resolved = ec_seo_compute_offer_url(
			self::WRAPPER_URL,
			self::PERMALINK,
			fn( $url ) => true,
			fn( $url ) => self::VENDOR_URL
		);

		$this->assertSame( self::VENDOR_URL, $resolved['url'] );
		$this->assertSame( RESOLUTION_VENDOR_URL, $resolved['resolution'] );
	}

	public function test_unparseable_affiliate_url_resolves_to_permalink(): void {
		// The real unwrapper returns its input unchanged when it cannot parse.
		$resolved = ec_seo_compute_offer_url(
			self::WRAPPER_URL,
			self::PERMALINK,
			fn( $url ) => true,
			fn( $url ) => $url
		);

		$this->assertSame( self::PERMALINK, $resolved['url'] );
		$this->assertSame( RESOLUTION_FALLBACK_UNPARSEABLE, $resolved['resolution'] );
	}

	public function test_helpers_absent_resolves_to_permalink(): void {
		$resolved = ec_seo_compute_offer_url( self::WRAPPER_URL, self::PERMALINK, null, null );

		$this->assertSame( self::PERMALINK, $resolved['url'] );
		$this->assertSame( RESOLUTION_FALLBACK_HELPERS_UNAVAILABLE, $resolved['resolution'] );
	}

	public function test_unwrapper_absent_resolves_to_permalink_for_affiliate_url(): void {
		$resolved = ec_seo_compute_offer_url(
			self::WRAPPER_URL,
			self::PERMALINK,
			fn( $url ) => true,
			null
		);

		$this->assertSame( self::PERMALINK, $resolved['url'] );
		$this->assertSame( RESOLUTION_FALLBACK_HELPERS_UNAVAILABLE, $resolved['resolution'] );
	}

	public function test_non_affiliate_url_passes_through_unchanged(): void {
		$direct   = 'https://tickets.example.com/buy/1';
		$resolved = ec_seo_compute_offer_url(
			$direct,
			self::PERMALINK,
			fn( $url ) => false,
			fn( $url ) => self::fail( 'Unwrapper must not be consulted for non-affiliate URLs' )
		);

		$this->assertSame( $direct, $resolved['url'] );
		$this->assertSame( RESOLUTION_PASSTHROUGH, $resolved['resolution'] );
	}

	// ---- production wrapper ----

	public function test_production_wrapper_resolves_with_registry_backed_helpers(): void {
		TicketUrlRegistry::set_affiliate_urls( array( self::WRAPPER_URL ) );
		TicketUrlRegistry::set_unwrap_map( array( self::WRAPPER_URL => self::VENDOR_URL ) );

		$this->assertSame( self::VENDOR_URL, ec_seo_resolve_offer_url( self::WRAPPER_URL, self::PERMALINK ) );
	}

	// ---- end-to-end through the schema builder ----

	public function test_build_emits_de_affiliated_vendor_url_in_offers(): void {
		TicketUrlRegistry::set_affiliate_urls( array( self::WRAPPER_URL ) );
		TicketUrlRegistry::set_unwrap_map( array( self::WRAPPER_URL => self::VENDOR_URL ) );

		$schema = ec_seo_build_event_schema( $this->make_post(), $this->make_attrs( self::WRAPPER_URL ) );

		$this->assertArrayHasKey( 'offers', $schema );
		$this->assertSame( self::VENDOR_URL, $schema['offers']['url'] );
		// The offers node stays intact for Google Event rich results.
		$this->assertSame( 'Offer', $schema['offers']['@type'] );
		$this->assertSame( '15.00', $schema['offers']['price'] );
		$this->assertSame( 'USD', $schema['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $schema['offers']['availability'] );
	}

	public function test_build_emits_permalink_when_affiliate_url_cannot_be_unwrapped(): void {
		TicketUrlRegistry::set_affiliate_urls( array( self::WRAPPER_URL ) );

		$schema = ec_seo_build_event_schema( $this->make_post(), $this->make_attrs( self::WRAPPER_URL ) );

		$this->assertArrayHasKey( 'offers', $schema );
		$this->assertSame( self::PERMALINK, $schema['offers']['url'] );
		$this->assertSame( 'Offer', $schema['offers']['@type'] );
		$this->assertSame( '15.00', $schema['offers']['price'] );
	}

	public function test_build_passes_non_affiliate_url_through_unchanged(): void {
		$direct = 'https://tickets.example.com/buy/1';

		$schema = ec_seo_build_event_schema( $this->make_post(), $this->make_attrs( $direct ) );

		$this->assertArrayHasKey( 'offers', $schema );
		$this->assertSame( $direct, $schema['offers']['url'] );
	}
}
