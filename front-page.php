<?php
get_header();

/**
 * Homepage Content Query & Processing
 *
 * Pre-fetches all homepage content in optimized queries to avoid multiple
 * database calls within template sections. Newsletter posts require ExtraChill Newsletter Plugin.
 */
$live_reviews_posts = get_posts(array('numberposts' => 3, 'category_name' => 'live-music-reviews'));
$interviews_posts = get_posts(array('numberposts' => 3, 'category_name' => 'interviews'));
$newsletter_posts = get_posts(array('numberposts' => 3, 'post_type' => 'newsletter'));

// Build exclude list from posts already displayed above
$exclude_ids = array();
foreach ($live_reviews_posts as $post) {
    $exclude_ids[] = $post->ID;
}
foreach ($interviews_posts as $post) {
    $exclude_ids[] = $post->ID;
}
foreach ($newsletter_posts as $post) {
    $exclude_ids[] = $post->ID;
}

$more_recent_posts = get_posts(array(
    'numberposts' => 4, 
    'post__not_in' => $exclude_ids,
    'author__not_in' => array(39),
    'orderby' => 'date',
    'order' => 'DESC'
));

// Festival Wire ticker handled by ExtraChill News Wire plugin via extrachill_after_hero hook

?>
<div id="mediavine-settings" data-blocklist-all="1"></div>
<?php include get_template_directory() . '/inc/home/hero.php'; ?>
<?php do_action( 'extrachill_after_hero' ); ?>
<?php 
// Pass the pre-fetched data to the section templates.
include get_template_directory() . '/inc/home/section-3x3-grid.php'; 
?>
<?php include get_template_directory() . '/inc/home/section-more-recent-posts.php'; ?>
<?php include get_template_directory() . '/inc/home/section-extrachill-link.php'; ?>
<?php do_action('extrachill_homepage_newsletter_section'); ?>

<?php
get_footer();
?>
