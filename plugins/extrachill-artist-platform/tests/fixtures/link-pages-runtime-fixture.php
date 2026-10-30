<?php
/** Resolve the current sibling runtime, with an explicit CI override. */
function ec_test_link_pages_runtime_path( $artist_root ) {
	$override = getenv( 'LINK_PAGES_WORKTREE' );
	$runtime  = $override ? $override : dirname( $artist_root ) . '/extrachill-link-pages';
	if ( ! is_file( $runtime . '/extrachill-link-pages.php' ) ) {
		throw new RuntimeException( 'Set LINK_PAGES_WORKTREE to a current Extra Chill Link Pages checkout.' );
	}
	return $runtime;
}

/** Supply the pending migration API when testing against pre-migration main. */
function ec_test_load_link_pages_migration_override( $runtime ) {
	if ( ! is_file( $runtime . '/inc/migration.php' ) ) {
		require_once __DIR__ . '/link-pages-migration-contract-override.php';
	}
}

/** Report whether the selected runtime bootstrap owns a fixture function. */
function ec_test_link_pages_bootstrap_defines( $runtime, $function ) {
	$bootstrap = file_get_contents( $runtime . '/tests/bootstrap.php' );
	return false !== $bootstrap && false !== strpos( $bootstrap, 'function ' . $function . '(' );
}
