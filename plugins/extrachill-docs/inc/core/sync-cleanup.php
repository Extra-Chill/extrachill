<?php
/**
 * One-Shot Sync Subsystem Cleanup
 *
 * The repo→docs sync subsystem (removed in #70) left two kinds of state
 * behind on the docs site:
 *
 *   1. A scheduled `extrachill_docs_sync_cron` event that failed on every
 *      run once data-machine-code was uninstalled from the network.
 *   2. Per-page sync bookkeeping meta (`_source_repo`, `_source_path`,
 *      `_sync_hash`, `_sync_filesize`, `_sync_timestamp`) that — via the
 *      retired synced-page edit guard — kept 13 pages permanently
 *      uneditable.
 *
 * This routine runs once after upgrade and:
 *
 *   - Clears any remaining `extrachill_docs_sync_cron` cron event.
 *   - Deletes the sync bookkeeping meta for all posts, unlocking the
 *     affected pages for normal editing.
 *   - Flushes rewrite rules so exact page rules regenerate without the
 *     retired `_source_repo` meta query.
 *
 * It never touches post content, titles, slugs, status, or hierarchy —
 * only the sync bookkeeping meta.
 *
 * Upgrade pattern: the plugin has no prior upgrade/migration routine
 * (EXTRACHILL_DOCS_VERSION is declared but never gated on), so this uses
 * the minimal viable pattern — an `init`-time check against the
 * `extrachill_docs_sync_cleanup_done` option flag. The plugin is
 * site-activated on docs.extrachill.com only, so the per-site option
 * scopes the cleanup to the docs site.
 *
 * @package ExtraChillDocs
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run the one-shot sync cleanup when it has not run yet.
 *
 * @since 0.7.0
 * @return void
 */
function extrachill_docs_maybe_run_sync_cleanup(): void {
	if ( '1' === (string) get_option( 'extrachill_docs_sync_cleanup_done', '' ) ) {
		return;
	}

	// The sync subsystem is gone; clear any event it left scheduled.
	wp_clear_scheduled_hook( 'extrachill_docs_sync_cron' );

	// Remove sync bookkeeping meta so affected pages become normal,
	// editable pages. Content is not modified.
	foreach ( array( '_source_repo', '_source_path', '_sync_hash', '_sync_filesize', '_sync_timestamp' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	// Exact page rewrite rules no longer derive from _source_repo meta
	// (see inc/core/rewrite-rules.php); regenerate them from the new key.
	flush_rewrite_rules();

	update_option( 'extrachill_docs_sync_cleanup_done', '1', false );
}
add_action( 'init', 'extrachill_docs_maybe_run_sync_cleanup' );
