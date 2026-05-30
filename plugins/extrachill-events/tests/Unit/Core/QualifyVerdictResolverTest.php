<?php
/**
 * Tests for QualifyVerdictResolver — the pure decision tree.
 *
 * Each branch of the resolution order gets its own test. Every test also
 * asserts that the canonical agent_guidance string matches verbatim, since
 * downstream agents key off the wording.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\QualifyVerdictResolver;
use PHPUnit\Framework\TestCase;

class QualifyVerdictResolverTest extends TestCase {

	public function test_ticketmaster_precheck_wins_first(): void {
		$fingerprint = array(
			'http_status'           => 200,
			'ticketmaster_precheck' => array(
				'disqualified' => true,
				'matched'      => 'ticketmaster.com (in page HTML)',
			),
			// Even with a structured extraction, TM precheck still wins.
			'extractor_attempts'    => array(
				array(
					'name'   => 'JsonLdExtractor',
					'exists' => true,
					'ran'    => true,
					'events' => 5,
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::COVERED_ELSEWHERE, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_COVERED_ELSEWHERE, $verdict['agent_guidance'] );
		$this->assertStringContainsString( 'ticketmaster.com', $verdict['improvement_hint'] );
		$this->assertSame( 0, $verdict['event_count'] );
	}

	public function test_zero_http_status_resolves_unreachable(): void {
		$fingerprint = array(
			'http_status' => 0,
			'timeout'     => false,
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::UNREACHABLE, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_UNREACHABLE, $verdict['agent_guidance'] );
	}

	public function test_timeout_flag_resolves_unreachable(): void {
		$fingerprint = array(
			'http_status' => 200, // ignored when timeout is set
			'timeout'     => true,
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::UNREACHABLE, $verdict['verdict'] );
	}

	public function test_403_resolves_bot_blocked(): void {
		$fingerprint = array( 'http_status' => 403 );

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::BOT_BLOCKED, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_BOT_BLOCKED, $verdict['agent_guidance'] );
		$this->assertStringContainsString( 'HTTP 403', $verdict['improvement_hint'] );
	}

	public function test_429_resolves_bot_blocked(): void {
		$verdict = QualifyVerdictResolver::resolve( array( 'http_status' => 429 ) );
		$this->assertSame( QualifyVerdict::BOT_BLOCKED, $verdict['verdict'] );
	}

	public function test_cloudflare_challenge_resolves_bot_blocked(): void {
		$fingerprint = array(
			'http_status'          => 200,
			'cloudflare_challenge' => true,
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::BOT_BLOCKED, $verdict['verdict'] );
		$this->assertStringContainsString( 'Cloudflare', $verdict['improvement_hint'] );
	}

	public function test_5xx_resolves_unreachable(): void {
		$verdict = QualifyVerdictResolver::resolve( array( 'http_status' => 502 ) );
		$this->assertSame( QualifyVerdict::UNREACHABLE, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_UNREACHABLE, $verdict['agent_guidance'] );
	}

	public function test_structured_extractor_meeting_threshold_qualifies(): void {
		$fingerprint = array(
			'http_status'        => 200,
			'final_url'          => 'https://example.com/calendar',
			'extractor_attempts' => array(
				array(
					'name'       => 'BandzoogleExtractor',
					'exists'     => true,
					'matched'    => true,
					'ran'        => true,
					'events'     => 12,
					'events_url' => 'https://example.com/calendar',
				),
				array(
					'name'    => 'JsonLdExtractor',
					'exists'  => true,
					'matched' => false,
					'ran'     => true,
					'events'  => 0,
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::QUALIFIED_STRUCTURED, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_QUALIFIED_STRUCTURED, $verdict['agent_guidance'] );
		$this->assertSame( 12, $verdict['event_count'] );
		$this->assertSame( 'https://example.com/calendar', $verdict['events_url'] );
	}

	public function test_single_event_does_not_qualify_structured(): void {
		// MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION is 2 — a single event is
		// not enough to qualify on a non-detail page, even from a structured
		// extractor. Shape defaults to UNKNOWN here → listing threshold applies.
		$fingerprint = array(
			'http_status'        => 200,
			'extractor_attempts' => array(
				array(
					'name'   => 'JsonLdExtractor',
					'exists' => true,
					'ran'    => true,
					'events' => 1,
				),
			),
			'structured_data'    => array(
				'jsonld_events'              => 1,
				'jsonld_event_graph_present' => true,
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertNotSame( QualifyVerdict::QUALIFIED_STRUCTURED, $verdict['verdict'] );
		// Should fall into EXTRACTION_GAP because structured data is present.
		$this->assertSame( QualifyVerdict::EXTRACTION_GAP, $verdict['verdict'] );
	}

	public function test_single_event_detail_page_qualifies_with_one_event(): void {
		// Issue #77: a single-event detail page (Royal American shape) emits
		// exactly 1 Event by design. Once the fingerprinter classifies it as
		// `event_page_shape=detail`, the resolver MUST issue
		// QUALIFIED_STRUCTURED instead of the old EXTRACTION_GAP misverdict.
		$fingerprint = array(
			'http_status'        => 200,
			'final_url'          => 'https://www.theroyalamerican.com/schedule/emma-grace-burton-5-15-26',
			'structured_data'    => array(
				'jsonld_events'              => 1,
				'jsonld_event_graph_present' => true,
				'event_page_shape'           => QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL,
			),
			'extractor_attempts' => array(
				array(
					'name'       => 'JsonLdExtractor',
					'exists'     => true,
					'matched'    => true,
					'ran'        => true,
					'events'     => 1,
					'events_url' => 'https://www.theroyalamerican.com/schedule/emma-grace-burton-5-15-26',
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::QUALIFIED_STRUCTURED, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_QUALIFIED_STRUCTURED, $verdict['agent_guidance'] );
		$this->assertSame( 1, $verdict['event_count'] );
		$this->assertSame(
			'https://www.theroyalamerican.com/schedule/emma-grace-burton-5-15-26',
			$verdict['events_url']
		);
	}

	public function test_listing_page_still_requires_two_events(): void {
		// Listing pages keep the ≥2 threshold — a single-event match on a
		// shape=listing fingerprint must NOT qualify, otherwise the original
		// stray-snippet false-positive guard would be defeated.
		$fingerprint = array(
			'http_status'        => 200,
			'final_url'          => 'https://example.com/calendar',
			'structured_data'    => array(
				'jsonld_events'              => 1,
				'jsonld_event_graph_present' => true,
				'event_page_shape'           => QualifyVerdict::EVENT_PAGE_SHAPE_LISTING,
			),
			'extractor_attempts' => array(
				array(
					'name'    => 'JsonLdExtractor',
					'exists'  => true,
					'matched' => true,
					'ran'     => true,
					'events'  => 1,
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertNotSame( QualifyVerdict::QUALIFIED_STRUCTURED, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::EXTRACTION_GAP, $verdict['verdict'] );
	}

	public function test_unknown_shape_still_requires_two_events(): void {
		// Conservative default: shape=unknown uses the listing threshold.
		// 1 event + unknown shape → still EXTRACTION_GAP, not QUALIFIED.
		$fingerprint = array(
			'http_status'        => 200,
			'structured_data'    => array(
				'jsonld_events'              => 1,
				'jsonld_event_graph_present' => true,
				'event_page_shape'           => QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN,
			),
			'extractor_attempts' => array(
				array(
					'name'    => 'JsonLdExtractor',
					'exists'  => true,
					'matched' => true,
					'ran'     => true,
					'events'  => 1,
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertNotSame( QualifyVerdict::QUALIFIED_STRUCTURED, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::EXTRACTION_GAP, $verdict['verdict'] );
	}

	public function test_detail_shape_with_two_events_still_qualifies(): void {
		// Detail threshold (1) is a floor, not a ceiling — a page classified
		// as detail that happens to have 2+ events should still qualify.
		$fingerprint = array(
			'http_status'        => 200,
			'final_url'          => 'https://example.com/events/some-show-3-21-26',
			'structured_data'    => array(
				'jsonld_events'    => 2,
				'event_page_shape' => QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL,
			),
			'extractor_attempts' => array(
				array(
					'name'       => 'JsonLdExtractor',
					'exists'     => true,
					'matched'    => true,
					'ran'        => true,
					'events'     => 2,
					'events_url' => 'https://example.com/events/some-show-3-21-26',
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::QUALIFIED_STRUCTURED, $verdict['verdict'] );
		$this->assertSame( 2, $verdict['event_count'] );
	}

	public function test_vision_only_resolves_qualified_for_flyer(): void {
		$fingerprint = array(
			'http_status'        => 200,
			'final_url'          => 'https://example.com/',
			'extractor_attempts' => array(
				array(
					'name'   => 'JsonLdExtractor',
					'exists' => true,
					'ran'    => true,
					'events' => 0,
				),
				array(
					'name'        => 'VisionExtractor',
					'exists'      => true,
					'ran'         => true,
					'events'      => 1,
					'source_type' => 'vision_flyer',
					'events_url'  => 'https://example.com/',
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::QUALIFIED_FOR_FLYER, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_QUALIFIED_FOR_FLYER, $verdict['agent_guidance'] );
		$this->assertStringContainsString( 'event_flyer', $verdict['improvement_hint'] );
	}

	public function test_jsonld_present_but_unparsed_resolves_extraction_gap(): void {
		$fingerprint = array(
			'http_status'        => 200,
			'extractor_attempts' => array(
				array(
					'name'   => 'JsonLdExtractor',
					'exists' => true,
					'ran'    => true,
					'events' => 0,
				),
			),
			'structured_data'    => array(
				'jsonld_events'              => 0,
				'jsonld_event_graph_present' => true,
				'microdata_events'           => 0,
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::EXTRACTION_GAP, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_EXTRACTION_GAP, $verdict['agent_guidance'] );
		$this->assertStringContainsString( 'JsonLdExtractor', $verdict['improvement_hint'] );
	}

	public function test_platform_without_extractor_resolves_extraction_gap_with_class_hint(): void {
		$fingerprint = array(
			'http_status'        => 200,
			'platforms_detected' => array( 'bandzoogle' ),
			'structured_data'    => array(),
			'extractor_attempts' => array(
				array(
					'name'    => 'BandzoogleExtractor',
					'exists'  => false,
					'matched' => false,
					'ran'     => false,
					'events'  => 0,
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::EXTRACTION_GAP, $verdict['verdict'] );
		$this->assertStringContainsString( 'BandzoogleExtractor', $verdict['improvement_hint'] );
		$this->assertStringContainsString( 'bandzoogle platform detected', $verdict['improvement_hint'] );
	}

	public function test_reservation_only_platform_resolves_reservation_only(): void {
		$fingerprint = array(
			'http_status'        => 200,
			'platforms_detected' => array( 'opentable' ),
			'structured_data'    => array(),
			'extractor_attempts' => array(),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::RESERVATION_ONLY, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_RESERVATION_ONLY, $verdict['agent_guidance'] );
	}

	public function test_reservation_platform_plus_other_platform_does_not_resolve_reservation_only(): void {
		// If OpenTable is detected alongside e.g. Squarespace, the venue may
		// still publish events — do NOT permanently disqualify.
		$fingerprint = array(
			'http_status'        => 200,
			'platforms_detected' => array( 'opentable', 'squarespace' ),
			'structured_data'    => array(),
			'extractor_attempts' => array(
				// SquarespaceExtractor exists and matched but found nothing.
				array(
					'name'    => 'SquarespaceExtractor',
					'exists'  => true,
					'matched' => true,
					'ran'     => true,
					'events'  => 0,
				),
			),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertNotSame( QualifyVerdict::RESERVATION_ONLY, $verdict['verdict'] );
	}

	public function test_default_fallback_is_extraction_gap(): void {
		$fingerprint = array(
			'http_status'        => 200,
			'platforms_detected' => array(),
			'structured_data'    => array(),
			'extractor_attempts' => array(),
		);

		$verdict = QualifyVerdictResolver::resolve( $fingerprint );

		$this->assertSame( QualifyVerdict::EXTRACTION_GAP, $verdict['verdict'] );
		$this->assertSame( QualifyVerdict::GUIDANCE_EXTRACTION_GAP, $verdict['agent_guidance'] );
	}

	/**
	 * Verbatim guidance assertions for every verdict — agents key off the
	 * exact wording, so any drift in the constants would break them.
	 *
	 * @dataProvider guidanceProvider
	 */
	public function test_guidance_for_returns_verbatim_string( string $verdict, string $expected ): void {
		$this->assertSame( $expected, QualifyVerdict::guidance_for( $verdict ) );
	}

	public static function guidanceProvider(): array {
		return array(
			'qualified_structured' => array( QualifyVerdict::QUALIFIED_STRUCTURED, 'Safe to promote to a live flow. Recommend the operator wire it via wp extrachill venues add.' ),
			'qualified_for_flyer'  => array( QualifyVerdict::QUALIFIED_FOR_FLYER, 'Vision detected a likely flyer image, but no structured events were extracted. Fetch the source_url directly. Verify the image is actually an event flyer (not a logo, decoration, or stale ad). If it is a genuine flyer, recommend the operator wire it with the event_flyer handler, NOT universal_web_scraper. If it is noise, recommend pausing and filing the URL for re-qualification when extractor coverage improves.' ),
			'extraction_gap'       => array( QualifyVerdict::EXTRACTION_GAP, 'Page is reachable and contains structured data, but our extractors did not parse it. Fetch the URL via WebFetch and inspect the HTML. If you can identify a predictable pattern (JSON-LD shape, platform-specific markup, etc.), file an issue against data-machine-events suggesting the extractor fix. DO NOT recommend wiring this venue until the extractor lands.' ),
			'bot_blocked'          => array( QualifyVerdict::BOT_BLOCKED, 'HTTP 403/429 — venue origin is blocking our scraper. No code-level fix is possible without proxy support. Park the URL; revisit when proxy support lands. Do NOT recommend wiring.' ),
			'reservation_only'     => array( QualifyVerdict::RESERVATION_ONLY, 'Venue uses OpenTable/Resy/Tock for reservations only and does not publish event listings. Permanently disqualified. Do NOT recommend wiring. Do NOT file an extractor issue.' ),
			'unreachable'          => array( QualifyVerdict::UNREACHABLE, 'Site DNS/timeout/5xx. Could be transient. Requeue for re-qualification in 7 days. If still unreachable then, permanently disqualify.' ),
			'covered_elsewhere'    => array( QualifyVerdict::COVERED_ELSEWHERE, 'Venue is a Ticketmaster/Live Nation property. Already covered by the dedicated Ticketmaster flow. Do NOT recommend wiring.' ),
		);
	}

	public function test_guidance_for_unknown_verdict_returns_empty_string(): void {
		$this->assertSame( '', QualifyVerdict::guidance_for( 'no_such_verdict' ) );
	}

	public function test_is_qualified_helper(): void {
		$this->assertTrue( QualifyVerdict::is_qualified( QualifyVerdict::QUALIFIED_STRUCTURED ) );
		$this->assertTrue( QualifyVerdict::is_qualified( QualifyVerdict::QUALIFIED_FOR_FLYER ) );
		$this->assertFalse( QualifyVerdict::is_qualified( QualifyVerdict::EXTRACTION_GAP ) );
		$this->assertFalse( QualifyVerdict::is_qualified( QualifyVerdict::RESERVATION_ONLY ) );
	}

	public function test_is_requalifiable_helper(): void {
		$this->assertTrue( QualifyVerdict::is_requalifiable( QualifyVerdict::EXTRACTION_GAP ) );
		$this->assertTrue( QualifyVerdict::is_requalifiable( QualifyVerdict::BOT_BLOCKED ) );
		$this->assertTrue( QualifyVerdict::is_requalifiable( QualifyVerdict::UNREACHABLE ) );
		$this->assertFalse( QualifyVerdict::is_requalifiable( QualifyVerdict::COVERED_ELSEWHERE ) );
		$this->assertFalse( QualifyVerdict::is_requalifiable( QualifyVerdict::RESERVATION_ONLY ) );
		$this->assertFalse( QualifyVerdict::is_requalifiable( QualifyVerdict::QUALIFIED_STRUCTURED ) );
	}

	public function test_min_events_thresholds_are_distinct(): void {
		// Listing-page threshold MUST stay at 2 — the existing guard against
		// stray-snippet false positives. Detail-page threshold relaxes to 1
		// because legitimate /schedule/<slug> pages emit exactly 1 Event.
		$this->assertSame( 2, QualifyVerdict::MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION );
		$this->assertSame( 1, QualifyVerdict::MIN_EVENTS_FOR_DETAIL_PAGE );
	}

	public function test_event_page_shape_enum_values(): void {
		$this->assertSame( 'detail', QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL );
		$this->assertSame( 'listing', QualifyVerdict::EVENT_PAGE_SHAPE_LISTING );
		$this->assertSame( 'unknown', QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN );
	}
}
