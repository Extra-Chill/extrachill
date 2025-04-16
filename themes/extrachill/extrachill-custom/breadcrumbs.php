<?php
function display_breadcrumbs() {
    // Allow for overriding the breadcrumbs for custom post types
    if (function_exists('override_display_breadcrumbs') && override_display_breadcrumbs()) {
        return;
    }
    
    if (is_tax('location')) {
        display_location_breadcrumbs(); // Call the custom location breadcrumb function
        return;
    }

    if (is_woocommerce() && function_exists('woocommerce_breadcrumb')) {
        woocommerce_breadcrumb();
        return;
    }

    if (!is_front_page()) {
        echo '<nav class="breadcrumbs" itemprop="breadcrumb">';
        echo '<a href="' . home_url() . '">Home</a> › ';

        // Add Merch Store link for WooCommerce cart and checkout pages
        if (is_cart() || is_checkout()) {
            echo '<a href="' . home_url('/shop') . '">Merch Store</a> › ';
        }

        // Check for custom post types - added support for festival_wire
        if (is_singular() && !is_singular(array('post', 'page', 'product'))) {
            $post_type = get_post_type();
            $post_type_obj = get_post_type_object($post_type);
            $archive_link = get_post_type_archive_link($post_type);
            
            if ($archive_link && $post_type_obj) {
                echo '<a href="' . $archive_link . '">' . $post_type_obj->labels->name . '</a> › ';
            }
            
            echo '<span>' . get_the_title() . '</span>';
        }
        elseif (is_post_type_archive()) {
            $post_type = get_post_type();
            $post_type_obj = get_post_type_object($post_type);
            
            if ($post_type_obj) {
                echo '<span>' . $post_type_obj->labels->name . '</span>';
            }
        }
        elseif (is_page()) {
            // Get parent pages if they exist
            $parent_id = wp_get_post_parent_id(get_the_ID());
            if ($parent_id) {
                $parent_title = get_the_title($parent_id);
                $parent_url = get_permalink($parent_id);
                echo '<a href="' . $parent_url . '">' . $parent_title . '</a> › ';
            }
            echo '<span>' . get_the_title() . '</span>';
        } elseif (is_category()) {
            $category = get_queried_object();
            if ($category->parent) {
                $parent_category = get_category($category->parent);
                echo '<a href="' . get_category_link($parent_category->term_id) . '">' . $parent_category->name . '</a> › ';
            }
            echo '<span>' . single_cat_title('', false) . '</span>';
        } elseif (is_tag()) {
            echo '<a href="' . home_url('/all-tags') . '">Tags</a> › ';
            echo '<span>' . single_tag_title('', false) . '</span>';
        } elseif (is_single() && !is_product()) {
            display_post_breadcrumbs(); // Use the existing function for posts
        } else {
            echo 'Archives';
        }
        echo '</nav>';
    }
}




// Function to display breadcrumbs for post pages
function display_post_breadcrumbs() {
    if (is_single() && !is_front_page()) {
        global $post;

        // Get categories, exclude category 714 unless it's the only one
        $categories = get_the_category($post->ID);
        $filtered_categories = array_filter($categories, function($cat) {
            return $cat->term_id != 714;
        });

        // Use the first category if exists, otherwise, use category 714 if it's the only one
        $category = !empty($filtered_categories) ? reset($filtered_categories) : (count($categories) === 1 ? reset($categories) : null);

        // Get tags and ensure it's an array
        $tags = get_the_tags($post->ID);
        $top_tag = null;

        if ($tags && is_array($tags)) {
            // Sort tags by post count in descending order
            usort($tags, function($a, $b) {
                return $b->count - $a->count;
            });

            // Get the tag with the most posts
            $top_tag = reset($tags);
        }

        echo '<nav class="breadcrumbs" itemprop="breadcrumb">';
        echo '<a href="' . home_url() . '">Home</a> › ';

        if ($category) {
            echo '<a href="' . get_category_link($category->term_id) . '">' . $category->name . '</a>';
        }

        if ($top_tag) {
            echo ' › <a href="' . get_tag_link($top_tag->term_id) . '">' . $top_tag->name . '</a>';
        }

        echo '<span class="breadcrumb-title"> › ' . get_the_title($post->ID) . '</span>';
        echo '</nav>';
    }
}

// Function to display breadcrumbs for location taxonomy pages
function display_location_breadcrumbs() {
    if (is_tax('location')) {
        $term = get_queried_object();

        echo '<nav class="breadcrumbs" itemprop="breadcrumb">';
        echo '<a href="' . home_url() . '">Home</a> › ';
        echo '<a href="' . home_url('/locations') . '">Locations</a> › ';

        // Display parent locations in the breadcrumb
        $parents = get_ancestors($term->term_id, 'location');
        if (!empty($parents)) {
            $parents = array_reverse($parents);
            foreach ($parents as $parent_id) {
                $parent_term = get_term($parent_id, 'location');
                echo '<a href="' . get_term_link($parent_term) . '">' . esc_html($parent_term->name) . '</a> › ';
            }
        }

        // Display the current term name
        echo '<span>' . esc_html($term->name) . '</span>';
        echo '</nav>';
    }
}

add_filter('woocommerce_get_breadcrumb', 'add_merch_store_to_breadcrumb', 10, 2);
function add_merch_store_to_breadcrumb($crumbs, $breadcrumb) {
    // Construct the Merch Store breadcrumb
    $shop_crumb = ['Merch Store', home_url('/shop')];

    // Insert "Merch Store" directly after "Home" for relevant WooCommerce pages
    if (is_product_category() || is_product_tag() || is_product() || is_cart() || is_checkout()) {
        array_splice($crumbs, 1, 0, [$shop_crumb]);
    }

    return $crumbs;
}




add_filter( 'woocommerce_breadcrumb_defaults', 'wps_breadcrumb_delimiter' );
function wps_breadcrumb_delimiter( $defaults ) {
  $defaults['delimiter'] = ' › ';
  return $defaults;
}
