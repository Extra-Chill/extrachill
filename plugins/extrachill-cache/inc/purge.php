<?php
/**
 * Cache purge / invalidation.
 *
 * Modeled on Breeze's purge hook set (inc/cache/purge-cache.php:37-53). Breeze,
 * for a file-based page cache, effectively flushed the whole site's page cache
 * on any content change (breeze_cache_flush). We do the same, deliberately: a
 * blog-wide flush on content change is simple, correct, and cheap for this
 * platform's traffic — it avoids the fragile per-URL invalidation matrix
 * (permalinks + REST + author + term archives + feeds) that Breeze's
 * collect_urls_for_cache_purge() tried to enumerate and frequently missed.
 *
 * All purges are multisite-aware: they clear the CURRENT blog's cache
 * partition only (extrachill_cache_blog_dir), never another site's.
 *
 * Deliberately NOT adopted from Breeze's purge path: Cloudflare purge (CF
 * fronts the site and manages its own edge TTL/purge), Varnish purge (DEAD —
 * the old Cloudways Varnish endpoint no longer exists), and object-cache
 * clearing (Redis object cache owns its own invalidation).
 *
 * @package ExtraChillCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Post lifecycle — mirrors Breeze purge-cache.php:37-43.
add_action( 'save_post', 'extrachill_cache_purge_on_post_change', 10, 1 );
add_action( 'pre_post_update', 'extrachill_cache_purge_on_post_change', 10, 1 );
add_action( 'wp_trash_post', 'extrachill_cache_purge_on_post_change', 10, 1 );
add_action( 'delete_post', 'extrachill_cache_purge_on_post_change', 10, 1 );

// Comments — mirrors Breeze purge-cache.php:44-48.
add_action( 'comment_post', 'extrachill_cache_purge_current_blog', 10, 0 );
add_action( 'edit_comment', 'extrachill_cache_purge_current_blog', 10, 0 );
add_action( 'wp_set_comment_status', 'extrachill_cache_purge_current_blog', 10, 0 );
add_action( 'trashed_comment', 'extrachill_cache_purge_current_blog', 10, 0 );
add_action( 'spammed_comment', 'extrachill_cache_purge_current_blog', 10, 0 );

// Terms — mirrors Breeze purge-cache.php:41.
add_action( 'edited_term', 'extrachill_cache_purge_current_blog', 10, 0 );
add_action( 'created_term', 'extrachill_cache_purge_current_blog', 10, 0 );
add_action( 'delete_term', 'extrachill_cache_purge_current_blog', 10, 0 );

// Theme / customizer — mirrors Breeze purge-cache.php:51-52.
add_action( 'switch_theme', 'extrachill_cache_purge_current_blog', 10, 0 );
add_action( 'customize_save_after', 'extrachill_cache_purge_current_blog', 10, 0 );

// Programmatic hook — mirrors Breeze's `purge_post_cache` action so existing
// callers keep working. Also expose a namespaced flush action.
add_action( 'purge_post_cache', 'extrachill_cache_purge_on_post_change', 10, 1 );
add_action( 'extrachill_cache_flush', 'extrachill_cache_purge_current_blog', 10, 0 );

/**
 * Purge on a post lifecycle event, guarding autosaves and revisions.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function extrachill_cache_purge_on_post_change( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$post_type = get_post_type( $post_id );
	if ( 'revision' === $post_type ) {
		return;
	}

	// Match Breeze: skip when the current user can't edit the post, unless
	// running under cron (purge-cache.php:157).
	if ( ! current_user_can( 'edit_post', $post_id ) && ! wp_doing_cron() ) {
		return;
	}

	/**
	 * Filter exact public URLs to invalidate for a post change.
	 *
	 * Return an array to use targeted invalidation. The default null retains the
	 * correctness-first full-blog purge for post types without an integration.
	 *
	 * @param null|array $urls      Absolute URLs, or null for a full purge.
	 * @param int        $post_id   Changed post ID.
	 * @param string     $post_type Changed post type.
	 */
	$urls = apply_filters( 'extrachill_cache_post_change_urls', null, $post_id, $post_type );
	if ( is_array( $urls ) ) {
		$blog_id = get_current_blog_id();
		foreach ( array_unique( array_filter( $urls, 'is_string' ) ) as $url ) {
			extrachill_cache_delete_url( $url, $blog_id );
		}
		return;
	}

	extrachill_cache_purge_current_blog();
}

/**
 * Flush the current blog's page cache partition.
 *
 * @return void
 */
function extrachill_cache_purge_current_blog() {
	$blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
	$dir     = extrachill_cache_blog_dir( $blog_id );
	extrachill_cache_rrmdir( $dir );

	/**
	 * Fires after a blog's page cache has been purged.
	 *
	 * @param int $blog_id Blog whose cache was cleared.
	 */
	do_action( 'extrachill_cache_purged', $blog_id );
}

/**
 * Flush the entire cache tree across all sites.
 *
 * Used on plugin deactivation. Not wired to any content hook.
 *
 * @return void
 */
function extrachill_cache_purge_all() {
	extrachill_cache_rrmdir( extrachill_cache_base_dir() );
}
