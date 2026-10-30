<?php
/**
 * Architecture contract for taxonomy projection ownership.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Ensures admin routes do not reintroduce Network-owned taxonomy projection.
 */
final class TaxonomyProjectionOwnershipTest extends TestCase {
	/**
	 * Taxonomy projection and persistence belong to Extra Chill Network.
	 */
	public function test_admin_routes_do_not_own_taxonomy_projection(): void {
		$routes = dirname( __DIR__, 2 ) . '/inc/routes';
		$files  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $routes, FilesystemIterator::SKIP_DOTS )
		);
		$source = '';

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
			$route_source = file_get_contents( $file->getPathname() );
			$this->assertNotFalse( $route_source );
			$source .= $route_source;
		}

		$this->assertFileDoesNotExist( $routes . '/admin/taxonomy-sync.php' );
		$this->assertStringNotContainsString( '/admin/taxonomies/sync', $source );
		$this->assertStringNotContainsString( 'wp_insert_term(', $source );
	}
}
