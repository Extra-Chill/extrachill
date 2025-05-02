<?php
get_header();

$current_user = wp_get_current_user();
$is_logged_in = is_user_logged_in();
$page_title = ( get_query_var('paged', 1 ) > 1 ) 
    ? sprintf( __( 'The Latest – Page %d', 'colormag' ), get_query_var('paged') ) 
    : __( 'The Latest', 'colormag' );

// Collect IDs for exclusion in more recent posts
$homepage_exclude_ids = [];
// Live Reviews
$live_reviews = new WP_Query([
  'cat' => 2608, // Use actual Live Reviews category ID
  'posts_per_page' => 3,
  'post_status' => 'publish',
]);
if ($live_reviews->have_posts()) {
  while ($live_reviews->have_posts()) {
    $live_reviews->the_post();
    $homepage_exclude_ids[] = get_the_ID();
  }
  wp_reset_postdata();
}
// Interviews
$interviews = new WP_Query([
  'cat' => 723, // Use actual Interviews category ID
  'posts_per_page' => 3,
  'post_status' => 'publish',
]);
if ($interviews->have_posts()) {
  while ($interviews->have_posts()) {
    $interviews->the_post();
    $homepage_exclude_ids[] = get_the_ID();
  }
  wp_reset_postdata();
}
?>
<div id="mediavine-settings" data-blocklist-all="1"></div>
<?php include get_template_directory() . '/home/hero.php'; ?>
<?php include get_template_directory() . '/home/festival-wire-ticker.php'; ?>
<?php include get_template_directory() . '/home/section-3x3-grid.php'; ?>
<?php include get_template_directory() . '/home/section-more-recent-posts.php'; ?>
<?php include get_template_directory() . '/home/section-newsletter-and-about.php'; ?>

<?php
// get_sidebar();
get_footer();
?>
