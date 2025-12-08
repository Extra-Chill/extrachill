<?php
/**
 * Unified Breadcrumb System
 *
 * Technical Implementation:
 * - bbPress integration: Plugin-based breadcrumbs via extrachill_breadcrumbs_override_trail filter
 * - WooCommerce integration: Shop plugin provides breadcrumbs via extrachill_breadcrumbs_override_trail filter
 * - Hierarchical taxonomies: Full ancestor chain display for parent/child relationships
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('extrachill_breadcrumbs')) {
    function extrachill_breadcrumbs() {
        echo '<nav class="breadcrumbs" itemprop="breadcrumb">';

        // Allow plugins to override the root breadcrumb link
        $root_link = apply_filters( 'extrachill_breadcrumbs_root', '<a href="' . home_url() . '">Extra Chill</a>' );
        echo $root_link . ' › ';

        // Allow plugins to override the default breadcrumb trail
        $custom_trail = apply_filters('extrachill_breadcrumbs_override_trail', '');
        if (!empty($custom_trail)) {
            // Only wrap spans with explicit network-dropdown-target class
            if ( preg_match( '/^<span class="network-dropdown-target">(.+)<\/span>$/', $custom_trail, $matches ) ) {
                echo extrachill_network_dropdown( $matches[1] );
            } else {
                echo $custom_trail;
            }
        } else {
            // Original breadcrumb logic
            if (is_single() && is_singular('post')) {
            global $post;

            $categories = get_the_category($post->ID);
            $filtered_categories = array_filter($categories, function($cat) {
                return $cat->term_id != 714;
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
        }
        elseif (is_singular() && !is_singular(array('post', 'page', 'product'))) {
            $post_type = get_post_type();
            $post_type_obj = get_post_type_object($post_type);
            $archive_link = get_post_type_archive_link($post_type);

            if ($archive_link && $post_type_obj) {
                echo '<a href="' . $archive_link . '">' . $post_type_obj->labels->name . '</a>';
            }
        }
        elseif (is_page()) {
            $parent_id = wp_get_post_parent_id(get_the_ID());
            if ($parent_id) {
                $parent_title = get_the_title($parent_id);
                $parent_url = get_permalink($parent_id);
                echo '<a href="' . $parent_url . '">' . $parent_title . '</a> › ';
            }
            echo '<span class="breadcrumb-title">' . get_the_title() . '</span>';
        }

        if (is_page()) {
            // Pages fully handled above, skip this chain
        } elseif (is_singular() && !is_front_page()) {
            echo '<span class="breadcrumb-title"> › ' . get_the_title() . '</span>';
        } elseif (is_post_type_archive()) {
            $post_type = get_post_type();
            $post_type_obj = get_post_type_object($post_type);

            if ($post_type_obj) {
                echo '<span>' . $post_type_obj->labels->name . '</span>';
            }
        }
        elseif (is_category()) {
            $category = get_queried_object();
            if ($category->parent) {
                $parent_category = get_category($category->parent);
                echo '<a href="' . get_category_link($parent_category->term_id) . '">' . $parent_category->name . '</a> › ';
            }
            echo '<span>' . single_cat_title('', false) . '</span>';
        }
        elseif (is_tag()) {
            echo '<span>' . single_tag_title('', false) . '</span>';
        }
        elseif (is_tax()) {
            $term = get_queried_object();
            $taxonomy = get_taxonomy($term->taxonomy);

            if ($taxonomy) {
                if (!$taxonomy->hierarchical) {
                    echo '<span>' . esc_html($taxonomy->labels->name) . '</span> › ';
                }

                if ($taxonomy->hierarchical && $term->parent) {
                    $parents = get_ancestors($term->term_id, $term->taxonomy);
                    if (!empty($parents)) {
                        $parents = array_reverse($parents);
                        foreach ($parents as $parent_id) {
                            $parent_term = get_term($parent_id, $term->taxonomy);
                            echo '<a href="' . get_term_link($parent_term) . '">' . esc_html($parent_term->name) . '</a> › ';
                        }
                    }
                }

                echo '<span>' . esc_html($term->name) . '</span>';
            }
        }
        elseif (is_search()) {
            echo '<span>Search Results</span>';
        }
        elseif (is_author()) {
            $author = get_queried_object();
            echo '<span>Author</span> › ';
            echo '<span>' . esc_html($author->display_name) . '</span>';
        }
        else {
            echo '<span>Archives</span>';
        }
        } // Close the custom_trail else block

        // Allow plugins to append custom breadcrumb items
        do_action('extrachill_breadcrumbs_append');

        echo '</nav>';
    }
}