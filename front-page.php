<?php
/**
 * Template to show the front page.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */

get_header(); ?>
<div id="mediavine-settings" data-blocklist-all="1"></div>

<div class="front-page-top-section clearfix">
    <!-- Location Filter Button and Popup -->
    <div class="filter-by-location-container">
        <button id="filter-location-btn" class="filter-btn">Filter by Location</button>
        <div id="location-filters" class="location-filters">
            <div class="location-filters-content">
                <span id="close-filter">&times;</span>
                <h3>Select Locations</h3>
                <p>Showing locations with 5 or more posts. <a href="/locations">View all.</a></p>
                <button class="save-filters-btn">Save Filters</button>
                <div class="location-grid">
                    <?php
                    // Start the display from top-level locations
                    display_location_hierarchy_homepage();
                    ?>
                </div>
                <button class="save-filters-btn">Save Filters</button>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Section -->
<section id="primary" class="content-area">
    <span class="home-header">
<h2>Latest Posts</h2>
    <a href="/all" class="view-all-button">View All</a></span>
    <main id="main" class="site-main archive" role="main">
        <?php if (have_posts()) : ?>
            <?php while (have_posts()) : the_post(); ?>
                <!-- Display each post in the loop using the content template -->
                <?php get_template_part('content', ''); ?>
            <?php endwhile; ?>
    </main>
                <!-- Pagination -->
                <?php get_template_part('navigation', 'none'); ?>
        <?php else : ?>
            <!-- No posts found -->
            <?php get_template_part('no-results', 'none'); ?>
        <?php endif; ?>
</section>

<!-- Sidebar -->
<?php get_sidebar(); ?>

<?php get_footer(); ?>
