<?php
/**
 * Archive Custom Sorting Component
 *
 * Provides both frontend UI and backend query modification for archive post sorting.
 * Handles URL-based sorting via 'sort' GET parameter with 'oldest' or 'recent' values.
 * Includes modern artist taxonomy filtering for specific categories.
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Generate artist dropdown filter for current category
 * Uses modern artist taxonomy to create dropdown filter
 *
 * @param string $filter_heading Display heading for filter section
 * @since 1.0
 */
function extrachill_artist_filter_dropdown($filter_heading) {
    $current_artist = get_query_var('artist');
    $category_id = get_queried_object_id();
    $archive_link = get_category_link($category_id);

    // Get artists that have posts in this category
    $artists = get_terms(array(
        'taxonomy' => 'artist',
        'orderby' => 'name',
        'order' => 'ASC',
        'hide_empty' => true,
        'object_ids' => get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'category' => $category_id,
            'numberposts' => -1,
            'fields' => 'ids'
        ))
    ));

    if (empty($artists) || is_wp_error($artists)) {
        return;
    }

    echo '<div id="artist-filters"><h2 class="filter-head">' . esc_html($filter_heading) . '</h2>';
    echo '<select id="artist-filter-dropdown" onchange="window.location.href=this.value;">';

    $selected = empty($current_artist) ? ' selected' : '';
    echo '<option value="' . esc_url($archive_link) . '"' . $selected . '>View All</option>';

    foreach ($artists as $artist) {
        $artist_url = add_query_arg('artist', $artist->slug, $archive_link);
        $selected = ($artist->slug == $current_artist) ? ' selected' : '';
        echo '<option value="' . esc_url($artist_url) . '"' . $selected . '>' . esc_html($artist->name) . '</option>';
    }

    echo '</select></div>';
}

/**
 * Modify main query to support URL-based sorting and artist filtering on archive pages
 * Responds to 'sort' GET parameter with 'oldest' or 'recent' values
 * Responds to 'artist' GET parameter for artist taxonomy filtering
 *
 * @param WP_Query $query The WordPress query object
 * @return void
 * @since 1.0
 */
function extrachill_sort_posts($query) {
    if (!is_admin() && $query->is_main_query() && is_archive()) {
        // Handle sorting
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

        // Handle artist filtering
        $artist = get_query_var('artist');
        if (!empty($artist)) {
            $query->set('artist', $artist);
        }
    }
}
add_action('pre_get_posts', 'extrachill_sort_posts');

add_action('extrachill_archive_above_posts', 'extrachill_custom_sorting', 10);

/**
 * Display custom sorting interface for archive pages
 * Provides dropdown sorting options, randomize button, and category-specific filtering
 * Hooks into 'extrachill_archive_above_posts' action
 *
 * @since 1.0
 */
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
        extrachill_artist_filter_dropdown('Filter By Artist');
    } elseif (is_category('music-history')) {
        extrachill_artist_filter_dropdown('Filter By Artist');
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