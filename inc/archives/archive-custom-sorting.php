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
 * Uses modern artist taxonomy to create dropdown filter with descriptive default option
 *
 * @since 1.0
 */
function extrachill_artist_filter_dropdown() {
    $current_artist = get_query_var('artist');
    $category_id = get_queried_object_id();
    $archive_link = get_category_link($category_id);

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

    echo '<div id="artist-filters">';
    echo '<select id="artist-filter-dropdown" onchange="window.location.href=this.value;">';

    $selected = empty($current_artist) ? ' selected' : '';
    echo '<option value="' . esc_url($archive_link) . '"' . $selected . '>All Artists</option>';

    foreach ($artists as $artist) {
        $artist_url = add_query_arg('artist', $artist->slug, $archive_link);
        $selected = ($artist->slug == $current_artist) ? ' selected' : '';
        echo '<option value="' . esc_url($artist_url) . '"' . $selected . '>' . esc_html($artist->name) . '</option>';
    }

    echo '</select></div>';
}

/**
 * Modify main query to support URL-based sorting and artist filtering on archive pages
 * Responds to 'sort' GET parameter with 'oldest', 'recent', 'random', or 'popular' values
 * Responds to 'artist' GET parameter for artist taxonomy filtering
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
            case 'random':
                $query->set('orderby', 'rand');
                break;
            case 'popular':
                $query->set('meta_key', 'ec_post_views');
                $query->set('orderby', 'meta_value_num');
                $query->set('order', 'DESC');
                break;
            case 'recent':
            default:
                break;
        }

        $artist = get_query_var('artist');
        if (!empty($artist)) {
            $query->set('artist', $artist);
        }
    }
}
add_action('pre_get_posts', 'extrachill_sort_posts');