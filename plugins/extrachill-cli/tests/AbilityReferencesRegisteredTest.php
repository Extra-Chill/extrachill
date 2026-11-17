<?php
/**
 * Standalone guard for extrachill#1621.
 *
 * Every literal ability name a CLI command resolves (wp_get_ability() and the
 * command helpers that wrap it) must be registered by some plugin in this
 * monorepo. Commands that point at renamed or removed abilities otherwise only
 * fail at runtime with "ability not available".
 *
 * Static source scan: no WordPress or WP-CLI required.
 */

$plugins_dir = dirname( __DIR__, 2 );
if ( ! is_dir( $plugins_dir . '/extrachill-community' ) || ! is_dir( $plugins_dir . '/extrachill-newsletter' ) ) {
	fwrite( STDOUT, "Ability reference guard skipped: sibling monorepo plugins are not available.\n" );
	return;
}

/**
 * Namespaces registered by plugins outside this monorepo (Data Machine,
 * Data Machine Business). They cannot be resolved statically here.
 */
const EXTERNAL_ABILITY_NAMESPACES = array( 'datamachine' );

/**
 * List PHP source files under a directory, skipping tests and dependencies.
 *
 * @param string $dir Directory.
 * @return string[]
 */
function ability_guard_php_files( string $dir ): array {
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
 * Collect every ability name registered by the monorepo plugins.
 *
 * Literal `wp_register_ability( 'ns/name', ...)` calls are read directly.
 * Files that register through a variable, constant, concatenation or
 * sprintf() (link-pages, studio, users, events, booking, ...) contribute
 * every `ns/name`-shaped literal they contain, plus `'ns/' . $slug . 'suffix'`
 * and `'ns/%s-suffix'` templates expanded with the file's slug literals.
 *
 * @param string $plugins_dir Monorepo plugins directory.
 * @return array<string, true>
 */
function ability_guard_registered_names( string $plugins_dir ): array {
	$registered = array();
	foreach ( ability_guard_php_files( $plugins_dir ) as $path ) {
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

		// 'ns/' . $slug [. 'suffix'].
		preg_match_all( "/'([a-z0-9-]+\\/[a-z0-9-]*)'\\s*\\.\\s*\\$[a-z_]+(?:\\s*\\.\\s*'([a-z0-9-]*)')?/i", $source, $concat, PREG_SET_ORDER );
		foreach ( $concat as $match ) {
			foreach ( $slugs as $slug ) {
				$registered[ $match[1] . $slug . ( $match[2] ?? '' ) ] = true;
			}
		}

		// sprintf( 'ns/%s-suffix', ... ).
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
 * Collect literal ability names an adapter resolves, keyed by name.
 *
 * @param string   $dir     Adapter source directory.
 * @param string[] $helpers Method names that take an ability name first.
 * @return array<string, string[]> Name => list of relative file paths.
 */
function ability_guard_referenced_names( string $dir, array $helpers ): array {
	$callers = '(?:wp_get_ability|wp_has_ability' . ( $helpers ? '|->(?:' . implode( '|', array_map( 'preg_quote', $helpers ) ) . ')' : '' ) . ')';
	$pattern = "/{$callers}\\(\\s*'([a-z0-9-]+\\/[a-z0-9-]+)'/";
	$refs    = array();
	foreach ( ability_guard_php_files( $dir ) as $path ) {
		$source = (string) file_get_contents( $path );
		preg_match_all( $pattern, $source, $matches );
		preg_match_all( "/'ability'\\s*=>\\s*'([a-z0-9-]+\\/[a-z0-9-]+)'/", $source, $declared );
		foreach ( array_merge( $matches[1], $declared[1] ) as $name ) {
			$refs[ $name ][] = substr( $path, strlen( dirname( $dir ) ) + 1 );
		}
	}
	ksort( $refs );
	return $refs;
}

$registered = ability_guard_registered_names( $plugins_dir );
$refs       = ability_guard_referenced_names(
	dirname( __DIR__ ) . '/inc',
	array( 'ability', 'get_ability', 'get_artist_ability', 'get_events_ability', 'run', 'execute', 'execute_ability' )
);

// Sanity checks so a broken scanner cannot pass vacuously.
foreach ( array( 'extrachill/community-toggle-forum-archive', 'extrachill/create-managed-qr-code', 'extrachill/grant-lifetime-membership' ) as $known ) {
	if ( ! isset( $registered[ $known ] ) ) {
		throw new RuntimeException( 'Registration scanner missed known ability ' . $known );
	}
}
if ( count( $refs ) < 50 ) {
	throw new RuntimeException( 'Reference scanner found only ' . count( $refs ) . ' ability names; pattern is broken.' );
}

$missing = array();
foreach ( $refs as $name => $files ) {
	if ( in_array( strtok( $name, '/' ), EXTERNAL_ABILITY_NAMESPACES, true ) || isset( $registered[ $name ] ) ) {
		continue;
	}
	$missing[] = $name . ' (' . implode( ', ', array_unique( $files ) ) . ')';
}

if ( $missing ) {
	throw new RuntimeException( "CLI commands reference abilities no monorepo plugin registers:\n  " . implode( "\n  ", $missing ) );
}

fwrite( STDOUT, 'Ability reference guard passed (' . count( $refs ) . " referenced abilities).\n" );
