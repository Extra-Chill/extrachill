<?php
/**
 * Open Graph Meta Tags
 *
 * Outputs OG tags for social sharing on Facebook, LinkedIn, etc.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Output Open Graph meta tags
 */
add_action('wp_head', function () {
    $og_data = ec_seo_get_open_graph_data();

    echo "\n<!-- Open Graph -->\n";

    foreach ($og_data as $property => $content) {
        if (!empty($content)) {
            printf(
                '<meta property="%s" content="%s" />' . "\n",
                esc_attr($property),
                esc_attr($content)
            );
        }
    }
}, 5);

/**
 * Get Open Graph data for current page
 *
 * @return array OG properties and values
 */
function ec_seo_get_open_graph_data() {
    $data = [
        'og:locale'    => 'en_US',
        'og:site_name' => 'Extra Chill',
        'og:type'      => 'website',
        'og:title'     => wp_get_document_title(),
        'og:url'       => ec_seo_get_canonical_url(),
    ];

    // Get description
    $description = ec_seo_get_meta_description();
    if (!empty($description)) {
        $data['og:description'] = $description;
    }

    // Singular content
    if (is_singular()) {
        $post = get_queried_object();

        // Article type for posts
        if ($post->post_type === 'post') {
            $data['og:type'] = 'article';
            $data['article:published_time'] = get_the_date('c', $post);
            $data['article:modified_time'] = get_the_modified_date('c', $post);

            // Article author
            $author = get_the_author_meta('display_name', $post->post_author);
            if ($author) {
                $data['article:author'] = $author;
            }
        }

        // Featured image
        if (has_post_thumbnail($post->ID)) {
            $image_id = get_post_thumbnail_id($post->ID);
            $image = wp_get_attachment_image_src($image_id, 'large');

            if ($image) {
                $data['og:image'] = $image[0];
                $data['og:image:width'] = $image[1];
                $data['og:image:height'] = $image[2];

                $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                if ($alt) {
                    $data['og:image:alt'] = $alt;
                }
            }
        }
    }

    // Default image fallback for pages without featured image
    if (empty($data['og:image'])) {
        $data['og:image'] = ec_seo_get_default_image();
    }

    return $data;
}

/**
 * Get canonical URL for current page
 *
 * @return string Canonical URL
 */
function ec_seo_get_canonical_url() {
    if (is_singular()) {
        return get_permalink();
    }

    if (is_front_page()) {
        return home_url('/');
    }

    if (is_home()) {
        $page_for_posts = get_option('page_for_posts');
        if ($page_for_posts) {
            $url = get_permalink($page_for_posts);
        } else {
            $url = home_url('/');
        }

        // Add pagination
        $paged = get_query_var('paged');
        if ($paged > 1) {
            $url = trailingslashit($url) . 'page/' . $paged . '/';
        }

        return $url;
    }

    if (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        $url = get_term_link($term);

        // Add pagination
        $paged = get_query_var('paged');
        if ($paged > 1) {
            $url = trailingslashit($url) . 'page/' . $paged . '/';
        }

        return $url;
    }

    if (is_author()) {
        $author = get_queried_object();
        $url = get_author_posts_url($author->ID);

        // Add pagination
        $paged = get_query_var('paged');
        if ($paged > 1) {
            $url = trailingslashit($url) . 'page/' . $paged . '/';
        }

        return $url;
    }

    if (is_search()) {
        return home_url('?s=' . rawurlencode(get_search_query()));
    }

    // Fallback to current URL
    global $wp;
    return home_url($wp->request);
}

/**
 * Get default OG image
 *
 * @return string Default image URL
 */
function ec_seo_get_default_image() {
    return 'https://extrachill.com/wp-content/uploads/2024/07/cropped-bigger-logo-black-1-400x400.jpeg';
}
