<?php
/**
 * Tests for extrachill_analytics_categorize_404_url() — the canonical owner
 * of 404 pattern categorization (issue #134).
 *
 * Covers the scanner-path-probe category that stops filesystem LFI targets,
 * dependency manifests, and Java web descriptors from being buried in the
 * actionable 'content' bucket, plus regressions for the precedence order and
 * the actionable/scanner category helpers.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/core/404-categorizer.php';

/**
 * Verifies categorization precedence, the new scanner-path-probe bucket, and
 * the actionable/scanner partition used by the 404 read-side reports.
 */
final class Categorize404UrlTest extends TestCase {

	/**
	 * Root-level scanner probe forms that previously fell through to 'content'.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_root_scanner_probes() {
		return array(
			'etc passwd'        => array( '/etc/passwd' ),
			'windows win.ini'   => array( '/windows/win.ini' ),
			'win.ini'           => array( '/win.ini' ),
			'boot.ini'          => array( '/boot.ini' ),
			'package.json'      => array( '/package.json' ),
			'package-lock.json' => array( '/package-lock.json' ),
			'gemfile'           => array( '/Gemfile' ),
			'gemfile.lock'      => array( '/Gemfile.lock' ),
			'composer.json'     => array( '/composer.json' ),
			'yarn.lock'         => array( '/yarn.lock' ),
			'requirements.txt'  => array( '/requirements.txt' ),
			'pipfile'           => array( '/Pipfile' ),
			'WEB-INF web.xml'   => array( '/WEB-INF/web.xml' ),
			'META-INF manifest' => array( '/META-INF/MANIFEST.MF' ),
			'jsp endpoint'      => array( '/admin/index.jsp' ),
			'jspx endpoint'     => array( '/x.jspx' ),
		);
	}

	/**
	 * Root scanner probes classify as scanner-path-probe, not content.
	 *
	 * @dataProvider provider_root_scanner_probes
	 *
	 * @param string $url Requested 404 path.
	 */
	public function test_root_scanner_probes_classify_as_scanner_path_probe( $url ) {
		$this->assertSame( 'scanner-path-probe', extrachill_analytics_categorize_404_url( $url ), "Failed for URL: {$url}" );
	}

	/**
	 * Scanner probes nested beneath taxonomy prefixes must NOT be masked by the
	 * taxonomy-prefix catches that fire later in the precedence chain.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_nested_scanner_probes() {
		return array(
			'events + etc/passwd'  => array( '/events/etc/passwd' ),
			'festival + WEB-INF'   => array( '/festival/WEB-INF/web.xml' ),
			'community + manifest' => array( '/t/package.json' ),
			'date-prefix + passwd' => array( '/2023/04/etc/passwd' ),
			'deep nested gemfile'  => array( '/blog/2024/Gemfile' ),
			'nested composer.json' => array( '/artists/composer.json' ),
		);
	}

	/**
	 * Nested probes hit scanner-path-probe before the taxonomy/content catches.
	 *
	 * @dataProvider provider_nested_scanner_probes
	 *
	 * @param string $url Requested 404 path.
	 */
	public function test_nested_scanner_probes_are_not_masked_by_taxonomy( $url ) {
		$this->assertSame( 'scanner-path-probe', extrachill_analytics_categorize_404_url( $url ), "Failed for URL: {$url}" );
	}

	/**
	 * URL-encoded scanner probes (including JSP variants) must land in the
	 * scanner bucket via the rawurldecoded view.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_encoded_scanner_probes() {
		return array(
			'encoded WEB-INF'      => array( '%2FWEB-INF%2Fweb.xml' ),
			'encoded traversal'    => array( '%2e%2e%2fetc%2fpasswd' ),
			'jsp null-byte suffix' => array( '/foo.jsp%00' ),
			'jsp with query'       => array( '/x.jspx?q=1' ),
			'encoded package.json' => array( '/%70ackage.json' ),
		);
	}

	/**
	 * Encoded scanner variants decode into scanner-path-probe.
	 *
	 * @dataProvider provider_encoded_scanner_probes
	 *
	 * @param string $url Requested 404 path.
	 */
	public function test_encoded_scanner_probes_decode_into_scanner_bucket( $url ) {
		$this->assertSame( 'scanner-path-probe', extrachill_analytics_categorize_404_url( $url ), "Failed for URL: {$url}" );
	}

