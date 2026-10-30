<?php
/**
 * Contract tests for the artist route genres adapter.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the extrachill/v1 artist routes mirror the artist-platform
 * genres contract: genres in as string[], genres/genre_labels out, and no
 * legacy genre string field.
 */
class ArtistRouteGenresAdapterTest extends TestCase {

	/**
	 * The create route exposes a sanitized genres array argument.
	 */
	public function test_create_route_exposes_sanitized_genres_array() {
		$source = $this->artist_route_source();

		$this->assertStringContainsString( "'genres'     => array(", $source );
		$this->assertStringContainsString( "'maxItems'          => 3,", $source );
		$this->assertStringContainsString( "'items'             => array(", $source );
		$this->assertStringContainsString( "array_map( 'sanitize_text_field', \$value )", $source );
	}

	/**
	 * Create forwards genres to the ability alongside the other optional fields.
	 */
	public function test_create_handler_forwards_genres() {
		$source = $this->artist_route_source();

		$this->assertStringContainsString( "array( 'bio', 'local_city', 'genres' )", $source );
	}

	/**
	 * Update forwards genres to the ability alongside the other mutable fields.
	 */
	public function test_update_handler_forwards_genres() {
		$source = $this->artist_route_source();

		$this->assertStringContainsString(
			"array( 'name', 'bio', 'local_city', 'genres', 'profile_image_id', 'header_image_id' )",
			$source
		);
	}

	/**
	 * No legacy genre string field or alias remains on the artist route.
	 */
	public function test_legacy_genre_field_is_gone() {
		$source = $this->artist_route_source();

		$this->assertStringNotContainsString( "'genre'", $source );
	}

	/**
	 * Reads and writes pass the ability projection through untouched, so
	 * genres and genre_labels flow straight from the ability into responses.
	 */
	public function test_handlers_return_ability_result_unchanged() {
		$source = $this->artist_route_source();

		$this->assertSame( 3, substr_count( $source, 'rest_ensure_response( $result )' ) );
		$this->assertStringNotContainsString( 'unset( $result', $source );
	}

	/**
	 * Return the artist route source.
	 */
	private function artist_route_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		return file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/artists/artist.php' );
	}
}
