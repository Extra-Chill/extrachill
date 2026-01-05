<?php
/**
 * Universal View Counting System
 *
 * Tracks post views using WordPress post meta via async REST API.
 * Excludes previews.
 *
 * @package ExtraChill\Analytics
 * @since 0.1.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Track post views (called by REST API endpoint)
 */
function ec_track_post_views($post_id) {
	if (!$post_id || is_preview()) {
		return;
	}

	$views = (int) get_post_meta($post_id, 'ec_post_views', true);
	update_post_meta($post_id, 'ec_post_views', $views + 1);
}

/**
 * Get view count for any post
 *
 * @param int|null $post_id
 * @return int
 */
function ec_get_post_views($post_id = null) {
	$post_id = $post_id ?: get_the_ID();
	return (int) get_post_meta($post_id, 'ec_post_views', true);
}

/**
 * Display formatted view count
 *
 * @param int|null $post_id
 * @param bool $echo
 * @return string|void
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
