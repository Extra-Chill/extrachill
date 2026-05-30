<?php
/**
 * Tests for VenueQualificationAbilities::analyzeForTicketmasterMarkers() —
 * the pure (no I/O) classifier that decides whether a (final URL, HTML body)
 * pair indicates a Ticketmaster/Live Nation OWNED page.
 *
 * Regression coverage for extrachill-events#90: the previous precheck
 * substring-matched `ticketmaster.com` anywhere in the page HTML, which
 * false-disqualified promoter aggregator sites (Bowery Presents, AEG
 * Presents, etc.) that legitimately link out to TM only as a ticket vendor.
 *
 * The fixture `tests/Fixtures/bowery-presents-shows.html` was captured live
 * from https://www.bowerypresents.com/shows/ and contains outbound TM event
 * links — it is the canonical negative-case regression anchor.
 *
 * @package ExtraChillEvents\Tests\Unit\Abilities
 */

namespace ExtraChillEvents\Tests\Unit\Abilities;

use ExtraChillEvents\Abilities\VenueQualificationAbilities;
use PHPUnit\Framework\TestCase;

class VenueQualificationTicketmasterPrecheckTest extends TestCase {

	// ---- Positive cases: SHOULD disqualify (LN-owned page). ----

	public function test_disqualifies_when_final_url_is_ticketmaster(): void {
		// Simulates: input URL redirected to a ticketmaster.com venue page.
		// Body can be empty — the final URL host alone is decisive.
		$result = VenueQualificationAbilities::analyzeForTicketmasterMarkers(
			'https://www.ticketmaster.com/venue/123456',
			'<html><body>anything</body></html>'
		);
		$this->assertTrue( $result['disqualified'] );
		$this->assertStringContainsString( 'final URL', $result['matched'] );
		$this->assertStringContainsString( 'ticketmaster.com', $result['matched'] );
	}

	public function test_disqualifies_when_final_url_is_livenation(): void {
		$result = VenueQualificationAbilities::analyzeForTicketmasterMarkers(
			'https://concerts.livenation.com/venue/some-slug',
			''
		);
		$this->assertTrue( $result['disqualified'] );
		$this->assertStringContainsString( 'final URL', $result['matched'] );
		$this->assertStringContainsString( 'livenation.com', $result['matched'] );
	}

	public function test_disqualifies_when_canonical_points_to_tm(): void {
		// Final URL is on a third-party host BUT the canonical link tag
		// declares the page is really a TM property.
		$html   = <<<'HTML'
<html>
<head>
  <title>Some Venue</title>
  <link rel="canonical" href="https://www.ticketmaster.com/venue/some-slug-12345" />
</head>
<body>fake mirror</body>
</html>
HTML;
		$result = VenueQualificationAbilities::analyzeForTicketmasterMarkers(
			'https://some-mirror.example.com/venue/some-slug',
			$html
		);
		$this->assertTrue( $result['disqualified'] );
		$this->assertStringContainsString( 'canonical', $result['matched'] );
	}

	public function test_disqualifies_when_jsonld_organization_is_ln(): void {
		$html   = <<<'HTML'
<html><head>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "@id": "https://www.livenation.com/#organization",
  "name": "Live Nation",
  "url": "https://www.livenation.com"
}
</script>
</head><body></body></html>
HTML;
		$result = VenueQualificationAbilities::analyzeForTicketmasterMarkers(
			'https://example.com/venue/abc',
			$html
		);
		$this->assertTrue( $result['disqualified'] );
		$this->assertStringContainsString( 'JSON-LD Organization', $result['matched'] );
		$this->assertStringContainsString( 'livenation.com', $result['matched'] );
	}

	// ---- Negative cases: must NOT disqualify (extraction should proceed). ----

	public function test_does_not_disqualify_when_only_outbound_tm_links(): void {
		// Regression anchor for issue #90. Live HTML capture of
		// https://www.bowerypresents.com/shows/. The page contains outbound
		// <a href="https://www.ticketmaster.com/event/…"> buy-ticket links
		// but is hosted on bowerypresents.com, has no LN meta/canonical/SDK.
		$fixture = dirname( __DIR__, 2 ) . '/Fixtures/bowery-presents-shows.html';
		$this->assertFileExists(
			$fixture,
			'Bowery Presents fixture missing — re-pull it (see test file docblock).'
		);
		$html = (string) file_get_contents( $fixture );

		// Sanity check the fixture: it MUST contain at least one outbound TM
		// link, otherwise the regression-anchor property of this test is
		// vacuous and we should re-pull.
		$this->assertMatchesRegularExpression(
			'#href=["\']https?://(?:www\.)?ticketmaster\.com/event/#i',
			$html,
			'Bowery fixture has no outbound TM event links — re-pull it.'
		);

		$result = VenueQualificationAbilities::analyzeForTicketmasterMarkers(
			'https://www.bowerypresents.com/shows/',
			$html
		);
		$this->assertFalse(
			$result['disqualified'],
			'Bowery Presents must not be disqualified: matched=' . $result['matched']
		);
		$this->assertSame( '', $result['matched'] );
	}

	public function test_does_not_disqualify_when_aeg_presents_aggregator(): void {
		// Baseline: a similar-shape aggregator with no TM/LN signals at all
		// must always pass through. Confirms the analyzer does not over-fire
		// on incidental schedule-page markup.
		$html   = <<<'HTML'
<html>
<head>
  <title>AEG Presents — Shows</title>
  <link rel="canonical" href="https://www.aegpresents.com/shows" />
  <meta property="og:site_name" content="AEG Presents" />
</head>
<body>
  <ul class="shows">
    <li><a href="https://www.aegpresents.com/event/show-1">Show 1</a></li>
    <li><a href="https://www.axs.com/event/12345">Show 2 — Buy on AXS</a></li>
  </ul>
</body>
</html>
HTML;
		$result = VenueQualificationAbilities::analyzeForTicketmasterMarkers(
			'https://www.aegpresents.com/shows',
			$html
		);
		$this->assertFalse( $result['disqualified'] );
		$this->assertSame( '', $result['matched'] );
	}

	// ---- Bonus coverage: subdomain hosts and brand meta tags. ----

	public function test_disqualifies_when_final_url_is_concerts_livenation_subdomain(): void {
		$result = VenueQualificationAbilities::analyzeForTicketmasterMarkers(
			'https://m.livenation.com/event/xyz',
			''
		);
		$this->assertTrue( $result['disqualified'] );
		$this->assertStringContainsString( 'livenation.com', $result['matched'] );
	}

	public function test_disqualifies_when_meta_site_name_is_ticketmaster(): void {
		$html   = '<html><head><meta property="og:site_name" content="Ticketmaster"/></head><body></body></html>';
		$result = VenueQualificationAbilities::analyzeForTicketmasterMarkers(
			'https://example.com/foo',
			$html
		);
		$this->assertTrue( $result['disqualified'] );
		$this->assertStringContainsString( 'site_name', $result['matched'] );
	}
}
