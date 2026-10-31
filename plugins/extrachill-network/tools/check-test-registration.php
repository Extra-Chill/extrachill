<?php
/**
 * Guard: every tests/*.php file must be reachable by a real execution path.
 *
 * #254: a third of tests/*.php were registered nowhere and never ran, while
 * still reading as coverage. This script is the gate that stops that
 * recurring: a new file dropped into tests/ without being wired in fails
 * the check instead of silently doing nothing.
 *
 * A tests/*.php file counts as reachable when it is:
 *
 *   1. Auto-discovered by the managed PHPUnit runner's default convention
 *      (`*Test.php` suffix or `test-*` prefix — see the WordPress
 *      extension's docs/TESTING.md "Requirements" section), OR
 *   2. Declared in homeboy-test-manifest.json under `tests`
 *      (`standalone-php` or `wordpress` environment), OR
 *   3. Required, by literal relative-path reference, from another
 *      tests/*.php file that is itself reachable by (1) or (2) — a child
 *      probe spawned by its caller (e.g. FoundationBootstrapProbe.php is
 *      exec()'d from bootstrap-architecture-smoke.php), not a standalone
 *      top-level entry point.
 *
 * Scope is intentionally the flat tests/*.php directory, not recursive: a
 * subdirectory could hold fixtures invoked manually through separate
 * tooling (e.g. rigs/extrachill-network/journeys/, wp-codebox recipes),
 * never through a `homeboy review test` gate at all.
 *
 * Run: php tools/check-test-registration.php
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "Run this from the CLI: php tools/check-test-registration.php\n" );
	exit( 2 );
}

$root          = dirname( __DIR__ );
$tests_dir     = $root . '/tests';
$manifest_path = $root . '/homeboy-test-manifest.json';

$manifest_json = file_get_contents( $manifest_path );
if ( false === $manifest_json ) {
	fwrite( STDERR, "Could not read {$manifest_path}.\n" );
	exit( 2 );
}

$manifest = json_decode( $manifest_json, true );
if ( ! is_array( $manifest ) || ! isset( $manifest['tests'] ) || ! is_array( $manifest['tests'] ) ) {
	fwrite( STDERR, "Could not parse a `tests` object out of {$manifest_path}.\n" );
	exit( 2 );
}

/** @var string[] $declared Manifest keys, e.g. "tests/foo-smoke.php". */
$declared = array_keys( $manifest['tests'] );

$files = glob( $tests_dir . '/*.php' );
if ( false === $files ) {
	$files = array();
}
sort( $files );

if ( empty( $files ) ) {
	fwrite( STDERR, "No tests/*.php files found under {$tests_dir}.\n" );
	exit( 2 );
}

$basenames = array_map( 'basename', $files );

/**
 * PHPUnit's default discovery convention, matching the WordPress
 * extension's documented `*Test.php` suffix / `test-*` prefix rule.
 */
function ecn_is_phpunit_auto_discovered( string $basename ): bool {
	return (bool) preg_match( '/Test\.php$/', $basename ) || 0 === strpos( $basename, 'test-' );
}

// Build a reference graph: for each file, which OTHER tests/*.php basenames
// does its source literally mention (a relative-path require/exec target)?
// This lets a child probe inherit "reachable" status from whichever
// registered caller pulls it in — the substring check is deliberately loose
// (it will match a basename mentioned in a comment too); a false positive
// here only widens what counts as reachable, never hides a genuine orphan.
$references = array();
foreach ( $files as $file ) {
	$content = (string) file_get_contents( $file );
	$refs    = array();
	foreach ( $basenames as $candidate ) {
		if ( basename( $file ) === $candidate ) {
			continue;
		}
		if ( false !== strpos( $content, $candidate ) ) {
			$refs[] = $candidate;
		}
	}
	$references[ basename( $file ) ] = $refs;
}

/** @var array<string,string> $reachable basename => reason. */
$reachable = array();
foreach ( $basenames as $basename ) {
	if ( ecn_is_phpunit_auto_discovered( $basename ) ) {
		$reachable[ $basename ] = 'PHPUnit auto-discovery (*Test.php / test-*.php)';
		continue;
	}
	if ( in_array( 'tests/' . $basename, $declared, true ) ) {
		$reachable[ $basename ] = 'homeboy-test-manifest.json';
	}
}

// Propagate reachability to files referenced by an already-reachable file.
$changed = true;
while ( $changed ) {
	$changed = false;
	foreach ( $references as $basename => $refs ) {
		if ( ! isset( $reachable[ $basename ] ) ) {
			continue;
		}
		foreach ( $refs as $ref ) {
			if ( ! isset( $reachable[ $ref ] ) ) {
				$reachable[ $ref ] = 'invoked from tests/' . $basename;
				$changed            = true;
			}
		}
	}
}

$orphans = array_values( array_diff( $basenames, array_keys( $reachable ) ) );

if ( ! empty( $orphans ) ) {
	fwrite( STDERR, "The following tests/*.php files are reachable by no known execution path:\n" );
	foreach ( $orphans as $orphan ) {
		fwrite( STDERR, "  - tests/{$orphan}\n" );
	}
	fwrite( STDERR, "\nA test file registered nowhere reads as coverage but runs nowhere (see #254).\n" );
	fwrite( STDERR, "Register it in homeboy-test-manifest.json (standalone-php or wordpress),\n" );
	fwrite( STDERR, "rename it to the PHPUnit *Test.php / test-*.php convention, or delete it.\n" );
	exit( 1 );
}

fwrite( STDOUT, sprintf( "%d tests/*.php files are all reachable by a known execution path.\n", count( $basenames ) ) );
exit( 0 );
