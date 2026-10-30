<?php
/**
 * Redirect retired article-mirror topics to their canonical blog posts.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extract a legacy topic slug from the current request.
 *
 * @return string Topic slug, or an empty string when the request is ineligible.
 */
function extrachill_community_get_legacy_topic_slug(): string {
	if ( ! extrachill_community_is_community_site() || is_admin() || wp_doing_ajax() || ! is_404() ) {
		return '';
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		return '';
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( ! is_string( $path ) || ! preg_match( '#^/t/([^/]+)/?$#', $path, $matches ) ) {
		return '';
	}

	return sanitize_title( rawurldecode( $matches[1] ) );
}

/**
 * Find the canonical main-site post URL for a retired topic request.
 *
 * Current slugs are preferred. WordPress's `_wp_old_slug` records cover posts
 * renamed after their Community mirrors were created.
 *
 * @return string Canonical blog URL, or an empty string when none exists.
 */
function extrachill_community_get_legacy_topic_redirect_url(): string {
	$slug = extrachill_community_get_legacy_topic_slug();
	if ( '' === $slug ) {
		return '';
	}

	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 1;
	if ( $main_blog_id <= 0 || (int) get_current_blog_id() === $main_blog_id ) {
		return '';
	}

	switch_to_blog( $main_blog_id );
	try {
		$post = get_page_by_path( $slug, OBJECT, 'post' );

		if ( ! $post || 'publish' !== get_post_status( $post ) ) {
			$old_slug_posts = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_wp_old_slug',
					'meta_value'     => $slug,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			$post           = ! empty( $old_slug_posts ) ? (int) $old_slug_posts[0] : null;
		}

		return $post ? (string) get_permalink( $post ) : '';
	} finally {
		restore_current_blog();
	}
}

/**
 * Extract a legacy sync-era shortlink post ID from the current request.
 *
 * Before the multisite consolidation, Community ran as a standalone site and
 * blog posts were synced into the forum, so `?p=<main-site-id>` shortlinks
 * minted in that era are still live in the wild. They only apply to the site
 * root: anything else is a genuine Community 404.
 *
 * @return int Main-site post ID, or 0 when the request is ineligible.
 */
function extrachill_community_get_legacy_shortlink_id(): int {
	if ( ! extrachill_community_is_community_site() || is_admin() || wp_doing_ajax() || ! is_404() ) {
		return 0;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		return 0;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( '/' !== $path ) {
		return 0;
	}

	$post_id = get_query_var( 'p' );
	if ( ! is_scalar( $post_id ) || ! ctype_digit( (string) $post_id ) || (int) $post_id <= 0 ) {
		return 0;
	}

	return (int) $post_id;
}

/**
 * Find the canonical main-site URL for a legacy sync-era shortlink request.
 *
 * Only published posts and attachments with a published parent resolve;
 * anything else falls through so the standing 404 is preserved.
 *
 * @return string Canonical blog URL, or an empty string when none exists.
 */
function extrachill_community_get_legacy_shortlink_redirect_url(): string {
	$post_id = extrachill_community_get_legacy_shortlink_id();
	if ( $post_id <= 0 ) {
		return '';
	}

	// A future local ID collision on this blog must never be redirected away.
	// get_post_status() (not get_post()) so static analysis does not narrow the
	// identical get_post() call made after switch_to_blog() below.
	if ( false !== get_post_status( $post_id ) ) {
		return '';
	}

	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 1;
	if ( $main_blog_id <= 0 || (int) get_current_blog_id() === $main_blog_id ) {
		return '';
	}

	switch_to_blog( $main_blog_id );
	try {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		if ( 'attachment' === $post->post_type ) {
			$parent = $post->post_parent ? get_post( (int) $post->post_parent ) : null;
			if ( ! $parent || 'publish' !== get_post_status( $parent ) ) {
				return '';
			}

			return (string) get_permalink( $parent );
		}

		if ( 'post' === $post->post_type && 'publish' === get_post_status( $post ) ) {
			return (string) get_permalink( $post );
		}

		return '';
	} finally {
		restore_current_blog();
	}
}

/**
 * Allow safe redirects from Community to the canonical main-site host.
 *
 * @param string[] $hosts Hosts already allowed by WordPress.
 * @param string   $host  Host WordPress is validating.
 * @return string[]
 */
function extrachill_community_allow_main_site_redirect_host( array $hosts, string $host ): array {
	$main_site_url  = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : network_home_url( '/' );
	$main_site_host = wp_parse_url( $main_site_url, PHP_URL_HOST );

	if ( is_string( $main_site_host ) && $main_site_host === $host ) {
		$hosts[] = $host;
	}

	return array_unique( $hosts );
}
add_filter( 'allowed_redirect_hosts', 'extrachill_community_allow_main_site_redirect_host', 10, 2 );

/**
 * Redirect a missing legacy topic or sync-era shortlink to its canonical main-site post.
 */
function extrachill_community_maybe_redirect_legacy_topic(): void {
	$redirect_url = extrachill_community_get_legacy_topic_redirect_url();
	if ( '' === $redirect_url ) {
		$redirect_url = extrachill_community_get_legacy_shortlink_redirect_url();
	}

	if ( '' === $redirect_url ) {
		return;
	}

	wp_safe_redirect( $redirect_url, 301, 'Extra Chill Community' );
	exit;
}
add_action( 'template_redirect', 'extrachill_community_maybe_redirect_legacy_topic', 9 );
