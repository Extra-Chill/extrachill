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
 * Redirect a missing legacy topic to its canonical main-site post.
 */
function extrachill_community_maybe_redirect_legacy_topic(): void {
	$redirect_url = extrachill_community_get_legacy_topic_redirect_url();
	if ( '' === $redirect_url ) {
		return;
	}

	wp_safe_redirect( $redirect_url, 301, 'Extra Chill Community' );
	exit;
}
add_action( 'template_redirect', 'extrachill_community_maybe_redirect_legacy_topic', 9 );
