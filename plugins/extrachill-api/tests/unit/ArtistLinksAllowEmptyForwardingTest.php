<?php
/**
 * The links PUT route must forward explicit "empty this page" intent.
 *
 * Storage refuses a save that takes a populated Link Page to zero links
 * unless the caller states the intent, so a broken client cannot silently
 * wipe a page (extrachill-artist-platform#224/#225). This route is the last
 * hop in that chain: if it drops the flag, a legitimate "remove all links"
 * is impossible no matter what the client sends.
 *
 * @package ExtraChillAPI
 */

use PHPUnit\Framework\TestCase;

class ArtistLinksAllowEmptyForwardingTest extends TestCase {

	public function test_links_put_forwards_allow_empty_to_the_save_ability() {
		$source = $this->links_route_source();

		$this->assertStringContainsString(
			"\$save_input['allow_empty'] = true;",
			$source,
			'The route must forward explicit empty-page intent into the save ability input.'
		);
		$this->assertStringContainsString(
			'$ability->execute( $save_input )',
			$source,
			'The save ability must be called with the input array that carries the intent.'
		);
	}

	public function test_allow_empty_forwarding_is_strictly_boolean() {
		$source = $this->links_route_source();

		$this->assertStringContainsString(
			"isset( \$body['allow_empty'] ) && true === \$body['allow_empty']",
			$source,
			'Only a real boolean true may authorise a destructive save; "true" or 1 must not.'
		);
		$this->assertStringNotContainsString(
			"! empty( \$body['allow_empty'] )",
			$source,
			'A truthy check would let a stray string or 1 authorise clearing every link.'
		);
	}

	public function test_links_are_still_forwarded_unconditionally() {
		$source = $this->links_route_source();

		$this->assertStringContainsString(
			"'links'     => \$body['links'],",
			$source,
			'Forwarding intent must not disturb the existing links payload.'
		);
	}

	/**
	 * Read the links route source.
	 *
	 * @return string
	 */
	private function links_route_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		return file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/artists/links.php' );
	}
}
