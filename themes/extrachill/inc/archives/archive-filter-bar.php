<?php
/**
 * Archive Filter Bar Component
 *
 * Unified filter bar for archive pages containing all filtering and sorting controls.
 * Includes child terms dropdown, artist filtering, randomize button, and sort dropdown.
 *
 * @package ExtraChill
 * @since 1.0
 */

add_action('extrachill_archive_above_posts', 'extrachill_archive_filter_bar', 10);

/**
 * Display unified archive filter bar
 * Combines all filtering/sorting controls in one consistent interface
 *
 * @since 1.0
 */
function extrachill_archive_filter_bar() {
    if (!is_archive()) {
        return;
    }

    // Get archive link for current context
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

    // Randomize button - always first
    echo '<button id="randomize-posts">Randomize Posts</button>';

    // Child terms dropdown (subcategories/sub-locations)
    if (function_exists('extrachill_child_terms_dropdown_html')) {
        echo extrachill_child_terms_dropdown_html();
    }

    // Artist filter dropdown for specific categories
    if (is_category('song-meanings')) {
        extrachill_artist_filter_dropdown('Filter By Artist');
    } elseif (is_category('music-history')) {
        extrachill_artist_filter_dropdown('Filter By Artist');
    }

    // Sort dropdown
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
