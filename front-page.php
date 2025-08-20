<?php
get_header();

// =================================================================================
// Homepage Content Query & Processing
// =================================================================================

// Direct queries for homepage content
$live_reviews_posts = get_posts(array('numberposts' => 3, 'category_name' => 'live-music-reviews'));
$interviews_posts = get_posts(array('numberposts' => 3, 'category_name' => 'interviews'));
$newsletter_posts = get_posts(array('numberposts' => 3, 'post_type' => 'newsletter'));
$more_recent_posts = get_posts(array('numberposts' => 4, 'category_name' => 'music-history/song-meanings'));
$festival_wire_posts = get_posts(array('numberposts' => 5, 'post_type' => 'festival_wire'));
$festival_wire_ticker_items = array();
foreach ($festival_wire_posts as $post) {
    $festival_wire_ticker_items[] = array(
        'permalink' => get_permalink($post->ID),
        'title' => $post->post_title,
        'title_attr' => esc_attr($post->post_title)
    );
}
$exclude_ids = array();

?>
<div id="mediavine-settings" data-blocklist-all="1"></div>
<?php include get_template_directory() . '/inc/home/hero.php'; ?>
<?php include get_template_directory() . '/inc/home/festival-wire-ticker.php'; ?>
<?php 
// Pass the pre-fetched data to the section templates.
include get_template_directory() . '/inc/home/section-3x3-grid.php'; 
?>
<?php include get_template_directory() . '/inc/home/section-more-recent-posts.php'; ?>
<?php include get_template_directory() . '/inc/home/section-extrachill-link.php'; ?>
<?php include get_template_directory() . '/inc/home/section-newsletter-and-about.php'; ?>

<?php
// get_sidebar();
get_footer();
?>
