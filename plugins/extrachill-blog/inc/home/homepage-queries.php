<?php
/**
 * Homepage Queries
 *
 * Pre-fetches homepage content into global variables for template consumption.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $live_reviews_posts, $interviews_posts;

$live_reviews_posts = get_posts(
	array(
		'numberposts'   => 3,
		'category_name' => 'live-music-reviews',
	)
);
$interviews_posts   = get_posts(
	array(
		'numberposts'   => 3,
		'category_name' => 'interviews',
	)
);
