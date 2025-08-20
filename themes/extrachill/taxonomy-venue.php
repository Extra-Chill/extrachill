<?php
/**
 * The template for displaying Venue taxonomy archive pages.
 *
 * @package ExtraChill
 * @since 1.0
 */

get_header(); ?>
<div id="mediavine-settings" data-blocklist-all="1"></div>
<?php do_action('extrachill_before_body_content'); ?>

<section id="primary">
<?php
$term = get_queried_object();
$archive_link = get_term_link($term);
if (have_posts()) : ?>
    <header class="page-header">
        <?php if(function_exists('display_breadcrumbs')) display_breadcrumbs(); ?>
        <h1 class="page-title">
            <a href="<?php echo esc_url($archive_link); ?>">
                <span><?php single_term_title(); ?></span>
            </a>
        </h1>
    </header><!-- .page-header -->
    <?php if (term_description()) : ?>
        <div class="term-description">
            <?php echo term_description(); ?>
        </div>
    <?php endif; ?>
    <div class="article-container">
        <?php while (have_posts()) : the_post(); ?>
            <?php get_template_part('content', 'archive'); ?>
        <?php endwhile; ?>
    </div>
    <?php get_template_part('navigation', 'archive'); ?>
<?php else : ?>
    <?php get_template_part('no-results', 'archive'); ?>
<?php endif; ?>
</section><!-- #primary -->
<?php // get_sidebar(); ?>
<?php do_action('extrachill_after_body_content'); ?>
<?php get_footer(); ?> 