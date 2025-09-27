<?php
/**
 * Location Filter Backend Functions
 *
 * DORMANT FILE - Not currently included in theme functionality.
 * Contains AJAX handlers and location taxonomy display functions.
 * Frontend JavaScript (location-filter.js) has been removed from theme.
 * Server-side functionality remains available for potential future integration.
 *
 * @package ExtraChill
 * @since 69.57
 * @status dormant
 */

/**
 * AJAX handler for filtering posts by location
 * Note: Frontend JavaScript interface has been removed
 */
function ajax_filter_posts_by_location() {
    check_ajax_referer( 'location_filter_nonce', 'nonce' );

    $locations = isset( $_POST['locations'] ) ? json_decode( stripslashes( $_POST['locations'] ), true ) : array();
    $paged = isset( $_POST['paged'] ) ? intval( $_POST['paged'] ) : 1; // Get the current page number

    $args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => get_option( 'posts_per_page' ),
        'paged'          => $paged,
    );

    if ( ! empty( $locations ) ) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'location',
                'field'    => 'slug',
                'terms'    => $locations,
            ),
        );
    }

    $query = new WP_Query( $args );

    // Get the filtered posts
    ob_start();
    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            get_template_part( 'content', get_post_format() );
        }
    } else {
        echo '<p>No posts found for the selected locations.</p>';
    }
    $posts_html = ob_get_clean();

    // Generate clean pagination links
    ob_start();
    $big = 999999999; // Need an unlikely integer
    echo paginate_links(array(
        'base'    => str_replace($big, '%#%', esc_url(home_url('/page/%#%/'))), // Force base to use /page/
        'format'  => 'page/%#%/',
        'current' => max(1, $paged),
        'total'   => $query->max_num_pages,
    ));
    $pagination_html = ob_get_clean();

    wp_reset_postdata();

    // Send back the filtered posts, location names, and pagination
    wp_send_json_success( array(
        'posts'         => $posts_html,
        'pagination'    => $pagination_html,
        'location_names' => array_map(function($slug) {
            $term = get_term_by('slug', $slug, 'location');
            return $term ? $term->name : $slug;
        }, $locations),
            ));

    wp_die();
}

add_action( 'wp_ajax_filter_posts_by_location', 'ajax_filter_posts_by_location' );
add_action( 'wp_ajax_nopriv_filter_posts_by_location', 'ajax_filter_posts_by_location' );

/**
 * Function for displaying the location hierarchy on the homepage filter popup
 * (Only show locations with 5 or more posts)
 */
function display_location_hierarchy_homepage($parent_id = 0) {
    $locations = get_terms(array(
        'taxonomy'   => 'location',
        'hide_empty' => false,
        'parent'     => $parent_id,
    ));

    if (!empty($locations) && !is_wp_error($locations)) {
        echo '<ul class="location-list">';
        foreach ($locations as $location) {
            // Get the total post count for this location, including child categories
            $post_count = get_location_post_count($location->term_id);

            // Only display locations with 5 or more posts
            if ($post_count >= 5) {
                $location_name = esc_html($location->name);
                $location_slug = esc_attr($location->slug);

                echo '<li class="location-item">';
                echo '<label class="location-checkbox">';
                echo '<input type="checkbox" value="' . $location_slug . '"> ' . $location_name . ' (' . $post_count . ')';
                echo '</label>';

                // Recursively display child locations
                display_location_hierarchy_homepage($location->term_id);

                echo '</li>';
            }
        }
        echo '</ul>';
    }
}

/**
 * Function for displaying the location hierarchy on the archive page
 * (Show all locations with 1 or more posts)
 */
// Function for displaying the location hierarchy with scaled font sizes for the archive page
function display_location_hierarchy_archive($parent_id = 0, $min = 0, $max = 1) {
    $locations = get_terms(array(
        'taxonomy' => 'location',
        'hide_empty' => false,
        'parent' => $parent_id,
        'orderby' => 'name',
        'order' => 'ASC',
    ));

    if (!empty($locations) && !is_wp_error($locations)) {
        echo '<ul class="location-list">';
        foreach ($locations as $location) {
            $total_count = get_location_post_count($location->term_id);

            // Only display locations with 1 or more posts
            if ($total_count >= 1) {
                $font_size = calculate_logarithmic_font_size($total_count, $min, $max);

                echo '<li class="location-item">';
                echo '<a href="' . esc_url(get_term_link($location)) . '" style="font-size:' . esc_attr($font_size) . 'px;">';
                echo esc_html($location->name) . ' (' . $total_count . ')';
                echo '</a>';

                // Recursively display child locations with scaling
                display_location_hierarchy_archive($location->term_id, $min, $max);

                echo '</li>';
            }
        }
        echo '</ul>';
    }
}

/**
 * Get the total post count for a location, including child categories.
 */
function get_location_post_count($term_id) {
    $term_children = get_term_children($term_id, 'location');

    // Include the main location term ID and all child IDs
    $all_term_ids = array_merge(array($term_id), $term_children);

    // Get the total post count for the location and its children
    $posts_in_location = new WP_Query(array(
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'tax_query'      => array(
            array(
                'taxonomy' => 'location',
                'field'    => 'term_id',
                'terms'    => $all_term_ids,
            ),
        ),
        'fields' => 'ids',
    ));

    return $posts_in_location->found_posts;
}

function calculate_logarithmic_font_size($total_count, $min, $max, $min_font_size = 14, $max_font_size = 36) {
    if ($max > $min) {
        $relative_size = ($total_count - $min) / ($max - $min);
    } else {
        $relative_size = 0.5; // Default size if min and max are the same
    }

    $exponent = 3; // Increase for more dramatic differences
    $relative_size = pow(log($total_count - $min + 1) / log($max - $min + 1), $exponent);

    return $min_font_size + $relative_size * ($max_font_size - $min_font_size);
}

// Find the minimum and maximum total post counts for locations
function find_min_max_post_counts($parent_id = 0) {
    $locations = get_terms(array(
        'taxonomy' => 'location',
        'hide_empty' => false,
        'parent' => $parent_id,
    ));

    $min = PHP_INT_MAX;
    $max = PHP_INT_MIN;

    foreach ($locations as $location) {
        $total_count = get_location_post_count($location->term_id);

        if ($total_count < $min) {
            $min = $total_count;
        }

        if ($total_count > $max) {
            $max = $total_count;
        }

        // Recursively check child locations
        $child_min_max = find_min_max_post_counts($location->term_id);
        $min = min($min, $child_min_max['min']);
        $max = max($max, $child_min_max['max']);
    }

    return ['min' => $min, 'max' => $max];
}
