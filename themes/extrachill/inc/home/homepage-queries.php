<?php
/**
 * Homepage Content Queries
 *
 * Pre-fetches homepage content in optimized queries to avoid multiple
 * database calls. Makes variables global for template consumption.
 *
 * @package ExtraChill
 * @since 69.57
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
global $live_reviews_posts, $interviews_posts, $more_recent_posts;

$live_reviews_posts = get_posts(array('numberposts' => 3, 'category_name' => 'live-music-reviews'));
$interviews_posts = get_posts(array('numberposts' => 3, 'category_name' => 'interviews'));

$exclude_ids = array();
foreach ($live_reviews_posts as $post) {
    $exclude_ids[] = $post->ID;
}
foreach ($interviews_posts as $post) {
    $exclude_ids[] = $post->ID;
}

$more_recent_posts = get_posts(array(
    'numberposts' => 4,
    'post_type' => 'post',
    'post__not_in' => $exclude_ids,
    'author__not_in' => array(39),
    'orderby' => 'date',
    'order' => 'DESC'
));