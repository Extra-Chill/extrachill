<?php
/**
 * The link-page analytics route is a thin adapter over the
 * extrachill/get-link-page-analytics ability.
 *
 * Owner resolution and the artist permission check live in the ability
 * (extrachill-analytics). A second copy here resolved the artist with
 * `ec_get_artist_id`, which treats a Link Pages site post ID as an Artist site
 * post ID after the cutover (Extra-Chill/extrachill#1311).
 *
 * @package ExtraChillAPI
 */

use PHPUnit\Framework\TestCase;

class LinkPageAnalyticsAbilityAdapterTest extends TestCase {

	public function test_route_delegates_to_the_ability() {
		$source = $this->route_source();

		$this->assertStringContainsString( "wp_get_ability( 'extrachill/get-link-page-analytics' )", $source );
		$this->assertStringContainsString( '$ability->execute(', $source );
		$this->assertStringContainsString( "'link_page_id' => absint( \$request->get_param( 'link_page_id' ) )", $source );
		$this->assertStringContainsString( "'date_range'   => absint( \$request->get_param( 'date_range' ) )", $source );
	}

	public function test_route_has_no_parallel_permission_logic() {
		$source = $this->route_source();

		$this->assertStringNotContainsString( 'ec_get_artist_id', $source );
		$this->assertStringNotContainsString( 'ec_can_manage_artist', $source );
		$this->assertStringNotContainsString( 'extrachill_get_link_page_analytics', $source );
	}

	public function test_route_fails_closed_without_the_ability() {
		$this->assertStringContainsString( "'ability_not_found'", $this->route_source() );
	}

	/**
	 * @return string
	 */
	private function route_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		return file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/analytics/link-page.php' );
	}
}
