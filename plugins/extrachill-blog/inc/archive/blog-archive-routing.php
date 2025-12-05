<?php
/**
 * Blog Archive Routing
 *
 * Provides /all URL for main blog archive on extrachill.com (Blog ID 1).
 * Excludes AI-generated content (author ID 39) from main listing.
 * Sorting handled by theme's archive-custom-sorting.php via $_GET['sort'] parameter.
 *
 * @package ExtraChillBlog
 * @since 0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register /all rewrite rules
 */
function extrachill_blog_register_archive_rewrite() {
	add_rewrite_rule( '^all/?$', 'index.php?extrachill_blog_archive=1', 'top' );
	add_rewrite_rule( '^all/page/([0-9]+)/?$', 'index.php?extrachill_blog_archive=1&paged=$matches[1]', 'top' );
}
add_action( 'init', 'extrachill_blog_register_archive_rewrite' );

/**
 * Register query variable
 *
 * @param array $vars Existing query variables.
 * @return array Modified query variables.
 */
function extrachill_blog_register_query_vars( $vars ) {
	$vars[] = 'extrachill_blog_archive';
	return $vars;
}
add_filter( 'query_vars', 'extrachill_blog_register_query_vars' );

/**
 * Modify main query for blog archive
 *
 * Sets post type, excludes AI author (ID 39), and configures query flags.
 *
 * @param WP_Query $query The main query object.
 */
function extrachill_blog_archive_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( ! get_query_var( 'extrachill_blog_archive' ) ) {
		return;
	}

	$query->set( 'post_type', 'post' );
	$query->set( 'post_status', 'publish' );
	$query->set( 'author__not_in', array( 39 ) );
	$query->is_home    = false;
	$query->is_archive = true;
}
add_action( 'pre_get_posts', 'extrachill_blog_archive_query' );

/**
 * Route blog archive to theme's archive template
 *
 * @param string $template The template path.
 * @return string Modified template path.
 */
function extrachill_blog_archive_template( $template ) {
	if ( get_query_var( 'extrachill_blog_archive' ) ) {
		$archive_template = get_template_directory() . '/inc/archives/archive.php';
		if ( file_exists( $archive_template ) ) {
			return $archive_template;
		}
	}
	return $template;
}
add_filter( 'template_include', 'extrachill_blog_archive_template' );
