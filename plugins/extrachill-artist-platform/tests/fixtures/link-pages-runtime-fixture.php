<?php
/**
 * Resolve the Link Pages runtime for standalone fixtures.
 *
 * Order: explicit LINK_PAGES_WORKTREE override, then the path Homeboy's
 * standalone smoke runner exports for the resolved validation dependency
 * (EXTRACHILL_LINK_PAGES_PATH), then a sibling plugin directory.
 */
function ec_test_link_pages_runtime_path( $artist_root ) {
	$candidates = array_filter(
		array(
			getenv( 'LINK_PAGES_WORKTREE' ),
			getenv( 'EXTRACHILL_LINK_PAGES_PATH' ),
			dirname( $artist_root ) . '/extrachill-link-pages',
		)
	);
	foreach ( $candidates as $runtime ) {
		if ( is_file( $runtime . '/extrachill-link-pages.php' ) ) {
			return $runtime;
		}
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI fixture diagnostic, never rendered.
	throw new RuntimeException( 'Link Pages runtime unavailable (checked: ' . implode( ', ', $candidates ) . '). Resolve validation_dependencies with Homeboy or set LINK_PAGES_WORKTREE.' );
}

/** Supply the pending migration API when testing against pre-migration main. */
function ec_test_load_link_pages_migration_override( $runtime ) {
	if ( ! is_file( $runtime . '/inc/migration.php' ) ) {
		require_once __DIR__ . '/link-pages-migration-contract-override.php';
	}
}

/** Report whether the selected runtime bootstrap owns a fixture function. */
function ec_test_link_pages_bootstrap_defines( $runtime, $function_name ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local fixture file, not a remote URL.
	$bootstrap = file_get_contents( $runtime . '/tests/bootstrap.php' );
	return false !== $bootstrap && false !== strpos( $bootstrap, 'function ' . $function_name . '(' );
}
