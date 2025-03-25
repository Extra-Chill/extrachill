<?php
/**
 * Template Name: All Posts
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */

get_header(); ?>
<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action('extrachill_before_body_content'); ?>

<section id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
        <nav class="breadcrumbs" itemprop="breadcrumb">
            <a href="<?php echo home_url(); ?>">Home</a> › <span>All</span>
        </nav>
        <header class="page-header">
            <h1 class="page-title">
                <span><?php _e('The Latest', 'colormag-pro'); ?></span>
            </h1>
            <a class="locations-link" href="/locations">Browse Locations</a>
        </header><!-- .page-header -->

        <div class="taxonomy-description">
            <?php
            // Display the content from the WordPress page editor
            while (have_posts()) : the_post();
                the_content();
            endwhile;
            ?>
        </div>

        <div class="category-dropdown">
            <h2 class="filter-head"><?php _e('Select Category:', 'colormag-pro'); ?></h2>
            <select id="category" name="category" onchange="if (this.value) window.location.href=this.value;">
                <option value=""><?php _e('Select Category', 'colormag-pro'); ?></option>
                <?php
                $categories = get_categories(array(
                    'hide_empty' => false,
                ));
                foreach ($categories as $category) {
                    echo '<option value="' . get_category_link($category->term_id) . '">' . $category->name . '</option>';
                }
                ?>
            </select>
        </div>

        <?php
$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
$sort = (isset($_GET['sort']) && in_array($_GET['sort'], array('recent', 'upvotes', 'oldest'))) ? $_GET['sort'] : 'recent';

$args = array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'paged'          => $paged,
);

if ($sort == 'upvotes') {
    // Simplified meta query to avoid conflicting joins
    $args['meta_key'] = 'upvote_count';
    $args['orderby'] = array(
        'meta_value_num' => 'DESC',
        'date' => 'DESC', // Secondary sort by date if upvotes are equal
    );
} elseif ($sort == 'oldest') {
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
    
<div id="extrachill-custom-sorting">
    <button id="randomize-posts">Randomize Posts</button>
    <div id="custom-sorting-dropdown">
        <select id="post-sorting" name="post_sorting" onchange="window.location.href='<?php echo esc_url(get_permalink()); ?>?sort='+this.value;">
            <option value="recent" <?php selected($sort, 'recent'); ?>>Sort by Recent</option>
            <option value="upvotes" <?php selected($sort, 'upvotes'); ?>>Sort by Upvotes</option>
            <option value="oldest" <?php selected($sort, 'oldest'); ?>>Sort by Oldest</option>
        </select>
    </div>
</div>

<div class="article-container">
    <?php while ($all_posts_query->have_posts()) : $all_posts_query->the_post(); ?>
        <?php get_template_part('content', get_post_format()); ?>
    <?php endwhile; ?>
</div>

<div class="pagination">
    <?php
    if (function_exists('wp_pagenavi')) {
        wp_pagenavi(array('query' => $all_posts_query));
    } else {
        previous_posts_link('&laquo; Newer Posts', $all_posts_query->max_num_pages);
        next_posts_link('Older Posts &raquo;', $all_posts_query->max_num_pages);
    }
    ?>
</div>

<?php else : ?>

    <p>No posts found for this sorting option.</p>
    <?php get_template_part('no-results', 'none'); ?>

    <?php
    // DEBUGGING: Log that no posts were found
    error_log('No posts found for upvotes sorting.');
    ?>

<?php endif;

wp_reset_postdata();
?>



    </main><!-- #main -->
</section><!-- #primary -->

<?php get_sidebar(); ?>

<?php do_action('extrachill_after_body_content'); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var sortingDropdown = document.getElementById('post-sorting');
    var urlParams = new URLSearchParams(window.location.search);
    var sort = urlParams.get('sort'); // Get the 'sort' parameter from the URL

    // If 'sort' parameter exists, set the dropdown value to match
    if (sort) {
        sortingDropdown.value = sort;
    }

    // Add change event listener to update the page URL based on selection
    sortingDropdown.addEventListener('change', function() {
        var selectedOption = this.value;
        window.location.href = '?sort=' + selectedOption;
    });

    // Add event listener to the "Randomize Posts" button
    document.getElementById('randomize-posts').addEventListener('click', function() {
        var currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('randomize', 'true');
        window.location.href = currentUrl.href;
    });
});
</script>

<?php get_footer(); ?>
