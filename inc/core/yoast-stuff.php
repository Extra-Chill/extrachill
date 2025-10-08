<?php

/**
 * Yoast SEO Sitemap Integration
 *
 * Prevents duplicate featured images from appearing in both post content
 * and dedicated image sitemap entries.
 *
 * @package ExtraChill
 * @since 69.58
 */

/**
 * Remove duplicate images from Yoast sitemap
 *
 * @param array  $images  Images to include in sitemap
 * @param string $post_id Post ID
 * @return array Modified images array
 */
function filter_yoast_sitemap_images($images, $post_id) {
    $featured_image_url = get_the_post_thumbnail_url($post_id);

    if (!$featured_image_url) {
        return $images;
    }

    $post_content = get_post_field('post_content', $post_id);

    if (strpos($post_content, $featured_image_url) !== false) {
        foreach ($images as $key => $image) {
            if ($image['src'] === $featured_image_url) {
                unset($images[$key]);
                break;
            }
        }
    }

    return $images;
}
add_filter('wpseo_sitemap_urlimages', 'filter_yoast_sitemap_images', 10, 2);
