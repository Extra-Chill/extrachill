<?php
/**
 * Integration-style tests for the production global-lookup path.
 *
 * These exercise ec_seo_resolve_offer_url() — the production entry point
 * that resolves its domain helpers via function_exists() at GLOBAL names —
 * against the globally-defined test doubles registered in tests/bootstrap.php
 * (which mirror data-machine-events' inc/public-api.php global exports,
 * data-machine-events#820).
 *
 * The injected-callable unit tests in SchemaEventOfferUrlTest cannot catch
 * the bug class where production resolves a wrong global name: injecting
 * callables skips the function_exists() lookup entirely. These tests can.
 * If the global name consumed in production drifts from the contract, the
 * lookup returns null, resolution degrades to
 * RESOLUTION_FALLBACK_HELPERS_UNAVAILABLE, and the vendor-url assertions
 * here fail.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ExtraChill\SEO\Tests\Support\TicketUrlRegistry;

use function ExtraChill\SEO\Schema\ec_seo_resolve_offer_url;
use const ExtraChill\SEO\Schema\RESOLUTION_FALLBACK_UNPARSEABLE;
use const ExtraChill\SEO\Schema\RESOLUTION_PASSTHROUGH;
use const ExtraChill\SEO\Schema\RESOLUTION_VENDOR_URL;

final class SchemaEventOfferUrlGlobalResolutionTest extends TestCase {

	private const WRAPPER_URL = 'https://go.affiliate-wrapper.test/c/9999999/264167/4272?u=https%3A%2F%2Fwww.vendor.example%2Fevent%2Fabc123&utm_medium=affiliate';
	private const VENDOR_URL  = 'https://www.vendor.example/event/abc123';
	private const PERMALINK   = 'https://events.example.com/events/test-event';

	protected function setUp(): void {
		TicketUrlRegistry::reset();
	}

	public function test_resolver_resolves_both_helpers_at_their_global_names(): void {
		// Proves the doubles really are registered as global functions — the
		// precondition these integration tests depend on.
		$this->assertTrue( function_exists( 'data_machine_events_is_affiliate_ticket_url' ) );
		$this->assertTrue( function_exists( 'datamachine_unwrap_affiliate_url' ) );

		TicketUrlRegistry::set_affiliate_urls( array( self::WRAPPER_URL ) );
		TicketUrlRegistry::set_unwrap_map( array( self::WRAPPER_URL => self::VENDOR_URL ) );

		$resolved = ec_seo_resolve_offer_url( self::WRAPPER_URL, self::PERMALINK );

		$this->assertSame( self::VENDOR_URL, $resolved );
	}

	public function test_resolver_degrades_to_permalink_for_unparseable_wrapper_via_global_lookup(): void {
		TicketUrlRegistry::set_affiliate_urls( array( self::WRAPPER_URL ) );

		$this->assertSame( self::PERMALINK, ec_seo_resolve_offer_url( self::WRAPPER_URL, self::PERMALINK ) );
	}

	public function test_resolver_passes_non_affiliate_url_through_via_global_lookup(): void {
		$direct = 'https://tickets.example.com/buy/1';

		$this->assertSame( $direct, ec_seo_resolve_offer_url( $direct, self::PERMALINK ) );
	}

	public function test_resolution_states_distinguish_fallback_reasons(): void {
		// Observable-degradation contract: the two permalink fallbacks are
		// distinct RESOLUTION_* states even though both emit the same URL.
		$this->assertNotSame( RESOLUTION_FALLBACK_UNPARSEABLE, RESOLUTION_VENDOR_URL );
		$this->assertNotSame( RESOLUTION_FALLBACK_UNPARSEABLE, RESOLUTION_PASSTHROUGH );

		$unparseable = \ExtraChill\SEO\Schema\ec_seo_compute_offer_url(
			self::WRAPPER_URL,
			self::PERMALINK,
			fn( $url ) => true,
			fn( $url ) => $url
		);
		$this->assertSame( self::PERMALINK, $unparseable['url'] );
		$this->assertSame( RESOLUTION_FALLBACK_UNPARSEABLE, $unparseable['resolution'] );
	}
}