	/**
	 * Legitimate missing content must keep resolving to actionable categories.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_legitimate_actionable_content() {
		return array(
			'plain slug'       => array( '/some-real-post-slug', 'content' ),
			'legacy html'      => array( '/old-page.html', 'legacy-html' ),
			'date prefix'      => array( '/2023/04/my-post', 'date-prefix' ),
			'events real'      => array( '/events/some-real-show', 'events' ),
			'festival real'    => array( '/festival/bonnaroo-2025', 'festival' ),
			'community thread' => array( '/t/community-topic', 'community-thread' ),
			'missing upload'   => array( '/wp-content/uploads/2024/01/img.png', 'missing-upload' ),
			'old sitemap'      => array( '/sitemap.xml', 'old-sitemap' ),
			'join page'        => array( '/join', 'join-page' ),
		);
	}

	/**
	 * Legitimate content resolves to its existing actionable category and stays
	 * actionable.
	 *
	 * @dataProvider provider_legitimate_actionable_content
	 *
	 * @param string $url      Requested 404 path.
	 * @param string $expected Expected category name.
	 */
	public function test_legitimate_missing_content_stays_actionable( $url, $expected ) {
		$category = extrachill_analytics_categorize_404_url( $url );
		$this->assertSame( $expected, $category, "Failed for URL: {$url}" );
		$this->assertTrue( extrachill_analytics_is_actionable_404_category( $category ), "Category {$category} should be actionable ({$url})" );
	}

	/**
	 * Existing hostile categories must keep firing before scanner-path-probe.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_existing_hostile_categories() {
		return array(
			'sql injection'    => array( '/?id=union+select', 'sql-injection' ),
			'secret env'       => array( '/.env', 'secret-probe' ),
			'config backup'    => array( '/wp-config.php.bak', 'config-probe' ),
			'wpjson user enum' => array( '/wp-json/wp/v2/users', 'wpjson-user-enum' ),
			'php probe'        => array( '/wp-login.php', 'php-probe' ),
			'bot probe'        => array( '/admin/', 'bot-probe' ),
			'author enum'      => array( '?author=1', 'author-enum' ),
		);
	}

	/**
	 * Earlier hostile categories keep precedence over scanner-path-probe.
	 *
	 * @dataProvider provider_existing_hostile_categories
	 *
	 * @param string $url      Requested 404 path.
	 * @param string $expected Expected category name.
	 */
	public function test_existing_hostile_categories_keep_precedence( $url, $expected ) {
		$this->assertSame( $expected, extrachill_analytics_categorize_404_url( $url ), "Failed for URL: {$url}" );
	}

	/**
	 * The scanner-path-probe category is partitioned as scanner traffic, never actionable.
	 */
	public function test_scanner_path_probe_partition() {
		$this->assertTrue( extrachill_analytics_is_scanner_404_category( 'scanner-path-probe' ) );
		$this->assertFalse( extrachill_analytics_is_actionable_404_category( 'scanner-path-probe' ) );
	}

	/**
	 * The actionable content categories remain actionable (regression guard for
	 * redirect detection preservation).
	 */
	public function test_actionable_categories_remain_actionable() {
		$must_be_actionable = array(
			'content',
			'legacy-html',
			'date-prefix',
			'events',
			'festival',
			'community-thread',
			'missing-upload',
			'old-sitemap',
			'join-page',
			'ad-txt',
		);
		foreach ( $must_be_actionable as $category ) {
			$this->assertTrue( extrachill_analytics_is_actionable_404_category( $category ), "Category {$category} must stay actionable" );
			$this->assertFalse( extrachill_analytics_is_scanner_404_category( $category ), "Category {$category} must NOT be scanner traffic" );
		}
	}
}
