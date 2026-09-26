<?php
/**
 * Team Member New Post Guard
 *
 * Editorial posts only have a lifecycle on the main site: they are
 * authored through Studio Compose, reviewed in the main-site queue,
 * and published to extrachill.com. But the extra_chill_team role
 * grants edit_posts network-wide (inc/team-members/role.php), so
 * core happily shows *New → Post* in the admin bar on every site and
 * the block editor creates a pending `post` wherever the writer
 * happens to be. Those posts strand: no editor looks at that site's
 * pending queue, the Studio review pipeline never sees them, and the
 * writer can't find their own work again.
 *
 * Repro: Extra-Chill/extrachill-users#425. A team member wrote a full
 * photo recap in community's block editor; it landed as a pending post
 * on blog 2 and had to be migrated by hand.
 *
 * The fix steers the entry point, not the capabilities. The standard
 * post caps stay network-wide because edit_posts/upload_files back
 * cap-aware surfaces unrelated to editorial posts (events admin, media
 * uploads). Instead:
 *
 *   1. wp-admin/post-new.php for post type `post` on any non-main site
 *      redirects non-admins to the Studio site (where Compose lives).
 *   2. The admin bar *New → Post* node is removed on non-main sites
 *      for non-admins, and the *New* parent link is repointed to the
 *      first remaining child (or dropped when nothing remains) so it
 *      no longer opens post-new.php.
 *
 * The main site and administrators (manage_options on that site) are
 * completely untouched. Other post types (events, topics, pages, …)
 * are untouched.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current user on the current site is steerable away from
 * the editorial New Post surface.
 *
 * True only when extrachill-network resolved a main site, the current
 * site is NOT that main site, and the current user does not hold
 * manage_options on the current site. Super-admins always hold
 * manage_options via the multisite cap layer and are therefore always
 * exempt.
 *
 * @return bool
 */
function ec_users_is_nonmain_nonadmin() {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( $main_blog_id <= 0 || get_current_blog_id() === $main_blog_id ) {
		return false;
	}

	return ! current_user_can( 'manage_options' );
}

/**
 * Whether the current request is wp-admin New Post for post type `post`.
 *
 * post-new.php without an explicit post_type parameter means `post`
 * (that is how the admin bar *New → Post* link is built). The global
 * $typenow is consulted as a fallback because wp-admin/admin.php sets
 * it from $_REQUEST before the load-post-new.php hook fires.
 *
 * Every other post type is left alone.
 *
 * @return bool
 */
function ec_users_is_post_type_new_post_request() {
	global $typenow;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only New Post entry point state; the user is capability-gated by ec_users_is_nonmain_nonadmin().
	$get_post_type = ( isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) )
		? sanitize_key( wp_unslash( $_GET['post_type'] ) )
		: '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( '' !== $get_post_type ) {
		$post_type = $get_post_type;
	} elseif ( isset( $typenow ) && is_string( $typenow ) && '' !== $typenow ) {
		$post_type = sanitize_key( $typenow );
	} else {
		$post_type = 'post';
	}

	return 'post' === $post_type;
}

/**
 * Make wp_safe_redirect() accept a target host outside the current site.
 *
 * wp_safe_redirect() only allows redirects to the current host, but
 * Studio is a different network subdomain. extrachill-network already
 * allowlists every network domain through the allowed_redirect_hosts
 * filter; this only adds a host when that allowlist is unavailable or
 * does not cover it, so production requests are no-ops here.
 *
 * @param string $url Redirect target URL whose host should be allowed.
 * @return void
 */
function ec_users_allow_redirect_host( $url ) {
	$host = wp_parse_url( (string) $url, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return;
	}

	$host_lower = strtolower( $host );

	$allowed_hosts = function_exists( 'ec_get_allowed_redirect_hosts' )
		? array_map( 'strtolower', (array) ec_get_allowed_redirect_hosts() )
		: array();
	if ( in_array( $host_lower, $allowed_hosts, true ) ) {
		return;
	}

	add_filter(
		'allowed_redirect_hosts',
		static function ( array $hosts ) use ( $host ): array {
			if ( ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
			return $hosts;
		}
	);
}

/**
 * Resolve the Studio site URL that non-main New Post should hand off to.
 *
 * @return string Absolute URL, or '' when the Studio site cannot be resolved.
 */
function ec_users_get_studio_redirect_url() {
	$studio_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'studio' ) : 0;
	if ( $studio_blog_id <= 0 ) {
		return '';
	}

	$studio_url = get_site_url( $studio_blog_id, '/' );
	if ( ! is_string( $studio_url ) || '' === $studio_url ) {
		return '';
	}

	return $studio_url;
}

/**
 * Redirect non-admin New Post requests for `post` on non-main sites to Studio.
 *
 * Hooked on load-post-new.php. Team members (and any other non-admin)
 * landing on a subsite's block editor for posts are sent to the Studio
 * site root, where the Compose entry point lives, instead of silently
 * creating a stranded pending post. Admins and the main site return
 * early and never reach the redirect.
 *
 * @return void
 */
function ec_users_redirect_new_post_to_studio() {
	if ( ! ec_users_is_post_type_new_post_request() || ! ec_users_is_nonmain_nonadmin() ) {
		return;
	}

	$studio_url = ec_users_get_studio_redirect_url();
	if ( '' === $studio_url ) {
		return;
	}

	ec_users_allow_redirect_host( $studio_url );

	wp_safe_redirect( $studio_url, 302 );
	exit;
}
add_action( 'load-post-new.php', 'ec_users_redirect_new_post_to_studio' );

/**
 * Prune the admin bar *New → Post* entry on non-main sites for non-admins.
 *
 * Hooked late on admin_bar_menu so every core and plugin menu builder
 * has finished. Three steps:
 *
 *   1. Remove the `new-post` node (the *New → Post* item).
 *   2. When the `new-content` parent link still points at post-new.php
 *      without a post_type parameter — which is where core anchors it
 *      when `post` is the first creatable type — repoint the parent
 *      href at the first remaining child so the *New* button opens
 *      something that still works (typically *Media* for team members).
 *   3. Remove the `new-content` parent entirely when it has no
 *      children left.
 *
 * Main site and administrators return early and are never touched.
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance, passed by reference.
 * @return void
 */
function ec_users_prune_admin_bar_new_post( $wp_admin_bar ) {
	if ( ! $wp_admin_bar instanceof WP_Admin_Bar || ! ec_users_is_nonmain_nonadmin() ) {
		return;
	}

	$wp_admin_bar->remove_node( 'new-post' );

	$new_content = $wp_admin_bar->get_node( 'new-content' );
	if ( ! $new_content || ! is_string( $new_content->href ) || '' === $new_content->href ) {
		return;
	}

	$path  = (string) wp_parse_url( $new_content->href, PHP_URL_PATH );
	$query = (string) wp_parse_url( $new_content->href, PHP_URL_QUERY );
	if ( ! str_ends_with( $path, 'post-new.php' ) || str_contains( $query, 'post_type=' ) ) {
		return;
	}

	$remaining_children = array();
	foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
		if ( isset( $node->parent ) && 'new-content' === $node->parent && ! empty( $node->href ) ) {
			$remaining_children[] = $node;
		}
	}

	if ( empty( $remaining_children ) ) {
		$wp_admin_bar->remove_node( 'new-content' );
		return;
	}

	$new_content->href = $remaining_children[0]->href;
	$wp_admin_bar->add_node( $new_content );
}
add_action( 'admin_bar_menu', 'ec_users_prune_admin_bar_new_post', 1000 );
