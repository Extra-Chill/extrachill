<?php
/**
 * Complete Single Post Template
 *
 * Handles the complete single post experience: header, content, comments, related posts, footer.
 * Replaces theme root single.php via template override system.
 *
 * @package    ExtraChill
 * @since      1.0
 */

get_header(); ?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<section id="primary" id="main">
    <?php while ( have_posts() ) : the_post(); ?>

        <?php
        // Always show breadcrumbs for posts
        extrachill_breadcrumbs();
        ?>

<div class="single-post-card">
<article id="post-<?php the_ID(); ?>">
    <?php do_action('extrachill_before_post_content'); ?>
    <?php
    $image_popup_id = get_post_thumbnail_id();
    $image_popup_url = wp_get_attachment_url($image_popup_id);
    ?>


    <header class="entry-header" id="postvote">
        <?php do_action( 'extrachill_above_post_title' ); ?>
        <h1 class="entry-title">
            <?php the_title(); ?>
        </h1>
    </header>
    <?php extrachill_entry_meta(); ?>

    <div class="entry-content">
        <?php
        the_content();

        wp_link_pages(array(
            'before'      => '<div style="clear: both;"></div><div class="pagination clearfix">' . __('Pages:', 'extrachill'),
            'after'       => '</div>',
            'link_before' => '<span>',
            'link_after'  => '</span>',
        ));
        ?>
    </div>

    <?php do_action('extrachill_after_post_content'); ?>
</article>
</div>

    <?php endwhile; ?>

<aside>
    <?php
    do_action( 'extrachill_before_comments_template' );
    // If comments are open or we have at least one comment, load up the comment template
    if ( comments_open() || '0' != get_comments_number() ) {
        do_action( 'extrachill_comments_section' );
    }
    do_action( 'extrachill_after_comments_template' );
    // Add related artist and venue sections below comments
    require_once get_template_directory() . '/inc/single/related-posts.php';

    $post_id = get_the_ID();
    extrachill_display_related_posts('artist', $post_id);
    extrachill_display_related_posts('venue', $post_id);
    ?>
</aside>
</section><!-- #primary -->

<?php get_sidebar(); ?>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
