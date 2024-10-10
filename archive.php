<?php
/**
 * The template for displaying Archive page.
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */

get_header(); ?>
<div id="mediavine-settings" data-blocklist-all="1"></div>
<?php do_action('colormag_before_body_content'); ?>

<section id="primary">
<?php

// Determine the correct archive link based on the type of archive
$archive_link = '';
if (is_category()) {
    $archive_link = get_category_link(get_queried_object_id());
} elseif (is_tag()) {
    $archive_link = get_tag_link(get_queried_object_id());
} elseif (is_author()) {
    $archive_link = get_author_posts_url(get_queried_object_id());
} elseif (is_day()) {
    $archive_link = get_day_link(get_query_var('year'), get_query_var('monthnum'), get_query_var('day'));
} elseif (is_month()) {
    $archive_link = get_month_link(get_query_var('year'), get_query_var('monthnum'));
} elseif (is_year()) {
    $archive_link = get_year_link(get_query_var('year'));
} else {
    $archive_link = get_post_type_archive_link(get_post_type());
}
?>
<?php if (have_posts()) : ?>

    <header class="page-header">
        <?php display_breadcrumbs(); ?>
        <h1 class="page-title">
            <a href="<?php echo esc_url($archive_link); ?>">
                <span>
                    <?php
                    if (is_category()) {
                        single_cat_title();
                    } elseif (is_tag()) {
                        single_tag_title();
                    } elseif (is_author()) {
                        the_post();
                        printf(__('Author: %s', 'colormag-pro'), '<class="vcard">' . get_the_author() . '</>');
                        rewind_posts();
                    } elseif (is_day()) {
                        printf(__('Day: %s', 'colormag-pro'), get_the_date());
                    } elseif (is_month()) {
                        printf(__('Month: %s', 'colormag-pro'), get_the_date('F Y'));
                    } elseif (is_year()) {
                        printf(__('Year: %s', 'colormag-pro'), get_the_date('Y'));
                    } else {
                        _e('Archives', 'colormag-pro');
                    }
                    ?>
                </span>
            </a>
        </h1>
    </header><!-- .page-header -->

    <?php
    // Optional Term and Author Description
    if (!is_paged() && empty($_GET['tag'])) {
        $term_description = term_description();
        if (!empty($term_description)) {
            printf('<div class="taxonomy-description">%s</div>', $term_description);
        }

        if (is_author()) {
            $author_bio = get_the_author_meta('description');
            if (!empty($author_bio)) {
                echo '<div class="author-bio">' . wpautop($author_bio) . '</div>';
            }
        }
    }

    // Fetch and display parent category link and subcategories
    if (is_category()) {
        $current_category = get_category(get_queried_object_id());

        $subcategories = get_categories(array(
            'child_of' => $current_category->term_id,
            'hide_empty' => false,
        ));

        if ($subcategories) {
            echo '<div class="subcategory-dropdown">';
            echo '<h2 class="filter-head">' . __('Select Subcategory:', 'colormag-pro') . '</h2>';
            echo '<select id="subcategory" name="subcategory" onchange="if (this.value) window.location.href=this.value;">';
            echo '<option value="">' . __('Select Subcategory', 'colormag-pro') . '</option>';
            foreach ($subcategories as $subcategory) {
                echo '<option value="' . get_category_link($subcategory->term_id) . '">' . $subcategory->name . '</option>';
            }
            echo '</select>';
            echo '</div>';
        }
    }
    ?>

    <div id="extrachill-custom-sorting">
        <?php
        if (is_category('song-meanings')) {
            wp_innovator_dropdown_menu('song-meanings', 'Filter By Artist');
        } elseif (is_category('music-history')) {
            wp_innovator_dropdown_menu('music-history', 'Filter By Tag');
        }
        ?>
        <?php if (is_archive()): ?>
            <button id="randomize-posts">Randomize Posts</button>
        <?php endif; ?>
        <div id="custom-sorting-dropdown">
            <select id="post-sorting" name="post_sorting" onchange="window.location.href='<?php echo esc_url($archive_link); ?>?sort='+this.value;">
                <option value="recent">Sort by Recent</option>
                <option value="upvotes">Sort by Upvotes</option>
                <option value="oldest">Sort by Oldest</option>
            </select>
        </div>
    </div>
    <div class="article-container">
        <?php global $post_i; $post_i = 1; ?>
        <?php while (have_posts()) : the_post(); ?>
            <?php get_template_part('content', 'archive'); ?>
        <?php endwhile; ?>
    </div>

    <?php get_template_part('navigation', 'archive'); ?>

<?php else : ?>
    <?php get_template_part('no-results', 'archive'); ?>
<?php endif; ?>
</section><!-- #primary -->

<?php colormag_sidebar_select(); ?>

<?php do_action('colormag_after_body_content'); ?>

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
});
</script>

<?php get_footer(); ?>
