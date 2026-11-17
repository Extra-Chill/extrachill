<?php
/**
 * Guard for extrachill#1621: REST adapters must call registered abilities.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Every literal ability name a route resolves must be registered by some
 * plugin in this monorepo. Static source scan: routes that point at renamed
 * or removed abilities otherwise only fail at runtime.
 */
final class AbilityReferencesRegisteredTest extends TestCase {
	/**
	 * Namespaces registered by plugins outside this monorepo (Data Machine,
	 * Data Machine Business). They cannot be resolved statically here.
	 */
	private const EXTERNAL_NAMESPACES = array( 'datamachine' );

	/**
	 * Known stale adapters, tracked separately. Each entry must still be
	 * referenced and still be unregistered, so fixing one forces its removal
	 * from this list.
	 *
	 * GET/POST /users/me/subscriptions wrap the retired email-sharing
	 * abilities (see extrachill-api/docs/routes/users/account.md).
	 */
	private const KNOWN_STALE = array(
		'extrachill/get-subscriptions',
		'extrachill/update-subscriptions',
	);

	public function test_route_abilities_are_registered(): void {
		$plugins_dir = dirname( __DIR__, 3 );
		if ( ! is_dir( $plugins_dir . '/extrachill-community' ) || ! is_dir( $plugins_dir . '/extrachill-newsletter' ) ) {
			$this->markTestSkipped( 'Sibling monorepo plugins are not available.' );
		}

		$registered = $this->registered_names( $plugins_dir );
		$refs       = $this->referenced_names( dirname( __DIR__, 2 ) . '/inc' );

		foreach ( array( 'extrachill/community-list-forums', 'extrachill/create-managed-qr-code', 'extrachill/grant-lifetime-membership' ) as $known ) {
			$this->assertArrayHasKey( $known, $registered, 'Registration scanner missed ' . $known );
		}
		$this->assertGreaterThan( 50, count( $refs ), 'Reference scanner pattern is broken.' );

		$missing = array();
		foreach ( $refs as $name => $files ) {
			if ( in_array( strtok( $name, '/' ), self::EXTERNAL_NAMESPACES, true ) || isset( $registered[ $name ] ) || in_array( $name, self::KNOWN_STALE, true ) ) {
				continue;
			}
			$missing[] = $name . ' (' . implode( ', ', array_unique( $files ) ) . ')';
		}
		$this->assertSame( array(), $missing, 'Routes reference abilities no monorepo plugin registers.' );

		foreach ( self::KNOWN_STALE as $name ) {
			$this->assertArrayHasKey( $name, $refs, $name . ' is no longer referenced; drop it from KNOWN_STALE.' );
			$this->assertArrayNotHasKey( $name, $registered, $name . ' is registered again; drop it from KNOWN_STALE.' );
		}
	}

	/**
	 * PHP source files under a directory, skipping tests and dependencies.
	 *
	 * @param string $dir Directory.
	 * @return string[]
	 */
	private function php_files( string $dir ): array {
		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = str_replace( '\\', '/', $file->getPathname() );
			if ( preg_match( '#/(tests|vendor|node_modules|build)/#', $path ) ) {
				continue;
			}
			$files[] = $path;
		}
		sort( $files );
		return $files;
	}

	/**
	 * Ability names registered by the monorepo plugins.
	 *
	 * Literal `wp_register_ability( 'ns/name', ...)` calls are read directly.
	 * Files that register through a variable, constant, concatenation or
	 * sprintf() contribute every `ns/name`-shaped literal they contain, plus
	 * `'ns/' . $slug . 'suffix'` and `'ns/%s-suffix'` templates expanded with
	 * the file's slug literals.
	 *
	 * @param string $plugins_dir Monorepo plugins directory.
	 * @return array<string, true>
	 */
	private function registered_names( string $plugins_dir ): array {
		$registered = array();
		foreach ( $this->php_files( $plugins_dir ) as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
			$source = (string) file_get_contents( $path );
			if ( false === strpos( $source, 'wp_register_ability' ) ) {
				continue;
			}

			preg_match_all( "/wp_register_ability\\(\\s*'([a-z0-9-]+\\/[a-z0-9-]+)'\\s*,/", $source, $literal );
			foreach ( $literal[1] as $name ) {
				$registered[ $name ] = true;
			}

			if ( ! preg_match( "/wp_register_ability\\(\\s*(?!'[a-z0-9-]+\\/[a-z0-9-]+'\\s*,)[^)\\s]/", $source ) ) {
				continue;
			}

			preg_match_all( "/'([a-z0-9-]+\\/[a-z0-9-]+)'/", $source, $names );
			foreach ( $names[1] as $name ) {
				$registered[ $name ] = true;
			}

			preg_match_all( "/'([a-z0-9-]+)'/", $source, $slugs );
			$slugs = array_unique( $slugs[1] );

			preg_match_all( "/'([a-z0-9-]+\\/[a-z0-9-]*)'\\s*\\.\\s*\\$[a-z_]+(?:\\s*\\.\\s*'([a-z0-9-]*)')?/i", $source, $concat, PREG_SET_ORDER );
			foreach ( $concat as $match ) {
				foreach ( $slugs as $slug ) {
					$registered[ $match[1] . $slug . ( $match[2] ?? '' ) ] = true;
				}
			}

			preg_match_all( "/'([a-z0-9-]+\\/[a-z0-9-]*%s[a-z0-9-]*)'/", $source, $templates );
			foreach ( $templates[1] as $template ) {
				foreach ( $slugs as $slug ) {
					$registered[ sprintf( $template, $slug ) ] = true;
				}
			}
		}
		return $registered;
	}

	/**
	 * Literal ability names the routes resolve, keyed by name.
	 *
	 * @param string $dir Route source directory.
	 * @return array<string, string[]> Name => relative file paths.
	 */
	private function referenced_names( string $dir ): array {
		$refs = array();
		foreach ( $this->php_files( $dir ) as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
			$source = (string) file_get_contents( $path );
			preg_match_all( "/(?:wp_get_ability|wp_has_ability)\\(\\s*'([a-z0-9-]+\\/[a-z0-9-]+)'/", $source, $calls );
			preg_match_all( "/'ability'\\s*=>\\s*'([a-z0-9-]+\\/[a-z0-9-]+)'/", $source, $declared );
			foreach ( array_merge( $calls[1], $declared[1] ) as $name ) {
				$refs[ $name ][] = substr( $path, strlen( dirname( $dir ) ) + 1 );
			}
		}
		ksort( $refs );
		return $refs;
	}
}
