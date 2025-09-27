<?php
/**
 * Archive Custom Sorting Component
 *
 * Provides both frontend UI and backend query modification for archive post sorting.
 * Handles URL-based sorting via 'sort' GET parameter with 'oldest' or 'recent' values.
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Modify main query to support URL-based sorting on archive pages
 * Responds to 'sort' GET parameter with 'oldest' or 'recent' values
 *
 * @param WP_Query $query The WordPress query object
 * @return void
 * @since 1.0
 */
function extrachill_sort_posts($query) {
    if (!is_admin() && $query->is_main_query() && is_archive()) {
        $sort = isset($_GET['sort']) ? $_GET['sort'] : '';

        switch ($sort) {
            case 'oldest':
                $query->set('orderby', 'date');
                $query->set('order', 'ASC');
                break;
            case 'recent':
            default:
                break;
        }
    }
}
add_action('pre_get_posts', 'extrachill_sort_posts');

add_action('extrachill_archive_above_posts', 'extrachill_custom_sorting', 10);

function extrachill_custom_sorting() {
    if (!is_archive()) {
        return;
    }

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

    echo '<div id="extrachill-custom-sorting">';

    if (is_category('song-meanings')) {
        wp_innovator_dropdown_menu('song-meanings', 'Filter By Artist');
    } elseif (is_category('music-history')) {
        wp_innovator_dropdown_menu('music-history', 'Filter By Tag');
    }

    echo '<button id="randomize-posts">Randomize Posts</button>';

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