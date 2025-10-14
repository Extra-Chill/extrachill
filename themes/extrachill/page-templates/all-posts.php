<?php
/**
 * Template Name: All Posts
 *
 * @package ExtraChill
 * @since 1.0
 */

get_header(); ?>
<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action('extrachill_before_body_content'); ?>

<section class="main-content archive full-width-content">
    <main class="site-main" role="main">
        <?php extrachill_breadcrumbs(); ?>
        <header class="page-header">
            <h1 class="page-title">
                <span><?php _e('The Latest', 'extrachill'); ?></span>
            </h1>
        </header><!-- .page-header -->

        <div class="taxonomy-description">
            <?php
            // Display the content from the WordPress page editor
            while (have_posts()) : the_post();
                the_content();
            endwhile;
            ?>
        </div>

        <?php
$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
$sort = (isset($_GET['sort']) && in_array($_GET['sort'], array('recent', 'oldest'))) ? $_GET['sort'] : 'recent';

$args = array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'paged'          => $paged,
    'author__not_in' => array(39),
);

if ($sort == 'oldest') {
    $args['orderby'] = 'date';
    $args['order'] = 'ASC';
} else {
    $args['orderby'] = 'date';
    $args['order'] = 'DESC';
}

// Check for randomize parameter
if (isset($_GET['randomize'])) {
    $args['orderby'] = 'rand';
}

// Execute the query
$all_posts_query = new WP_Query($args);

global $wpdb;

// Check if there are posts
if ($all_posts_query->have_posts()) :
    ?>

    <?php do_action('extrachill_archive_above_posts'); ?>

    <div class="full-width-breakout">
    <div class="article-container">
        <?php while ($all_posts_query->have_posts()) : $all_posts_query->the_post(); ?>
            <?php get_template_part('inc/archives/post-card'); ?>
        <?php endwhile; ?>
    </div><!-- .article-container -->
</div><!-- .full-width-breakout -->

<?php extrachill_pagination($all_posts_query, 'all-posts'); ?>

<?php else : ?>

    <p>No posts found for this sorting option.</p>
    <?php extrachill_no_results(); ?>

<?php endif;

wp_reset_postdata();
?>



    </main><!-- .site-main -->
</section><!-- .main-content -->

<?php // get_sidebar(); ?>

<?php do_action('extrachill_after_body_content'); ?>

<?php get_footer(); ?>
