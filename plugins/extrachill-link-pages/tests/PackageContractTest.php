<?php

use PHPUnit\Framework\TestCase;

final class PackageContractTest extends TestCase {
	public function test_homeboy_package_manifest_contains_only_runtime_source_and_readme(): void {
		$root = dirname( __DIR__ );
		$this->assertFileDoesNotExist( $root . '/.distignore' );
		$this->assertFileExists( $root . '/.buildignore' );
		$this->assertFileDoesNotExist( $root . '/composer.json' );

		$config = json_decode( file_get_contents( $root . '/homeboy.json' ), true );
		$configured = $config['extensions']['wordpress']['settings']['package_excludes'];
		foreach ( array( '/tests/', '/vendor/', '/tools/', '/composer.json', '/composer.lock', '/package.json', '/phpunit.xml.dist', '/phpcs.xml.dist', '/homeboy.json', '/docs/', '/.claude/', '/.datamachine/', '/.github/', '/AGENTS.md', '/CLAUDE.md' ) as $excluded ) {
			$this->assertContains( $excluded, $configured );
		}

		$patterns = array_values( array_filter( array_map( 'trim', file( $root . '/.buildignore' ) ), static function ( $line ) { return '' !== $line && '#' !== $line[0]; } ) );
		$files = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			$top = explode( '/', $relative )[0];
			$excluded = in_array( $top . '/', $patterns, true ) || in_array( $top, $patterns, true ) || in_array( $relative, $patterns, true );
			if ( ! $excluded && '.zip' !== substr( $relative, -4 ) && '.tar.gz' !== substr( $relative, -7 ) ) {
				$files[] = $relative;
			}
		}
		sort( $files );
		$this->assertSame(
			array(
				'README.md',
				'assets/css/custom-social-icons.css',
				'assets/css/extrch-links.css',
				'assets/css/extrch-share-modal.css',
				'assets/js/extrch-share-modal.js',
				'assets/js/link-page-public-tracking.js',
				'assets/js/link-page-youtube-embed.js',
				'extrachill-link-pages.php',
				'inc/compatibility.php',
				'inc/operations.php',
				'inc/owner-reference.php',
				'inc/post-type.php',
				'inc/public-projections.php',
				'inc/public-runtime.php',
				'inc/storage.php',
				'templates/components/single-link.php',
				'templates/link-page.php',
				'templates/share-modal.php',
				'templates/single-link-page.php',
			),
			$files
		);
	}
}
