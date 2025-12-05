<?php
/**
 * Newsletter Homepage Content
 *
 * Homepage content for newsletter.extrachill.com (homepage-as-archive pattern).
 * Hooked via extrachill_homepage_content action.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

do_action('extrachill_before_body_content');

if (have_posts()) :
    extrachill_breadcrumbs();

    do_action('extrachill_archive_header');

    do_action('newsletter_homepage_hero');

    do_action('extrachill_archive_above_posts');
    ?>
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

<?php do_action('extrachill_after_body_content');
