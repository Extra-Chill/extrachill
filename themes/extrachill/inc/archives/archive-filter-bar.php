<?php
/**
 * Archive Filter Bar
 *
 * Technical Implementation:
 * - Dynamic URL construction: Auto-detects archive type and builds appropriate base URL
 * - JavaScript sorting: Client-side URL parameter management for sort state persistence
 *
 * @package ExtraChill
 * @since 69.58
 */

add_action('extrachill_archive_above_posts', 'extrachill_archive_filter_bar', 10);

function extrachill_archive_filter_bar() {
    if (!is_archive() && !is_page_template('page-templates/all-posts.php')) {
        return;
    }

    $archive_link = '';

    if (is_page_template('page-templates/all-posts.php')) {
        $archive_link = get_permalink();
    } elseif (is_category()) {
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

    echo '<div id="extrachill-custom-sorting">';

    echo '<button id="randomize-posts" class="button-2 button-medium">Randomize Posts</button>';

    if (is_page_template('page-templates/all-posts.php')) {
        echo '<div class="category-dropdown">';
        echo '<select id="category" name="category" onchange="if (this.value) window.location.href=this.value;">';
        echo '<option value="">' . esc_html__('Select Category', 'extrachill') . '</option>';
        $categories = get_categories(array('hide_empty' => false));
        foreach ($categories as $category) {
            echo '<option value="' . esc_url(get_category_link($category->term_id)) . '">' . esc_html($category->name) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    if (function_exists('extrachill_child_terms_dropdown_html')) {
        echo extrachill_child_terms_dropdown_html();
    }

    if (is_category('song-meanings')) {
        extrachill_artist_filter_dropdown();
    } elseif (is_category('music-history')) {
        extrachill_artist_filter_dropdown();
    }
    echo '<div id="custom-sorting-dropdown">';
    echo '<select id="post-sorting" name="post_sorting" onchange="window.location.href=\'' . esc_url($archive_link) . '?sort=\'+this.value;">';
    echo '<option value="recent">Sort by Recent</option>';
    echo '<option value="oldest">Sort by Oldest</option>';
    echo '</select>';
    echo '</div>';

    echo '</div>';

    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var sortingDropdown = document.getElementById('post-sorting');
        var urlParams = new URLSearchParams(window.location.search);
        var sort = urlParams.get('sort');

        if (sort) {
            sortingDropdown.value = sort;
        }

        sortingDropdown.addEventListener('change', function() {
            var selectedOption = this.value;
            window.location.href = '?sort=' + selectedOption;
        });
    });
    </script>
    <?php
}
