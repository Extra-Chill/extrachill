<?php
/** Release packaging must retain compiled block version targets. */
use PHPUnit\Framework\TestCase;

final class ReleaseArtifactTest extends TestCase {
	public function test_build_version_targets_are_not_excluded(): void {
		$plugin_dir = dirname( __DIR__ );
		$config     = json_decode( file_get_contents( $plugin_dir . '/homeboy.json' ), true, 512, JSON_THROW_ON_ERROR );
		$rules      = array_map( 'trim', file( $plugin_dir . '/.buildignore', FILE_IGNORE_NEW_LINES ) );
		$targets    = array_filter( $config['version_targets'], static function ( $target ) {
			return 0 === strpos( $target['artifact_path'] ?? '', 'build/' );
		} );

		$this->assertNotEmpty( $targets, 'Expected a compiled block version target in homeboy.json.' );
		foreach ( array( 'build', 'build/', '/build', '/build/', 'build/*', 'build/**', 'build/***', '/build/*', '/build/**', '/build/***' ) as $exclusion ) {
			$this->assertNotContains( $exclusion, $rules, 'Compiled block version targets must be included in the release artifact.' );
		}
	}
}
