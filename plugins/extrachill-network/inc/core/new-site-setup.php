<?php
/**
 * New site setup hooks for the Extra Chill network.
 *
 * Runs after WordPress creates a new site on the multisite network.
 * Removes default placeholder content (Hello World post, Sample Page,
 * default comment) that WordPress auto-creates on every new site.
 *
 * @package ExtraChillNetwork
 * @subpackage Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove default WordPress placeholder content from a newly created site.
 *
 * WordPress creates "Hello world!" (post ID 1), "Sample Page" (page ID 2),
 * and "A WordPress Commenter" comment (comment ID 1) on every new site.
 * These are never wanted on the Extra Chill network.
 *
 * Why delete-after-create instead of preventing creation?
 * wp_install_defaults() uses raw $wpdb->insert() with no hooks or filters
 * to selectively skip content. The function IS pluggable (function_exists
 * guard), but overriding it means owning ALL defaults including the
 * Uncategorized category, Privacy Policy page, and default widgets.
 * Delete-after-create at priority 900 is the standard multisite approach.
 *
 * @param WP_Site $new_site The newly created site object.
 */
function ec_remove_default_content_on_new_site( $new_site ) {
	try {
		switch_to_blog( $new_site->blog_id );

		// Delete "Hello world!" post (always ID 1 on fresh sites).
		$hello_world = get_post( 1 );
		if ( $hello_world && 'hello-world' === $hello_world->post_name ) {
			wp_delete_post( 1, true );
		}

		// Delete "Sample Page" (always ID 2 on fresh sites).
		$sample_page = get_post( 2 );
		if ( $sample_page && 'sample-page' === $sample_page->post_name ) {
			wp_delete_post( 2, true );
		}

		// Delete default comment (always ID 1 on fresh sites).
		$default_comment = get_comment( 1 );
		if ( $default_comment ) {
			wp_delete_comment( 1, true );
		}
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp_initialize_site', 'ec_remove_default_content_on_new_site', 900 );
