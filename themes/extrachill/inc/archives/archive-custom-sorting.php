<?php
/**
 * Archive Custom Sorting Component
 *
 * Displays custom sorting dropdown and randomize button for archives
 *
 * @package ExtraChill
 * @since 1.0
 */

// Hook into archive above posts
add_action('extrachill_archive_above_posts', 'extrachill_custom_sorting', 10);

/**
 * Display custom sorting controls for archives
 */
function extrachill_custom_sorting() {
    // Only show on archive pages
    if (!is_archive()) {
        return;
    }

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

    echo '<div id="extrachill-custom-sorting">';

    // Category-specific artist dropdowns
    if (is_category('song-meanings')) {
        wp_innovator_dropdown_menu('song-meanings', 'Filter By Artist');
    } elseif (is_category('music-history')) {
        wp_innovator_dropdown_menu('music-history', 'Filter By Tag');
    }

    // Randomize button
    echo '<button id="randomize-posts">Randomize Posts</button>';

    // Sorting dropdown
    echo '<div id="custom-sorting-dropdown">';
    echo '<select id="post-sorting" name="post_sorting" onchange="window.location.href=\'' . esc_url($archive_link) . '?sort=\'+this.value;">';
    echo '<option value="recent">Sort by Recent</option>';
    echo '<option value="oldest">Sort by Oldest</option>';
    echo '</select>';
    echo '</div>';
    echo '</div>';

    // JavaScript for sorting functionality
    ?>
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
    <?php
}