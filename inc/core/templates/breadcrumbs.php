<?php
/**
 * Unified Breadcrumb System for ExtraChill Theme
 *
 * Centralized breadcrumb functionality for all page types including posts, pages,
 * archives, taxonomies, and custom post types. Single function handles all contexts.
 *
 * @package ExtraChill
 * @since 69.57
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display unified breadcrumbs for all page contexts
 *
 * Handles posts, pages, archives, taxonomies, and custom post types
 * with consistent formatting and logic throughout the theme.
 *
 * @return void
 * @since 69.57
 */
if (!function_exists('extrachill_breadcrumbs')) {
    function extrachill_breadcrumbs() {
        if (WP_DEBUG) {
            error_log('extrachill_breadcrumbs() called on: ' . $_SERVER['REQUEST_URI']);
        }

        // Allow override by plugins or custom functions
        if (function_exists('override_display_breadcrumbs') && override_display_breadcrumbs()) {
            return;
        }

        // Skip breadcrumbs for shop pages handled by ExtraChill Shop plugin
        if (function_exists('is_woocommerce') && (is_woocommerce() || is_cart() || is_checkout() || is_account_page() || is_product() || is_shop())) {
            return;
        }

        // Skip breadcrumbs on front page
        if (is_front_page()) {
            return;
        }

        echo '<nav class="breadcrumbs" itemprop="breadcrumb">';
        echo '<a href="' . home_url() . '">Home</a> › ';

        if (is_single()) {
            // Handle single posts with category and tag breadcrumbs
            global $post;

            $categories = get_the_category($post->ID);
            $filtered_categories = array_filter($categories, function($cat) {
                return $cat->term_id != 714; // Filter out specific category
            });

            $category = !empty($filtered_categories) ? reset($filtered_categories) : (count($categories) === 1 ? reset($categories) : null);

            $tags = get_the_tags($post->ID);
            $top_tag = null;

            if ($tags && is_array($tags)) {
                usort($tags, function($a, $b) {
                    return $b->count - $a->count;
                });
                $top_tag = reset($tags);
            }

            if ($category) {
                echo '<a href="' . get_category_link($category->term_id) . '">' . $category->name . '</a>';
            }

            if ($top_tag) {
                echo ' › <a href="' . get_tag_link($top_tag->term_id) . '">' . $top_tag->name . '</a>';
            }

            echo '<span class="breadcrumb-title"> › ' . get_the_title($post->ID) . '</span>';
        }
        elseif (is_singular() && !is_singular(array('post', 'page', 'product'))) {
            // Handle custom post types
            $post_type = get_post_type();
            $post_type_obj = get_post_type_object($post_type);
            $archive_link = get_post_type_archive_link($post_type);

            if ($archive_link && $post_type_obj) {
                echo '<a href="' . $archive_link . '">' . $post_type_obj->labels->name . '</a> › ';
            }

            echo '<span>' . get_the_title() . '</span>';
        }
        elseif (is_page()) {
            // Handle pages with parent hierarchy
            $parent_id = wp_get_post_parent_id(get_the_ID());
            if ($parent_id) {
                $parent_title = get_the_title($parent_id);
                $parent_url = get_permalink($parent_id);
                echo '<a href="' . $parent_url . '">' . $parent_title . '</a> › ';
            }
            echo '<span>' . get_the_title() . '</span>';
        }
        elseif (is_post_type_archive()) {
            // Handle post type archives
            $post_type = get_post_type();
            $post_type_obj = get_post_type_object($post_type);

            if ($post_type_obj) {
                echo '<span>' . $post_type_obj->labels->name . '</span>';
            }
        }
        elseif (is_category()) {
            // Handle category archives with parent hierarchy
            $category = get_queried_object();
            if ($category->parent) {
                $parent_category = get_category($category->parent);
                echo '<a href="' . get_category_link($parent_category->term_id) . '">' . $parent_category->name . '</a> › ';
            }
            echo '<span>' . single_cat_title('', false) . '</span>';
        }
        elseif (is_tag()) {
            // Handle tag archives
            echo '<a href="' . home_url('/all-tags') . '">Tags</a> › ';
            echo '<span>' . single_tag_title('', false) . '</span>';
        }
        else {
            // Fallback for other archive types
            echo '<span>Archives</span>';
        }

        echo '</nav>';
    }
}