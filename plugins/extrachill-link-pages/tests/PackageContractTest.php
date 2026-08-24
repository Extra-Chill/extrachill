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
		foreach ( array( '/tests/', '/vendor/', '/tools/', '/composer.json', '/composer.lock', '/phpunit.xml.dist', '/phpcs.xml.dist', '/homeboy.json', '/docs/', '/.claude/', '/.datamachine/', '/.github/', '/AGENTS.md', '/CLAUDE.md' ) as $excluded ) {
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
				'extrachill-link-pages.php',
				'inc/operations.php',
				'inc/owner-reference.php',
				'inc/post-type.php',
			),
			$files
		);
	}
}
