<?php
/**
 * Conversion-map author filter adapter contract.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Protect the thin API adapter without duplicating analytics semantics.
 */
final class ConversionMapAuthorFilterTest extends TestCase {
	/** Author scope is sanitized at the route and forwarded to the ability. */
	public function test_author_id_is_sanitized_and_forwarded(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/inc/routes/analytics/conversion-map.php' );

		$this->assertIsString( $source );
		$this->assertStringContainsString( "'author_id'", $source );
		$this->assertStringContainsString( "'minimum'  => 0", $source );
		$this->assertStringContainsString( "'author_id'          => max( 0, (int) \$request->get_param( 'author_id' ) )", $source );
	}
}
