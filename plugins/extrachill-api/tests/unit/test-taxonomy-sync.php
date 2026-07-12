<?php
/**
 * Unit tests for taxonomy sync contracts.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_Taxonomy_Sync extends TestCase {

	/**
	 * Target sites are public site slugs, not numeric blog IDs.
	 */
	public function test_target_site_sanitizer_preserves_slugs() {
		$result = extrachill_api_taxonomy_sync_sanitize_site_slugs(
			array( 'community', 'events', 'community', '', 'News Wire' )
		);

		$this->assertSame( array( 'community', 'events', 'newswire' ), $result );
	}

	/**
	 * Numeric values do not silently become valid blog identifiers.
	 */
	public function test_target_site_sanitizer_rejects_numeric_values() {
		$result = extrachill_api_taxonomy_sync_sanitize_site_slugs( array( 2, '2', 'events' ) );

		$this->assertSame( array( 'events' ), $result );
	}

	/**
	 * Only shared taxonomy slugs may pass through the route.
	 */
	public function test_taxonomy_sanitizer_enforces_shared_taxonomies() {
		$result = extrachill_api_taxonomy_sync_sanitize_taxonomies(
			array( 'location', 'artist', 'category', 'venue', 'artist' )
		);

		$this->assertSame( array( 'location', 'artist', 'venue' ), $result );
	}

	/**
	 * Terms are grouped under their source parent IDs.
	 */
	public function test_terms_are_grouped_by_parent() {
		$root         = (object) array( 'term_id' => 10, 'parent' => 0 );
		$child        = (object) array( 'term_id' => 11, 'parent' => 10 );
		$second_root  = (object) array( 'term_id' => 12, 'parent' => 0 );
		$hierarchy    = extrachill_api_organize_terms_by_parent( array( $root, $child, $second_root ) );

		$this->assertSame( array( $root, $second_root ), $hierarchy[0] );
		$this->assertSame( array( $child ), $hierarchy[10] );
	}
}
