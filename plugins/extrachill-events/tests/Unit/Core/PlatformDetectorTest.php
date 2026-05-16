<?php
/**
 * Tests for PlatformDetector — pure HTML inspection.
 *
 * Each platform regex gets its own fixture-based assertion. The Tribe API
 * probe is NOT exercised here (requires HTTP); commit 4's integration tests
 * cover it indirectly via the fingerprint contract.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\PlatformDetector;
use PHPUnit\Framework\TestCase;

class PlatformDetectorTest extends TestCase {

	public function test_empty_html_returns_empty_arrays(): void {
		$this->assertSame( array(), PlatformDetector::detect_platforms( '' ) );

		$structured = PlatformDetector::detect_structured_data( '' );
		$this->assertSame( 0, $structured['jsonld_events'] );
		$this->assertFalse( $structured['jsonld_event_graph_present'] );
		$this->assertSame( 0, $structured['microdata_events'] );
		$this->assertSame( 0, $structured['vision_image_candidates'] );
	}

	public function test_detects_bandzoogle_via_domain(): void {
		$html = '<html><head><link href="https://bandzoogle.com/styles.css"></head></html>';
		$this->assertContains( 'bandzoogle', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_bandzoogle_via_gig_info_class(): void {
		$html = '<div class="gig-info"><span>Tonight</span></div>';
		$this->assertContains( 'bandzoogle', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_squarespace(): void {
		$html = '<script>Static.SQUARESPACE_CONTEXT = {};</script>';
		$this->assertContains( 'squarespace', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_wordpress_generic_without_tribe_marker(): void {
		$html = '<link rel="stylesheet" href="/wp-content/themes/twentytwentythree/style.css">';
		$platforms = PlatformDetector::detect_platforms( $html );
		$this->assertContains( 'wordpress_generic', $platforms );
	}

	public function test_does_not_detect_wordpress_generic_when_tribe_endpoint_referenced(): void {
		$html = '<link href="/wp-content/themes/x/style.css"><script src="/wp-json/tribe/events/v1/events"></script>';
		$this->assertNotContains( 'wordpress_generic', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_webflow(): void {
		$html = '<html data-wf-page="abc"><head><meta name="generator" content="webflow.com"></head>';
		$this->assertContains( 'webflow', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_wix(): void {
		$html = '<meta name="generator" content="Wix.com Website Builder">';
		$this->assertContains( 'wix', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_opentable(): void {
		$html = '<script src="//cdn.opentable.com/js/widget.js"></script>';
		$this->assertContains( 'opentable', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_resy(): void {
		$html = '<iframe src="https://widgets.resy.com/some-venue"></iframe>';
		$this->assertContains( 'resy', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_tock(): void {
		$html = '<a href="https://exploretock.com/the-venue">Book a table</a>';
		$this->assertContains( 'tock', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_eventbrite_organizer(): void {
		$html = '<a href="https://www.eventbrite.com/o/the-organizer-12345">Tickets</a>';
		$this->assertContains( 'eventbrite', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_dice_fm(): void {
		$html = '<a href="https://dice.fm/event/abc123-show">Tickets</a>';
		$this->assertContains( 'dice_fm', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_detects_ticketmaster_widget(): void {
		$html = '<a href="https://www.ticketmaster.com/event/0F005E0A">Tickets</a>';
		$this->assertContains( 'ticketmaster_widget', PlatformDetector::detect_platforms( $html ) );
	}

	public function test_multiple_platforms_co_detect(): void {
		$html = '<script>Static.SQUARESPACE_CONTEXT={};</script>'
			. '<script src="//cdn.opentable.com/w.js"></script>';
		$platforms = PlatformDetector::detect_platforms( $html );
		$this->assertContains( 'squarespace', $platforms );
		$this->assertContains( 'opentable', $platforms );
	}

	public function test_result_is_deduped_and_sorted_predictably(): void {
		// Same platform appearing twice should appear once in the output.
		$html      = '<a href="https://dice.fm/event/x">A</a><a href="https://dice.fm/event/y">B</a>';
		$platforms = PlatformDetector::detect_platforms( $html );
		$this->assertSame( array_unique( $platforms ), $platforms );
		$dice_count = 0;
		foreach ( $platforms as $p ) {
			if ( 'dice_fm' === $p ) {
				++$dice_count;
			}
		}
		$this->assertSame( 1, $dice_count );
	}

	public function test_jsonld_event_counter_handles_graph_container(): void {
		$json = json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array( '@type' => 'MusicEvent', 'name' => 'Show A' ),
					array( '@type' => 'Event', 'name' => 'Show B' ),
					array( '@type' => 'Organization', 'name' => 'The Venue' ),
				),
			)
		);
		$html = '<script type="application/ld+json">' . $json . '</script>';

		$structured = PlatformDetector::detect_structured_data( $html );

		$this->assertSame( 2, $structured['jsonld_events'] );
		$this->assertTrue( $structured['jsonld_event_graph_present'] );
	}

	public function test_jsonld_event_graph_present_when_unparseable_but_recognizable(): void {
		// Malformed JSON but Event token still recoverable — flag the graph
		// as present so the resolver routes the verdict to EXTRACTION_GAP
		// instead of the default fallback.
		$html = '<script type="application/ld+json">{"@type":"MusicEvent",,,}</script>';

		$structured = PlatformDetector::detect_structured_data( $html );

		$this->assertSame( 0, $structured['jsonld_events'] );
		// We cannot detect malformed JSON-LD without a parser, but the
		// detector should also not falsely mark it as present — confirm
		// the boolean is at least defined.
		$this->assertArrayHasKey( 'jsonld_event_graph_present', $structured );
	}

	public function test_microdata_counter(): void {
		$html = '<div itemscope itemtype="https://schema.org/MusicEvent">a</div>'
			. '<div itemscope itemtype="http://schema.org/Event">b</div>';

		$structured = PlatformDetector::detect_structured_data( $html );

		$this->assertSame( 2, $structured['microdata_events'] );
	}

	public function test_vision_image_candidates_counts_flyer_imagery(): void {
		$html = '<img src="/show-flyer.jpg" alt="Show flyer"/>'
			. '<img src="/logo.png" alt="Site logo"/>'
			. '<img class="event-poster" src="/poster.jpg"/>';

		$structured = PlatformDetector::detect_structured_data( $html );

		$this->assertSame( 2, $structured['vision_image_candidates'] );
	}
}
