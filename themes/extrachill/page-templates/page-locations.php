<?php
/**
 * Template Name: Locations Archive
 * Description: A custom template to display all locations in a hierarchical list.
 */

get_header(); ?>
<div id="mediavine-settings" data-blocklist-all="1"></div>
<?php do_action('colormag_before_body_content'); ?>

<section id="primary">
    <!-- Breadcrumbs for Home > Locations -->
    <nav class="breadcrumbs" itemprop="breadcrumb">
        <a href="<?php echo home_url(); ?>">Home</a> › <span>Locations</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title"><span>Locations</span></h1>
        <a class="all-link" href="/all">All Posts</a>
    </header><!-- .page-header -->

    <p class="location-description">
        Browse the list of locations below to find content specific to your area of interest. The locations are organized in a hierarchical structure, so you can easily explore broader regions or drill down into more specific sub-locations.
    </p>

    <?php
    // Calculate the min and max post counts across all locations
    if (function_exists('find_min_max_post_counts')) {
        $min_max_counts = find_min_max_post_counts();
        $min = $min_max_counts['min'];
        $max = $min_max_counts['max'];
    } else {
        $min = 0;
        $max = 1; // Default values if function is not available
    }

    // Display the location hierarchy for the archive page with scaled font sizes
    if (function_exists('display_location_hierarchy_archive')) {
        display_location_hierarchy_archive(0, $min, $max); // Pass the min and max values to the function
    } else {
        echo '<p>Location hierarchy could not be displayed. Please check the function definition.</p>';
    }
    ?>
</section><!-- #primary -->

<?php colormag_sidebar_select(); ?>

<?php do_action('colormag_after_body_content'); ?>

<?php get_footer(); ?>
