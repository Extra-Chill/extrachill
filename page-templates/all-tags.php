<?php
/**
 * Template Name: All Tags
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */

get_header(); ?>

<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action('colormag_before_body_content'); ?>

                <!-- Breadcrumbs -->
                <nav class="breadcrumbs" itemprop="breadcrumb">
            <a href="<?php echo home_url(); ?>">Home</a> &gt; 
            <a href="<?php echo home_url('/all'); ?>">All</a> &gt; 
            <span>All Tags</span>
        </nav>

<section id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
        <header class="page-header">
            <h1 class="page-title">
                <span><?php _e('All Tags', 'colormag-pro'); ?></span>
            </h1>
        </header><!-- .page-header -->


        <div class="entry-content">
            <?php
            // Display the content from the WordPress page editor
            while (have_posts()) : the_post();
                the_content();
            endwhile;
            ?>
        </div><!-- .entry-content -->

        <div class="tag-sorting">
            <label for="tag-sorting"><?php _e('<b>Sort by:</b>', 'colormag-pro'); ?></label>
            <select id="tag-sorting" name="tag_sorting">
                <option value="count_desc"><?php _e('Most Posts', 'colormag-pro'); ?></option>
                <option value="name_asc"><?php _e('A-Z', 'colormag-pro'); ?></option>
                <option value="name_desc"><?php _e('Z-A', 'colormag-pro'); ?></option>
            </select>
        </div>

        <div class="tag-cloud-container">
            <?php
            // Function to display the tag cloud
            function display_custom_tag_cloud($order = 'count_desc') {
                $args = array(
                    'orderby' => 'count',
                    'order' => 'DESC',
                    'number' => 0, // Get all tags
                    'hide_empty' => true,
                );

                if ($order === 'name_asc') {
                    $args['orderby'] = 'name';
                    $args['order'] = 'ASC';
                } elseif ($order === 'name_desc') {
                    $args['orderby'] = 'name';
                    $args['order'] = 'DESC';
                }

                $tags = get_tags($args);

                // Define min and max font size
                $min_font_size = 10;
                $max_font_size = 36;

                // Calculate the min and max post count
                $min_count = min(array_column($tags, 'count'));
                $max_count = max(array_column($tags, 'count'));

                // Function to scale font size
                function scale_font_size($count, $min_count, $max_count, $min_font_size, $max_font_size) {
                    if ($max_count == $min_count) {
                        return ($max_font_size + $min_font_size) / 2;
                    }
                    return $min_font_size + ($max_font_size - $min_font_size) * ($count - $min_count) / ($max_count - $min_count);
                }

                foreach ($tags as $tag) {
                    if ($tag->count >= 3) { // Only display tags with 3 or more posts
                        $tag_link = get_tag_link($tag->term_id);
                        $tag_name = $tag->name;
                        $tag_count = $tag->count;
                        $font_size = scale_font_size($tag_count, $min_count, $max_count, $min_font_size, $max_font_size);
                        echo '<a href="' . esc_url($tag_link) . '" style="font-size:' . $font_size . 'px;">' . esc_html($tag_name) . ' (' . esc_html($tag_count) . ')</a> ';
                    }
                }
            }

            // Get sorting parameter from URL
            $tag_order = isset($_GET['tag_sorting']) ? sanitize_text_field($_GET['tag_sorting']) : 'count_desc';
            display_custom_tag_cloud($tag_order);
            ?>
        </div>

    </main><!-- #main -->
</section><!-- #primary -->

<?php colormag_sidebar_select(); ?>

<?php do_action('colormag_after_body_content'); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var sortingDropdown = document.getElementById('tag-sorting');
    var urlParams = new URLSearchParams(window.location.search);
    var sort = urlParams.get('tag_sorting'); // Get the 'tag_sorting' parameter from the URL

    // If 'tag_sorting' parameter exists, set the dropdown value to match
    if (sort) {
        sortingDropdown.value = sort;
    }

    // Add change event listener to update the page URL based on selection
    sortingDropdown.addEventListener('change', function() {
        var selectedOption = this.value;
        window.location.href = '?tag_sorting=' + selectedOption;
    });
});
</script>

<?php get_footer(); ?>
