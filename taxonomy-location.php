<?php
/**
 * The template for displaying Location taxonomy archive pages.
 *
 * @package ThemeGrill
 * @subpackage ColorMag
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
        <?php display_location_breadcrumbs(); ?>
        <h1 class="page-title">
            <a href="<?php echo esc_url($archive_link); ?>">
                <span><?php single_term_title(); ?></span>
            </a>
        </h1>
    </header><!-- .page-header -->

    <!-- Display the term description if it exists -->
    <?php if (term_description()) : ?>
        <div class="term-description">
            <?php echo term_description(); ?>
        </div>
    <?php endif; ?>

    <!-- Display sub-locations in a dropdown if they exist -->
    <?php
    $child_locations = get_terms(array(
        'taxonomy' => 'location',
        'hide_empty' => false,
        'parent' => $term->term_id,
    ));

    if (!empty($child_locations)) : ?>
        <div class="sub-location-dropdown">
            <h2>Select a Sub-Location:</h2>
            <select id="sub-location-select" onchange="if (this.value) window.location.href=this.value;">
                <option value=""><?php _e('Choose a Sub-Location', 'extrachill'); ?></option>
                <?php foreach ($child_locations as $child_location) : ?>
                    <option value="<?php echo esc_url(get_term_link($child_location)); ?>">
                        <?php echo esc_html($child_location->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
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

<?php get_sidebar(); ?>

<?php do_action('extrachill_after_body_content'); ?>

<?php get_footer(); ?>
