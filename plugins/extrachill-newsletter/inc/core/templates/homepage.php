<?php
/**
 * Newsletter Archive Template
 *
 * Used for newsletter.extrachill.com homepage (homepage-as-archive pattern).
 * Displays all published newsletters with subscription form integration.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

get_header(); ?>

<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action('extrachill_before_body_content'); ?>

<?php if (have_posts()) : ?>
    <?php extrachill_breadcrumbs(); ?>

    <?php do_action('extrachill_archive_header'); ?>

    <?php do_action('newsletter_homepage_hero'); ?>

    <?php do_action('extrachill_archive_above_posts'); ?>
    <div class="full-width-breakout">
        <div class="article-container">
            <?php global $post_i; $post_i = 1; ?>
            <?php while (have_posts()) : the_post(); ?>
                <?php get_template_part('inc/archives/post-card'); ?>
            <?php endwhile; ?>
        </div><!-- .article-container -->

        <?php extrachill_pagination(null, 'archive'); ?>
    </div><!-- .full-width-breakout -->

<?php else : ?>
    <?php extrachill_no_results(); ?>
<?php endif; ?>

<?php do_action('extrachill_after_body_content'); ?>

<?php get_footer(); ?>
