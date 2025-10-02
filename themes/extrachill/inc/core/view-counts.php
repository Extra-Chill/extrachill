<?php
/**
 * Universal View Counting System
 *
 * Tracks page views for all post types across the Extra Chill ecosystem.
 * Uses WordPress post meta for lightweight, cached storage.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Track post views on singular pages
 *
 * Excludes: previews, logged-in editors/admins, and REST API requests
 * Fires on wp_head for all singular post types
 */
function ec_track_post_views($post_id) {
	if (!$post_id || is_preview()) {
		return;
	}

	// Exclude users who can edit posts (editors/admins)
	if (current_user_can('edit_posts')) {
		return;
	}

	// Get and increment view count
	$views = (int) get_post_meta($post_id, 'ec_post_views', true);
	update_post_meta($post_id, 'ec_post_views', $views + 1);
}

add_action('wp_head', function() {
	if (is_singular()) {
		ec_track_post_views(get_the_ID());
	}
});

/**
 * Get view count for any post
 *
 * @param int|null $post_id Post ID (defaults to current post)
 * @return int View count
 */
function ec_get_post_views($post_id = null) {
	$post_id = $post_id ?: get_the_ID();
	return (int) get_post_meta($post_id, 'ec_post_views', true);
}

/**
 * Display formatted view count
 *
 * @param int|null $post_id Post ID (defaults to current post)
 * @param bool $echo Whether to echo or return (default: true)
 * @return string|void Formatted view count
 */
function ec_the_post_views($post_id = null, $echo = true) {
	$views = ec_get_post_views($post_id);
	$output = number_format($views) . ' views';

	if ($echo) {
		echo esc_html($output);
	} else {
		return $output;
	}
}
