<?php
/**
 * The link-page analytics route must resolve the Link Page type from the
 * storage site, not a hardcoded literal on the serving blog.
 *
 * After the Link Pages site cutover the record lives on the dedicated Link
 * Pages blog with post type `ec_link_page` (extrachill-link-pages#34). A
 * current-blog `'artist_link_page'` check would reject every valid Link Page
 * with HTTP 400 and silently break artist analytics.
 *
 * @package ExtraChillAPI
 */

use PHPUnit\Framework\TestCase;

class LinkPageAnalyticsTypeResolutionTest extends TestCase {

	public function test_route_validates_through_the_storage_aware_helper() {
		$source = $this->route_source();

		$this->assertStringContainsString(
			'if ( ! extrachill_api_is_link_page( $link_page_id ) ) {',
			$source,
			'The handler must validate through the storage-aware helper.'
		);
		$this->assertStringNotContainsString(
			"get_post_type( \$link_page_id ) !== 'artist_link_page'",
			$source,
			'The legacy current-blog literal check must be gone from the handler.'
		);
	}

	public function test_helper_reads_type_on_the_storage_site() {
		$source = $this->route_source();

		$this->assertStringContainsString( 'ec_with_link_page_storage_blog(', $source, 'The post must be read from the storage site.' );
		$this->assertStringContainsString(
			'ec_link_page_post_type( $storage_blog_id ) === get_post_type( $link_page_id )',
			$source,
			'The expected type must be resolved for the same site the post is read from.'
		);
	}

	public function test_helper_fails_closed_and_degrades_to_legacy_behaviour() {
		$source = $this->route_source();

		$this->assertStringContainsString(
			'return true === $matches;',
			$source,
			'A storage WP_Error must not be treated as a match.'
		);
		$this->assertStringContainsString(
			"return 'artist_link_page' === get_post_type( \$link_page_id );",
			$source,
			'Without the runtime, behaviour must be exactly the pre-cutover check.'
		);
	}

	/**
	 * @return string
	 */
	private function route_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		return file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/analytics/link-page.php' );
	}
}
