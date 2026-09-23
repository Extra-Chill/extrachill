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

// Post lifecycle. Only changes that can affect public output need invalidation.
add_action( 'transition_post_status', 'extrachill_cache_purge_on_post_transition', 10, 3 );
add_action( 'before_delete_post', 'extrachill_cache_purge_on_post_delete', 10, 2 );

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

// Code changes. A plugin or theme update can change enqueued scripts, their
// localized config, inline markup and template output. Cached HTML would keep
// serving the old versions for up to EXTRACHILL_CACHE_TTL (24h). Network-wide,
// because a network-activated plugin changes every site's pages.
// Note: homeboy deploy replaces the plugin directory directly and never fires
// these, so the deploy pipeline also calls `wp extrachill-cache purge --all`
// (see the wordpress extension's post:deploy hooks).
add_action( 'upgrader_process_complete', 'extrachill_cache_purge_all', 10, 0 );
add_action( 'activated_plugin', 'extrachill_cache_purge_all', 10, 0 );
add_action( 'deactivated_plugin', 'extrachill_cache_purge_all', 10, 0 );
add_action( 'customize_save_after', 'extrachill_cache_purge_current_blog', 10, 0 );

// Programmatic hook — mirrors Breeze's `purge_post_cache` action so existing
// callers keep working. The namespaced post action is for trusted, in-process
// callers that have already authorized and committed their domain mutation.
add_action( 'purge_post_cache', 'extrachill_cache_purge_on_post_change', 10, 1 );
add_action( 'extrachill_cache_purge_post', 'extrachill_cache_purge_post', 10, 1 );
add_action( 'extrachill_cache_flush', 'extrachill_cache_purge_current_blog', 10, 0 );

/**
 * Purge when a post enters, leaves, or remains in a public status.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       Post object.
 * @return void
 */
function extrachill_cache_purge_on_post_transition( $new_status, $old_status, $post ) {
	if ( ! extrachill_cache_is_public_post_status( $new_status ) && ! extrachill_cache_is_public_post_status( $old_status ) ) {
		return;
	}

	extrachill_cache_purge_on_post_change( $post->ID );
}

/**
 * Purge when permanently deleting a post that is still public.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    Post object.
 * @return void
 */
function extrachill_cache_purge_on_post_delete( $post_id, $post = null ) {
	if ( null === $post ) {
		$post = get_post( $post_id );
	}

	if ( ! $post || ! extrachill_cache_is_public_post_status( $post->post_status ) ) {
		return;
	}

	extrachill_cache_purge_on_post_change( $post_id );
}

/**
 * Determine whether a registered post status exposes content publicly.
 *
 * @param string $status Post status name.
 * @return bool
 */
function extrachill_cache_is_public_post_status( $status ) {
	$status_object = get_post_status_object( $status );

	return $status_object && $status_object->public;
}

/**
 * Purge on a post lifecycle event, guarding autosaves and revisions.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function extrachill_cache_purge_on_post_change( $post_id ) {
	extrachill_cache_purge_post( $post_id );
}

/**
 * Purge public cache affected by an already-authorized post mutation.
 *
 * This function and its same-named action are an internal PHP contract only.
 * Repeated calls are harmless: URL and directory deletion are idempotent.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function extrachill_cache_purge_post( $post_id ) {
	if ( ! is_int( $post_id ) && ( ! is_string( $post_id ) || ! ctype_digit( $post_id ) ) ) {
		return;
	}

	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	$post_type = get_post_type( $post );

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
 * Wired to code-change events (plugin/theme upgrade, activation,
 * deactivation) and exposed as `wp extrachill-cache purge --all` for deploy
 * pipelines that replace files without firing WordPress upgrader hooks.
 *
 * @return void
 */
function extrachill_cache_purge_all() {
	extrachill_cache_rrmdir( extrachill_cache_base_dir() );

	/**
	 * Fires after the whole page cache tree has been purged.
	 */
	do_action( 'extrachill_cache_purged_all' );
}
