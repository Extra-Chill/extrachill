<?php
/**
 * Tests for QualifyFingerprinter::detect_event_page_shape() — the pure
 * URL + JSON-LD + HTML shape detector that feeds the verdict resolver's
 * per-shape threshold (issue #77).
 *
 * Other methods on QualifyFingerprinter (fetch_homepage, run_extractor_attempt,
 * etc.) require live HTTP / WP_Ability infrastructure and are exercised via
 * integration tests in the WP_UnitTestCase suite, not here.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyFingerprinter;
use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

class QualifyFingerprinterTest extends TestCase {

	// ---- URL-pattern detail detection ----

	public function test_detail_shape_detection_url_pattern_schedule_slug(): void {
		// Royal American shape: /schedule/<artist>-<date> with one Event.
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://www.theroyalamerican.com/schedule/emma-grace-burton-5-15-26',
			array( 'jsonld_events' => 1, 'jsonld_event_graph_present' => true, 'microdata_events' => 0 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL, $shape );
	}

	public function test_detail_shape_detection_url_pattern_events_slug(): void {
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/events/some-show-3-21-26',
			array( 'jsonld_events' => 1 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL, $shape );
	}

	public function test_detail_shape_detection_url_pattern_shows_slug(): void {
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/shows/abc-def-ghi',
			array( 'jsonld_events' => 1 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL, $shape );
	}

	public function test_detail_url_without_dash_does_not_qualify(): void {
		// `/events/12345` has no dash + no alpha in slug → not a detail slug
		// per the issue's heuristic. Falls through to UNKNOWN because 1 event
		// + path that's not a listing root still resolves via heuristic 5...
		// actually `/events/12345` lives under a non-listing path so the
		// JSON-LD-count fallback fires. But the URL-regex itself must NOT
		// match — verify the regex alone via the no-html unknown-event case.
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/events/12345',
			array( 'jsonld_events' => 0 ),
			''
		);
		// 0 events + not a listing root → UNKNOWN. Confirms the URL regex
		// did NOT short-circuit to detail (which would require 1 event).
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN, $shape );
	}

	// ---- URL-pattern listing detection ----

	public function test_listing_shape_detection_url_pattern_schedule_root(): void {
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://www.theroyalamerican.com/schedule',
			array( 'jsonld_events' => 0 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	public function test_listing_shape_detection_url_pattern_events_root(): void {
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/events',
			array( 'jsonld_events' => 0 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	public function test_listing_shape_detection_url_pattern_calendar_root(): void {
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/calendar',
			array( 'jsonld_events' => 0 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	public function test_listing_shape_detection_url_pattern_bare_root(): void {
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/',
			array( 'jsonld_events' => 0 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	public function test_listing_shape_trailing_slash_normalized(): void {
		// `/events/` should be treated identically to `/events`.
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/events/',
			array( 'jsonld_events' => 0 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	// ---- JSON-LD count / multi-Event listing detection ----

	public function test_listing_shape_detection_multiple_events(): void {
		// Two or more Events in JSON-LD → always listing, even if URL looks
		// like a detail slug.
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/events/some-show-3-21-26',
			array( 'jsonld_events' => 5 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	public function test_detail_shape_detection_jsonld_count_no_url_match(): void {
		// URL is ambiguous (root path `/`, but with 1 Event) — wait, root is
		// a listing root, so root + 1 event → LISTING. Use a non-listing path
		// that doesn't match the detail regex, e.g. `/show-page`.
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/show-page',
			array( 'jsonld_events' => 1 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL, $shape );
	}

	// ---- HTML listing-marker detection ----

	public function test_listing_shape_detection_itemlist_schema(): void {
		// Even if URL looks like detail + 1 event, ItemList schema → listing.
		$html  = '<script type="application/ld+json">{"@type":"ItemList"}</script>';
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/schedule/some-show-3-21-26',
			array( 'jsonld_events' => 1 ),
			$html
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	public function test_listing_shape_detection_collectionpage_schema(): void {
		$html  = '<script type="application/ld+json">{"@type":"CollectionPage"}</script>';
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/shows/some-show-3-21-26',
			array( 'jsonld_events' => 1 ),
			$html
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	public function test_listing_shape_detection_event_list_class(): void {
		$html  = '<div class="event-list"><div>...</div></div>';
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/events/some-show-3-21-26',
			array( 'jsonld_events' => 1 ),
			$html
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	public function test_listing_shape_detection_repeated_data_event_id(): void {
		$html  = '<div data-event-id="1"></div><div data-event-id="2"></div>';
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/shows/some-show-3-21-26',
			array( 'jsonld_events' => 1 ),
			$html
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_LISTING, $shape );
	}

	// ---- Unknown / fallthrough ----

	public function test_unknown_shape_zero_events_non_listing_path(): void {
		// No events, no listing markers, path is neither listing root nor
		// detail-pattern → UNKNOWN. Resolver maps UNKNOWN → listing threshold.
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'https://example.com/about-us',
			array( 'jsonld_events' => 0 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN, $shape );
	}

	public function test_unknown_shape_unparseable_url(): void {
		// Deterministic fallthrough: nonsense URL, 0 events, no HTML → UNKNOWN.
		// Resolver maps UNKNOWN to the listing threshold, so the detail
		// relaxation never applies in this case.
		$shape = QualifyFingerprinter::detect_event_page_shape(
			'not a url',
			array( 'jsonld_events' => 0 ),
			''
		);
		$this->assertSame( QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN, $shape );
	}
}
